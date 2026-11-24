<?php

declare(strict_types=1);

namespace SvnManage\Audit;

/** Append-only JSON-lines activity log with size-based rotation. */
final class AuditLog
{
    public const MAX_LINE_BYTES = 4096;
    public const GENERATIONS = 5;
    private const MAX_FIELD = 300;

    public function __construct(
        private readonly string $file,
        private readonly int $maxBytes = 5_242_880,
    ) {
    }

    /** Untrusted values (user names typed at login, paths…) must not bloat or flood the log. */
    private static function clip(string $value): string
    {
        $value = (string) preg_replace('/[\x00-\x1F\x7F]/', '?', $value);

        return strlen($value) > self::MAX_FIELD ? mb_strcut($value, 0, self::MAX_FIELD, 'UTF-8') . '…' : $value;
    }

    /** Keeps several generations, so a burst of entries cannot push history out. */
    private function rotate(): void
    {
        @unlink($this->file . '.' . self::GENERATIONS);
        for ($i = self::GENERATIONS - 1; $i >= 1; $i--) {
            if (is_file($this->file . '.' . $i)) {
                @rename($this->file . '.' . $i, $this->file . '.' . ($i + 1));
            }
        }
        @rename($this->file, $this->file . '.1');
    }

    /** @param array<string, string|int|bool|null> $context */
    public function record(string $user, string $ip, string $action, string $target, array $context = []): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            return;
        }
        clearstatcache(true, $this->file);
        if (is_file($this->file) && filesize($this->file) > $this->maxBytes) {
            $this->rotate();
        }
        $context = array_slice($context, 0, 20, true);
        $clean = [];
        foreach ($context as $key => $value) {
            $clean[self::clip((string) $key)] = is_string($value) ? self::clip($value) : $value;
        }
        $line = (string) json_encode([
            'ts' => date(DATE_ATOM),
            'user' => self::clip($user),
            'ip' => self::clip($ip),
            'action' => self::clip($action),
            'target' => self::clip($target),
            'context' => $clean,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $line = substr($line, 0, self::MAX_LINE_BYTES - 1);
        $isNew = !is_file($this->file);
        @file_put_contents($this->file, $line . "\n", FILE_APPEND | LOCK_EX);
        if ($isNew) {
            @chmod($this->file, \SvnManage\Support\FileStore::NEW_FILE_MODE);
        }
    }

    /**
     * Newest first, optionally filtered by a case-insensitive search string.
     *
     * @return list<array{ts: string, user: string, ip: string, action: string, target: string, context: array<string, mixed>}>
     */
    public function recent(int $limit = 200, string $search = ''): array
    {
        $lines = [];
        $files = [$this->file];
        for ($i = 1; $i <= self::GENERATIONS && count($lines) < $limit * 5 + 1000; $i++) {
            $files[] = $this->file . '.' . $i;
        }
        foreach ($files as $file) {
            if (is_file($file)) {
                $lines = [...$lines, ...array_reverse(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])];
            }
        }
        $out = [];
        foreach ($lines as $line) {
            if ($search !== '' && stripos($line, $search) === false) {
                continue;
            }
            $row = json_decode($line, true);
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'ts' => (string) ($row['ts'] ?? ''),
                'user' => (string) ($row['user'] ?? ''),
                'ip' => (string) ($row['ip'] ?? ''),
                'action' => (string) ($row['action'] ?? ''),
                'target' => (string) ($row['target'] ?? ''),
                'context' => is_array($row['context'] ?? null) ? $row['context'] : [],
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }
}
