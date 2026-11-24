<?php

declare(strict_types=1);

namespace SvnManage\Security;

use InvalidArgumentException;

/**
 * Apache htpasswd compatible hashing.
 * Hashes: bcrypt ($2y$), apr1 (Apache MD5), sha1 ({SHA}); verifies crypt() variants too.
 */
final class PasswordHasher
{
    public const ALGORITHMS = ['bcrypt', 'apr1', 'sha1'];
    private const APR_ALPHABET = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    /**
     * Kept modest on purpose: Apache verifies the hash on every Subversion HTTP request,
     * so a high cost slows down every svn command.
     */
    public const BCRYPT_COST = 10;

    /**
     * Does the work of one real bcrypt check (one hash at the stored cost) for an unknown
     * user, so response time does not reveal which user names exist.
     */
    public static function equaliseTiming(string $password): void
    {
        password_hash(substr($password, 0, 72), PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);
    }

    public static function hash(string $password, string $algorithm = 'bcrypt'): string
    {
        return match (strtolower($algorithm)) {
            'bcrypt', 'blowfish' => password_hash($password, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]),
            'apr1', 'md5' => self::apr1($password, self::randomSalt()),
            'sha1', 'sha' => '{SHA}' . base64_encode(sha1($password, true)),
            default => throw new InvalidArgumentException('Unsupported hash algorithm: ' . $algorithm),
        };
    }

    public static function verify(string $password, string $hash): bool
    {
        if ($hash === '') {
            return false;
        }
        if (str_starts_with($hash, '{SHA}')) {
            return hash_equals($hash, '{SHA}' . base64_encode(sha1($password, true)));
        }
        if (preg_match('/^\$2[abxy]\$\d{2}\$/', $hash)) {
            return password_verify($password, $hash);
        }
        if (preg_match('/^\$apr1\$([^$]{1,8})\$/', $hash, $m)) {
            return hash_equals($hash, self::apr1($password, $m[1]));
        }
        if (preg_match('/^(\$[156]\$|[A-Za-z0-9.\/]{2}[A-Za-z0-9.\/]{11}$)/', $hash)) {
            $computed = crypt($password, $hash);

            return strlen($computed) > 12 && hash_equals($hash, $computed);
        }

        return false;
    }

    public static function apr1(string $password, string $salt): string
    {
        $salt = substr($salt, 0, 8);
        $len = strlen($password);
        $text = $password . '$apr1$' . $salt;
        $bin = md5($password . $salt . $password, true);
        for ($i = $len; $i > 0; $i -= 16) {
            $text .= substr($bin, 0, min(16, $i));
        }
        for ($i = $len; $i > 0; $i >>= 1) {
            $text .= ($i & 1) ? "\0" : ($password[0] ?? '');
        }
        $bin = md5($text, true);
        for ($i = 0; $i < 1000; $i++) {
            $new = ($i & 1) ? $password : $bin;
            if ($i % 3) {
                $new .= $salt;
            }
            if ($i % 7) {
                $new .= $password;
            }
            $new .= ($i & 1) ? $bin : $password;
            $bin = md5($new, true);
        }
        $tmp = '';
        for ($i = 0; $i < 5; $i++) {
            $k = $i + 6;
            $j = $i + 12;
            if ($j === 16) {
                $j = 5;
            }
            $tmp = $bin[$i] . $bin[$k] . $bin[$j] . $tmp;
        }
        $tmp = "\0\0" . $bin[11] . $tmp;
        $encoded = strtr(
            strrev(substr(base64_encode($tmp), 2)),
            'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/',
            self::APR_ALPHABET,
        );

        return '$apr1$' . $salt . '$' . $encoded;
    }

    private static function randomSalt(): string
    {
        $salt = '';
        for ($i = 0; $i < 8; $i++) {
            $salt .= self::APR_ALPHABET[random_int(0, 63)];
        }

        return $salt;
    }
}
