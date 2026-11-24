<?php

declare(strict_types=1);

namespace SvnManage\Http;

/** Immutable HTTP response; stream bodies are written by a callback. */
final class Response
{
    /**
     * @param array<string, string> $headers
     * @param (callable(): void)|null $streamer
     */
    public function __construct(
        public readonly int $status = 200,
        public readonly string $body = '',
        public readonly array $headers = [],
        private readonly mixed $streamer = null,
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, $body, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self($status, (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ['Content-Type' => 'application/json']);
    }

    /** Redirect that also works for htmx fragment requests. */
    public static function redirect(string $url, ?Request $request = null): self
    {
        if ($request !== null && $request->wantsFragment()) {
            return new self(200, '', ['HX-Redirect' => $url]);
        }

        return new self(303, '', ['Location' => $url]);
    }

    /** @param callable(): void $streamer */
    public static function stream(callable $streamer, array $headers): self
    {
        return new self(200, '', $headers, $streamer);
    }

    public function withHeader(string $name, string $value): self
    {
        return new self($this->status, $this->body, [...$this->headers, $name => $value], $this->streamer);
    }

    /** Content-Disposition with an ASCII fallback and the exact UTF-8 name (RFC 6266/5987). */
    public static function attachment(string $filename): string
    {
        $ascii = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename);

        return 'attachment; filename="' . ($ascii === '' ? 'download' : $ascii) . '"; filename*=UTF-8\'\'' . rawurlencode($filename);
    }

    public function send(): void
    {
        if (!headers_sent()) {
            header_remove('X-Powered-By'); // do not advertise the PHP version
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, strcasecmp($name, 'Set-Cookie') !== 0);
            }
        }
        if ($this->streamer !== null) {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            ($this->streamer)();

            return;
        }
        echo $this->body;
    }
}
