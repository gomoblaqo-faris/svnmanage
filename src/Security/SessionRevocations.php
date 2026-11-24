<?php

declare(strict_types=1);

namespace SvnManage\Security;

/**
 * Per-user "sessions started before this moment are invalid" timestamps
 * (data/revocations.json). Bumped on password changes, deletion and 2FA resets.
 */
final class SessionRevocations
{
    public function __construct(private readonly string $file)
    {
    }

    /** Microsecond timestamps: a login in the same second as a revocation is still ordered. */
    public function revokedBefore(string $user): float
    {
        return (float) ($this->read()[$user] ?? 0);
    }

    public function isRevoked(string $user, float $loginAt): bool
    {
        return $loginAt <= $this->revokedBefore($user);
    }

    public function revoke(string $user, ?float $now = null): void
    {
        $now ??= microtime(true);
        $handle = @fopen($this->file, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Cannot record the session revocation.');
        }
        try {
            flock($handle, LOCK_EX);
            $data = json_decode((string) stream_get_contents($handle), true);
            $data = is_array($data) ? $data : [];
            $data[$user] = $now;
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, (string) json_encode($data));
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        @chmod($this->file, \SvnManage\Support\FileStore::NEW_FILE_MODE);
    }

    /** @return array<string, int|float> */
    private function read(): array
    {
        if (!is_file($this->file)) {
            return [];
        }
        $handle = @fopen($this->file, 'r');
        if ($handle === false) {
            return [];
        }
        flock($handle, LOCK_SH);
        $data = json_decode((string) stream_get_contents($handle), true);
        flock($handle, LOCK_UN);
        fclose($handle);

        return is_array($data) ? $data : [];
    }
}
