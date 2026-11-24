<?php

declare(strict_types=1);

namespace SvnManage\Security;

/**
 * One-time key that proves the person running the setup wizard has access to
 * the server (it is only readable there: data/setup.key, mode 0600).
 */
final class SetupKey
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';

    public function __construct(public readonly string $file)
    {
    }

    /** Returns the current key, creating it on first use. */
    public function ensure(): string
    {
        if (is_file($this->file)) {
            $key = trim((string) file_get_contents($this->file));
            if ($key !== '') {
                return $key;
            }
        }
        $key = '';
        for ($i = 0; $i < 24; $i++) {
            $key .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        $old = umask(0077);
        try {
            if (file_put_contents($this->file, $key, LOCK_EX) === false) {
                throw new \RuntimeException(sprintf('Cannot write the setup key to %s.', $this->file));
            }
        } finally {
            umask($old);
        }
        @chmod($this->file, 0600);

        return $key;
    }

    public function verify(string $candidate): bool
    {
        $candidate = trim($candidate);

        return $candidate !== '' && is_file($this->file) && hash_equals($this->ensure(), $candidate);
    }

    public function remove(): void
    {
        if (is_file($this->file)) {
            @unlink($this->file);
        }
    }
}
