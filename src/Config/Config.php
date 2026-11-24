<?php

declare(strict_types=1);

namespace SvnManage\Config;

use SvnManage\Support\IniDocument;

/**
 * Immutable view of config.ini (iF.SVNAdmin compatible sections/keys).
 * Relative paths ("./data/x") are resolved against the application root.
 */
final class Config
{
    public function __construct(
        private readonly IniDocument $doc,
        private readonly string $appRoot,
        private readonly ?string $dataDir = null,
    ) {
    }

    public static function fromString(string $text, string $appRoot, ?string $dataDir = null): self
    {
        return new self(IniDocument::parse($text, '=', '#;'), $appRoot, $dataDir);
    }

    public function string(string $section, string $key, string $default = ''): string
    {
        $value = $this->doc->get($section, $key);

        return $value === null || $value === '' ? $default : self::unquote($value);
    }

    public function raw(string $section, string $key): string
    {
        return self::unquote((string) $this->doc->get($section, $key, ''));
    }

    public function bool(string $section, string $key, bool $default = false): bool
    {
        $value = strtolower($this->string($section, $key));

        return match ($value) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => $default,
        };
    }

    public function int(string $section, string $key, int $default = 0): int
    {
        $value = $this->string($section, $key);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function path(string $section, string $key, string $default = ''): string
    {
        return $this->resolvePath($this->string($section, $key, $default));
    }

    public function resolvePath(string $path): string
    {
        if ($path === '') {
            return '';
        }
        $isAbsolute = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
        $relative = (string) preg_replace('#^\./#', '', $path);
        $resolved = match (true) {
            $isAbsolute => $path,
            $this->dataDir !== null && ($relative === 'data' || str_starts_with($relative, 'data/')) => $this->dataDir . substr($relative, 4),
            default => $this->appRoot . '/' . $relative,
        };

        return rtrim($resolved, '/') === '' ? '/' : rtrim($resolved, '/');
    }

    public function with(string $section, string $key, string $value): self
    {
        $clean = str_replace(["\r", "\n"], '', $value);

        return new self($this->doc->with($section, $key, $clean), $this->appRoot, $this->dataDir);
    }

    /** @param array<string, array<string, string>> $values */
    public function withAll(array $values): self
    {
        $next = $this;
        foreach ($values as $section => $pairs) {
            foreach ($pairs as $key => $value) {
                $next = $next->with($section, $key, $value);
            }
        }

        return $next;
    }

    public function withoutSection(string $section): self
    {
        return new self($this->doc->withoutSection($section), $this->appRoot, $this->dataDir);
    }

    /** @return list<string> */
    public function sections(): array
    {
        return $this->doc->sections();
    }

    public function appRoot(): string
    {
        return $this->appRoot;
    }

    public function render(): string
    {
        return $this->doc->render();
    }

    private static function unquote(string $value): string
    {
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[strlen($value) - 1] === $value[0]) {
            return substr($value, 1, -1);
        }

        return $value;
    }
}
