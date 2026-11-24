<?php

declare(strict_types=1);

namespace SvnManage\Tests\Http;

use PHPUnit\Framework\TestCase;
use SvnManage\App;
use SvnManage\Http\Request;
use SvnManage\Http\Response;
use SvnManage\Http\Session;

/** Drives the real kernel in-process with an array-backed session. */
abstract class HttpTestCase extends TestCase
{
    protected string $dataDir;
    protected App $app;

    protected function setUp(): void
    {
        $this->dataDir = sys_get_temp_dir() . '/svnmanage-http-' . bin2hex(random_bytes(5));
        mkdir($this->dataDir, 0777, true);
        $_SESSION = [];
        $this->app = new App(dirname(__DIR__, 2), $this->dataDir, Session::inMemory());
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $this->removeTree($this->dataDir);
    }

    /** @param array<string, string> $query @param array<string, string> $headers */
    protected function get(string $path, array $query = [], array $headers = []): Response
    {
        return $this->app->handle(new Request('GET', $path, '', $query, [], [], $this->server($headers)));
    }

    /** @param array<string, mixed> $post @param array<string, string> $headers */
    protected function post(string $path, array $post = [], array $headers = [], bool $withToken = true): Response
    {
        if ($withToken) {
            $post['_token'] ??= $this->token();
        }

        return $this->app->handle(new Request('POST', $path, '', [], $post, [], $this->server($headers)));
    }

    protected function token(): string
    {
        $token = $_SESSION['_csrf'] ?? null;
        if (!is_string($token)) {
            $token = bin2hex(random_bytes(32));
            $_SESSION['_csrf'] = $token;
        }

        return $token;
    }

    /** Runs the setup wizard: local defaults, security, an "admin" account, logged in. */
    protected function install(string $admin = 'admin', string $password = 'Secret123'): void
    {
        $this->get('/setup');
        $this->post('/setup/unlock', ['key' => $this->file('setup.key')]);
        self::assertSame(303, $this->post('/setup', ['action' => 'defaults'])->status);
        // Security step. Fixtures use short passwords (new installs default to 12, see
        // PublicExposureTest) and enrol two-factor only where a test asks for it.
        self::assertSame(303, $this->post('/setup', ['action' => 'security', 'Common|TwoFactor' => 'optional', 'Common|MinPasswordLength' => '8'])->status);
        $response = $this->post('/setup/admin', ['mode' => 'create', 'username' => $admin, 'password' => $password, 'password2' => $password]);
        self::assertSame('/', $response->headers['Location'] ?? null, 'setup should log the new admin in');
        $this->flashes();
    }

    protected function logout(): void
    {
        $this->post('/logout');
        $_SESSION = [];
    }

    protected function login(string $user, string $password): Response
    {
        return $this->post('/login', ['username' => $user, 'password' => $password]);
    }

    /** @return list<array{0: string, 1: string}> drains flash messages */
    protected function flashes(): array
    {
        $messages = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);

        return array_values(array_map(static fn ($m): array => is_array($m) ? [(string) ($m[0] ?? $m['level'] ?? ''), (string) ($m[1] ?? $m['message'] ?? '')] : ['', (string) $m], $messages));
    }

    protected function flashText(): string
    {
        return implode("\n", array_map(static fn (array $m): string => $m[0] . ': ' . $m[1], $this->flashes()));
    }

    protected function file(string $relative): string
    {
        return (string) @file_get_contents($this->dataDir . '/' . $relative);
    }

    /** @param array<string, string> $headers */
    private function server(array $headers): array
    {
        $server = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost', 'SERVER_PORT' => '80'];
        foreach ($headers as $name => $value) {
            if (in_array($name, ['REMOTE_ADDR', 'HTTPS', 'SERVER_PORT', 'DOCUMENT_ROOT'], true)) {
                $server[$name] = $value; // server variables, not headers
                continue;
            }
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $server;
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
