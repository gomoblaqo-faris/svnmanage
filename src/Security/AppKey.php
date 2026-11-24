<?php

declare(strict_types=1);

namespace SvnManage\Security;

/** Per-installation secret for signatures (data/app.key, 0600, created on first use). */
final class AppKey
{
    public static function load(string $file): string
    {
        $key = is_file($file) ? trim((string) @file_get_contents($file)) : '';
        if (preg_match('/^[0-9a-f]{64}$/D', $key)) {
            return $key;
        }
        $key = bin2hex(random_bytes(32));
        $old = umask(0077);
        try {
            // x mode: if two requests race, the loser re-reads the winner's key.
            $handle = @fopen($file, 'x');
            if ($handle === false) {
                $existing = trim((string) @file_get_contents($file));
                if (preg_match('/^[0-9a-f]{64}$/D', $existing)) {
                    return $existing;
                }
                throw new \RuntimeException('Cannot create the application key file.');
            }
            fwrite($handle, $key);
            fclose($handle);
        } finally {
            umask($old);
        }
        @chmod($file, 0600);

        return $key;
    }
}
