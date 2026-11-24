<?php

declare(strict_types=1);

namespace SvnManage\Controllers;

use SvnManage\Acl\Authorizer;
use SvnManage\Config\Config;
use SvnManage\Http\Context;
use SvnManage\Http\HttpException;
use SvnManage\Http\Request;
use SvnManage\Http\Response;
use SvnManage\Services;

/** Base controller: authorization guards, rendering and flash helpers. */
abstract class Controller
{
    protected readonly Request $request;
    protected readonly Services $services;
    protected readonly Authorizer $auth;

    public function __construct(protected readonly Context $ctx)
    {
        $this->request = $ctx->request;
        $this->services = $ctx->services;
        $this->auth = $ctx->auth;
    }

    /** Requires a logged-in user with $module/$action. */
    protected function authorize(string $module, string $action): void
    {
        if ($this->auth->authEnabled && !$this->auth->isLoggedIn()) {
            throw new HttpException(401, 'Login required.', 'login');
        }
        if (!$this->auth->can($module, $action)) {
            throw HttpException::forbidden();
        }
    }

    protected function requireLogin(): void
    {
        if ($this->auth->authEnabled && !$this->auth->isLoggedIn()) {
            throw new HttpException(401, 'Login required.', 'login');
        }
    }

    /** Setup actions need the server-side setup key to have been entered in this session. */
    protected function requireSetupUnlocked(): void
    {
        if ($this->ctx->session->get('setup_unlocked') !== true) {
            throw HttpException::forbidden('Enter the setup key first.');
        }
    }

    protected function requireActive(bool $active): void
    {
        if (!$active) {
            throw HttpException::inactive();
        }
    }

    /** @param array<string, mixed> $vars */
    protected function page(string $template, array $vars, string $title, string $nav = ''): Response
    {
        return Response::html($this->ctx->view->page($template, $vars, $this->tr($title), $nav));
    }

    /** @param array<string, mixed> $vars */
    protected function fragment(string $template, array $vars = []): Response
    {
        return Response::html($this->ctx->view->render($template, $vars));
    }

    /** @param array<string, string|int|null> $query */
    protected function redirect(string $path, array $query = []): Response
    {
        return Response::redirect($this->ctx->view->url($path, $query), $this->request);
    }

    /** Redirects to the submitted "_back" target (same-origin path only) or $fallback. */
    /**
     * A same-origin absolute path. Rejects protocol-relative tricks browsers
     * normalise ("//", "/\\", and whitespace/control characters they strip).
     */
    public static function isLocalPath(string $path): bool
    {
        return $path !== ''
            && $path[0] === '/'
            && !preg_match('/[\x00-\x20\x7f\\\\]/', $path)
            && !str_starts_with($path, '//');
    }

    protected function back(string $fallback, array $query = []): Response
    {
        $back = $this->request->input('_back');
        if (self::isLocalPath($back)) {
            return Response::redirect($this->request->basePath . $back, $this->request);
        }

        return $this->redirect($fallback, $query);
    }

    /** @param list<string|int> $args */
    protected function tr(string $text, array $args = []): string
    {
        return $this->ctx->tr->t($text, $args);
    }

    /** @param list<string|int> $args */
    protected function success(string $text, array $args = []): void
    {
        $this->ctx->session->flash('success', $this->tr($text, $args));
    }

    /** @param list<string|int> $args */
    protected function error(string $text, array $args = []): void
    {
        $this->ctx->session->flash('error', $this->tr($text, $args));
    }

    /** @param list<string|int> $args */
    protected function info(string $text, array $args = []): void
    {
        $this->ctx->session->flash('info', $this->tr($text, $args));
    }

    /**
     * Text for an error shown on a page: the details (paths, svn output) only for
     * administrators; everyone else gets a generic line and the details are logged.
     */
    protected function errorText(\Throwable $e): string
    {
        if ($this->auth->isAdmin()) {
            return $e->getMessage();
        }
        error_log('[svnmanage] ' . $e::class . ': ' . $e->getMessage());

        return 'The operation failed. The details were written to the server log.';
    }

    protected function fail(\Throwable $e): void
    {
        $this->ctx->session->flash('error', $this->tr($e->getMessage()));
    }

    /** @param array<string, string|int|bool|null> $context */
    protected function audit(string $action, string $target, array $context = []): void
    {
        $this->services->audit()->record(
            $this->auth->username ?? ($this->auth->authEnabled ? 'anonymous' : '(auth off)'),
            $this->request->ip(),
            $action,
            $target,
            $context,
        );
    }

    /**
     * Runs $fn for every item, collecting per-item errors as flash messages.
     *
     * @template T
     * @param iterable<T> $items
     * @param callable(T): void $fn
     */
    protected function each(iterable $items, callable $fn): int
    {
        $ok = 0;
        foreach ($items as $item) {
            try {
                $fn($item);
                $ok++;
            } catch (\Throwable $e) {
                $this->fail($e);
            }
        }

        return $ok;
    }

    /** Case-insensitive search filter over strings produced by $key. */
    protected function filter(array $items, string $q, callable $key): array
    {
        $q = trim($q);
        if ($q === '') {
            return $items;
        }

        return array_values(array_filter($items, static fn ($i): bool => stripos((string) $key($i), $q) !== false));
    }

    /** @param callable(Config): Config $change */
    protected function writeConfig(callable $change): void
    {
        $appRoot = $this->services->appRoot;
        $dataDir = $this->services->dataDir;
        $this->services->files()->update($this->services->configPath(), static function (string $current) use ($change, $appRoot, $dataDir): string {
            return $change(Config::fromString($current, $appRoot, $dataDir))->render();
        });
    }
}
