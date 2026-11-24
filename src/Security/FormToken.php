<?php

declare(strict_types=1);

namespace SvnManage\Security;

/**
 * Stateless CSRF token for the anonymous login form ("signed double submit").
 *
 * The page sets a random nonce in a SameSite=Strict cookie and embeds
 * nonce.time.HMAC(key, nonce|time) in the form. A POST is accepted only when the
 * signature is valid, fresh, and its nonce equals the cookie, which another site can
 * neither read nor set. So no session (and no session file) is needed until someone
 * actually submits a login.
 */
final class FormToken
{
    public const MAX_AGE = 7200;

    public function __construct(private readonly string $key)
    {
    }

    public static function newNonce(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function issue(string $nonce, ?int $now = null): string
    {
        $now ??= time();

        return $nonce . '.' . $now . '.' . $this->sign($nonce, $now);
    }

    public function verify(string $token, string $cookieNonce, ?int $now = null): bool
    {
        $now ??= time();
        $parts = explode('.', $token);
        if (count($parts) !== 3 || !preg_match('/^[0-9a-f]{32}$/D', $cookieNonce) || !ctype_digit($parts[1])) {
            return false;
        }
        [$nonce, $issued, $signature] = $parts;
        $age = $now - (int) $issued;

        return hash_equals($cookieNonce, $nonce)
            && $age >= 0 && $age <= self::MAX_AGE
            && hash_equals($this->sign($nonce, (int) $issued), $signature);
    }

    private function sign(string $nonce, int $issued): string
    {
        return hash_hmac('sha256', 'login-form|' . $nonce . '|' . $issued, $this->key);
    }
}
