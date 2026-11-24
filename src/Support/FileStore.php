<?php

declare(strict_types=1);

namespace SvnManage\Support;

use RuntimeException;

/**
 * Locked read-modify-write access to the plain files svnmanage manages
 * (authz, htpasswd, htdigest, ini files).
 *
 * Writes happen in place on the locked handle so concurrent writers
 * serialise correctly (a rename-based write would let a waiting process
 * keep a handle on the stale inode). A timestamped backup is taken first.
 */
final class FileStore
{
    /** Owner read/write, group read (the Subversion server's group), nothing for others. */
    public const NEW_FILE_MODE = 0640;

    public function __construct(
        private readonly ?string $backupDir = null,
        private readonly int $backupKeep = 30,
    ) {
    }

    public function read(string $path): string
    {
        if (!is_file($path)) {
            throw new RuntimeException(sprintf('File not found: %s', $path));
        }
        if (!is_readable($path)) {
            throw new RuntimeException(sprintf('File is not readable: %s', $path));
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Cannot open file: %s', $path));
        }
        try {
            flock($handle, LOCK_SH);
            $content = stream_get_contents($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        return $content === false ? '' : $content;
    }

    /**
     * Applies $transform to the current content under an exclusive lock.
     *
     * @param callable(string): string $transform
     */
    public function update(string $path, callable $transform): string
    {
        $exists = is_file($path);
        if ($exists && !is_writable($path)) {
            throw new RuntimeException(sprintf('File is not writable: %s', $path));
        }
        $handle = @fopen($path, 'c+b');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Cannot open file for writing: %s', $path));
        }
        if (!$exists) {
            @chmod($path, self::NEW_FILE_MODE); // new files may hold hashes; existing ones keep their mode
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException(sprintf('Cannot lock file: %s', $path));
            }
            $current = stream_get_contents($handle);
            $current = $current === false ? '' : $current;
            $next = $transform($current);
            if ($next !== $current) {
                if ($exists && $current !== '') {
                    $this->backup($path, $current);
                }
                rewind($handle);
                ftruncate($handle, 0);
                if (fwrite($handle, $next) !== strlen($next)) {
                    throw new RuntimeException(sprintf('Short write to %s', $path));
                }
                fflush($handle);
            }
            flock($handle, LOCK_UN);

            return $next;
        } finally {
            fclose($handle);
        }
    }

    public function write(string $path, string $content): void
    {
        $this->update($path, static fn (): string => $content);
    }

    /** @return list<array{file: string, path: string, size: int, time: int}> newest first */
    public function backups(): array
    {
        if ($this->backupDir === null || !is_dir($this->backupDir)) {
            return [];
        }
        $out = [];
        foreach (glob($this->backupDir . '/*.bak') ?: [] as $file) {
            $out[] = ['file' => basename($file), 'path' => $file, 'size' => (int) filesize($file), 'time' => (int) filemtime($file)];
        }
        usort($out, static fn (array $a, array $b): int => [$b['time'], $b['file']] <=> [$a['time'], $a['file']]);

        return $out;
    }

    private function backup(string $path, string $content): void
    {
        if ($this->backupDir === null || $this->backupKeep <= 0) {
            return;
        }
        if (!is_dir($this->backupDir) && !@mkdir($this->backupDir, 0770, true) && !is_dir($this->backupDir)) {
            return;
        }
        $base = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($path)) ?? 'file';
        $stamp = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        $target = $this->backupDir . '/' . $base . '.' . $stamp . '.bak';
        if (@file_put_contents($target, $content) !== false) {
            @chmod($target, 0640); // backups contain password hashes and bind passwords
        }
        $this->prune($base);
    }

    private function prune(string $base): void
    {
        $files = glob($this->backupDir . '/' . $base . '.*.bak') ?: [];
        if (count($files) <= $this->backupKeep) {
            return;
        }
        usort($files, static fn (string $a, string $b): int => filemtime($a) <=> filemtime($b) ?: strcmp($a, $b));
        foreach (array_slice($files, 0, count($files) - $this->backupKeep) as $old) {
            @unlink($old);
        }
    }
}
