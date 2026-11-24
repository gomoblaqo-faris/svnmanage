<?php

declare(strict_types=1);

namespace SvnManage\Http;

/** Wrapper around the native PHP session with hardened cookie settings. */
final class Session
{
    public const NAME = 'svnmanage';
    /** Secure-only variant: the browser rejects it unless set over HTTPS with Path=/ by this host. */
    public const SECURE_NAME = '__Host-svnmanage';
    /** Cookie holding the nonce of the stateless login-form token (see FormToken). */
    public const FORM_COOKIE = 'svnmanage-form';
    /** Anonymous pages that render a session-backed form (only before setup is done). */
    private const ANONYMOUS_FORM_PAGES = ['/setup'];

    private bool $persistent = false;

    /**
     * Anonymous requests without a session cookie get no session (and no file on disk).
     * The login page uses a stateless token instead; App starts a session for a login
     * POST only once that token has been verified (and the attempt is throttled).
     */
    public static function isNeeded(Request $request): bool
    {
        if ($request->cookie(self::NAME) !== null || $request->cookie(self::SECURE_NAME) !== null) {
            return true;
        }
        return $request->method === 'GET' && in_array($request->path, self::ANONYMOUS_FORM_PAGES, true);
    }

    /** False for an anonymous request that got only a throw-away, in-memory session. */
    public function isPersistent(): bool
    {
        return $this->persistent;
    }

    /** Name of the form cookie: "__Host-" prefixed over HTTPS at the site root. */
    public static function formCookieName(bool $secure, string $cookiePath): string
    {
        return ($secure && $cookiePath === '' ? '__Host-' : '') . self::FORM_COOKIE;
    }

    public function __construct(private readonly bool $native = true)
    {
    }

    /** Array-backed session for tests and the CLI (no cookies, no session files). */
    public static function inMemory(): self
    {
        return new self(false);
    }

    /**
     * @param bool $persist false = an in-memory session only (anonymous request without a
     *                      session cookie): nothing is written to disk and no cookie is set
     * @param int  $idleSeconds keeps PHP's garbage collector from ending sessions early
     */
    public function start(bool $secure, string $cookiePath, bool $persist = true, int $idleSeconds = 3600): void
    {
        $this->persistent = $persist;
        if (!$this->native || !$persist) {
            $_SESSION ??= [];

            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.gc_maxlifetime', (string) max(1440, $idleSeconds));
        session_name(self::cookieName($secure, $cookiePath));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $cookiePath === '' ? '/' : $cookiePath . '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** Starts an authenticated session: new id (no fixation), user and both session clocks. */
    public function login(string $user): void
    {
        $this->regenerate();
        $this->set('user', $user);
        $this->set('login_at', microtime(true));
        $this->set('last_seen', time());
        $this->set('_csrf', bin2hex(random_bytes(32))); // fresh token per authenticated session
    }

    /** Writes and unlocks the session early (before long streams). */
    public function close(): void
    {
        if ($this->native && session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    public function regenerate(): void
    {
        $this->persistent = true;
        if ($this->native && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function destroy(): void
    {
        $_SESSION = [];
        if ($this->native && session_status() === PHP_SESSION_ACTIVE) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => 1,
                'path' => $params['path'],
                'secure' => $params['secure'],
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_destroy();
        }
    }

    /** "__Host-" needs Secure and Path=/, so it is only used for HTTPS at the site root. */
    public static function cookieName(bool $secure, string $cookiePath): string
    {
        return $secure && $cookiePath === '' ? self::SECURE_NAME : self::NAME;
    }

    // ------------------------------------------------------------- flash

    public function flash(string $level, string $message): void
    {
        $messages = $_SESSION['_flash'] ?? [];
        $messages[] = [$level, $message];
        $_SESSION['_flash'] = $messages;
    }

    /** @return list<array{0: string, 1: string}> */
    public function takeFlash(): array
    {
        $messages = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);

        return is_array($messages) ? $messages : [];
    }

    // -------------------------------------------------------------- csrf

    public function csrfToken(): string
    {
        $token = $_SESSION['_csrf'] ?? null;
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $_SESSION['_csrf'] = $token;
        }

        return $token;
    }

    public function verifyCsrf(string $token): bool
    {
        $expected = $_SESSION['_csrf'] ?? null;

        return is_string($expected) && $token !== '' && hash_equals($expected, $token);
    }
}
