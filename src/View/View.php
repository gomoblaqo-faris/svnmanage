<?php

declare(strict_types=1);

namespace SvnManage\View;

use RuntimeException;
use SvnManage\Acl\Authorizer;
use SvnManage\Http\Request;
use SvnManage\Http\Session;
use SvnManage\Security\FormToken;
use SvnManage\I18n\Translator;
use SvnManage\Services;

/** Plain-PHP template renderer. Templates receive $v (this view) plus their vars. */
final class View
{
    private static ?self $current = null;

    /** @var list<array{0: string, 1: string}> */
    private array $flash = [];

    public function __construct(
        private readonly string $templateDir,
        public readonly Translator $tr,
        public readonly Request $request,
        public readonly Session $session,
        public readonly Authorizer $auth,
        public readonly Services $services,
    ) {
        self::$current = $this;
    }

    public static function current(): self
    {
        return self::$current ?? throw new RuntimeException('No active view.');
    }

    /** @param array<string, mixed> $vars */
    public function render(string $template, array $vars = []): string
    {
        $file = $this->templateDir . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new RuntimeException('Template not found: ' . $template);
        }
        $v = $this;
        extract($vars, EXTR_SKIP);
        ob_start();
        try {
            include $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }

    /** @param array<string, mixed> $vars */
    public function page(string $template, array $vars, string $title, string $nav = '', string $layout = 'layout'): string
    {
        $content = $this->render($template, $vars);
        $this->flash = $this->session->takeFlash();

        return $this->render($layout, ['content' => $content, 'title' => $title, 'nav' => $nav]);
    }

    /** @return list<array{0: string, 1: string}> */
    public function flashMessages(): array
    {
        return $this->flash;
    }

    public function url(string $path = '/', array $query = []): string
    {
        $url = $this->request->basePath . '/' . ltrim($path, '/');
        $query = array_filter($query, static fn ($v): bool => $v !== null && $v !== '');
        if ($url !== '/' && str_ends_with($url, '/') && $path !== '/') {
            $url = rtrim($url, '/');
        }

        return $query === [] ? $url : $url . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    public function asset(string $file): string
    {
        $path = dirname(__DIR__, 2) . '/public/assets/' . $file;
        $version = is_file($path) ? substr((string) filemtime($path), -6) : Services::VERSION;

        return $this->request->basePath . '/assets/' . $file . '?v=' . $version;
    }

    public function can(string $module, string $action): bool
    {
        return $this->auth->can($module, $action);
    }

    private ?string $formNonce = null;

    /**
     * The anonymous login page gets a stateless, signed token (no session needed);
     * every other form uses the session's token.
     */
    public function csrf(): string
    {
        if (!$this->session->isPersistent() && $this->request->path === '/login') {
            $cookie = (string) $this->request->cookie(Session::formCookieName($this->request->isSecure(), $this->request->basePath));
            $this->formNonce ??= preg_match('/^[0-9a-f]{32}$/D', $cookie) ? $cookie : FormToken::newNonce();

            return $this->services->formTokens()->issue($this->formNonce);
        }

        return $this->session->csrfToken();
    }

    /** Nonce to (re)send in the form cookie, when this page rendered a stateless token. */
    public function formNonce(): ?string
    {
        return $this->formNonce;
    }

    public function isActive(string $nav, string $section): bool
    {
        return $nav === $section;
    }
}
