<?php

declare(strict_types=1);

namespace SvnManage\Http;

/** Immutable snapshot of the incoming HTTP request. */
final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     * @param array<string, mixed> $server
     * @param array<string, string> $cookies
     * @param list<string> $trustedProxies
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly string $basePath,
        private readonly array $query = [],
        private readonly array $post = [],
        private readonly array $files = [],
        private readonly array $server = [],
        private readonly array $cookies = [],
        private readonly array $trustedProxies = [],
    ) {
    }

    /**
     * Returns a copy that honours X-Forwarded-For / X-Forwarded-Proto, but only when
     * the direct peer is one of these proxies (addresses or CIDR ranges).
     *
     * @param list<string> $proxies
     */
    public function withTrustedProxies(array $proxies): self
    {
        return new self($this->method, $this->path, $this->basePath, $this->query, $this->post, $this->files, $this->server, $this->cookies, $proxies);
    }

    public function isFromTrustedProxy(): bool
    {
        return $this->trustedProxies !== []
            && \SvnManage\Security\Network::matchesAny((string) ($this->server['REMOTE_ADDR'] ?? ''), $this->trustedProxies);
    }

    public static function fromGlobals(): self
    {
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php');
        $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
        $uri = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $uri = rawurldecode($uri);
        if (str_starts_with($uri, $script)) {
            $uri = substr($uri, strlen($script));
        } elseif ($base !== '' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }
        $path = '/' . trim($uri, '/');

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path,
            $base,
            $_GET,
            $_POST,
            $_FILES,
            $_SERVER,
            array_map('strval', $_COOKIE),
        );
    }

    public function queryString(): string
    {
        return http_build_query($this->query, '', '&', PHP_QUERY_RFC3986);
    }

    public function query(string $key, string $default = ''): string
    {
        $value = $this->query[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    public function input(string $key, string $default = ''): string
    {
        $value = $this->post[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    /** @return list<string> */
    public function inputList(string $key): array
    {
        $value = $this->post[$key] ?? $this->query[$key] ?? [];
        if (is_string($value)) {
            $value = $value === '' ? [] : [$value];
        }

        return is_array($value) ? array_values(array_unique(array_filter(array_map('strval', $value), static fn (string $s): bool => $s !== ''))) : [];
    }

    public function has(string $key): bool
    {
        return isset($this->post[$key]) || isset($this->query[$key]);
    }

    /** @return array{name: string, tmp_name: string, size: int, error: int}|null */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        if (!is_array($file) || !isset($file['tmp_name']) || is_array($file['tmp_name'])) {
            return null;
        }

        return [
            'name' => (string) $file['name'],
            'tmp_name' => (string) $file['tmp_name'],
            'size' => (int) $file['size'],
            'error' => (int) $file['error'],
        ];
    }

    /**
     * Whether forwarded headers say the client used HTTPS, whoever sent them. Only for
     * diagnostics: trust decisions use isSecure().
     */
    public function claimsHttps(): bool
    {
        return strtolower($this->header('X-Forwarded-Proto')) === 'https'
            || preg_match('/(^|[;,\s])proto="?https"?($|[;,\s])/i', $this->header('Forwarded')) === 1
            || strtolower($this->header('X-Forwarded-Ssl')) === 'on'
            || str_contains(strtolower($this->header('CF-Visitor')), '"scheme":"https"');
    }

    public function header(string $name): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        return (string) ($this->server[$key] ?? '');
    }

    public function server(string $key): string
    {
        return (string) ($this->server[$key] ?? '');
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function isHtmx(): bool
    {
        return $this->header('HX-Request') === 'true';
    }

    public function isBoosted(): bool
    {
        return $this->header('HX-Boosted') === 'true';
    }

    /** True for htmx requests that only want a fragment (not boosted navigation). */
    public function wantsFragment(): bool
    {
        return $this->isHtmx() && !$this->isBoosted() && $this->header('HX-History-Restore-Request') !== 'true';
    }

    /**
     * The client address. Behind trusted proxies this is the rightmost X-Forwarded-For
     * hop that is not itself a trusted proxy, so a client cannot prepend fake entries.
     */
    public function ip(): string
    {
        $remote = \SvnManage\Security\Network::normalise((string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0'));
        if (!$this->isFromTrustedProxy()) {
            return $remote;
        }
        $hops = array_reverse(array_map('trim', explode(',', (string) ($this->server['HTTP_X_FORWARDED_FOR'] ?? ''))));
        foreach ($hops as $hop) {
            if (@inet_pton($hop) === false) {
                return $remote;
            }
            if (!\SvnManage\Security\Network::matchesAny($hop, $this->trustedProxies)) {
                return \SvnManage\Security\Network::normalise($hop);
            }
        }

        return $remote;
    }

    public function isSecure(): bool
    {
        return ($this->server['HTTPS'] ?? '') !== '' && ($this->server['HTTPS'] ?? '') !== 'off'
            || ($this->server['SERVER_PORT'] ?? '') === '443'
            || ($this->isFromTrustedProxy() && strtolower((string) ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
    }

    /** @return array<string, mixed> */
    public function allPost(): array
    {
        return $this->post;
    }

    /** @return array<string, mixed> */
    public function allQuery(): array
    {
        return $this->query;
    }
}
