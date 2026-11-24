<?php

declare(strict_types=1);

namespace SvnManage\Authz;

use InvalidArgumentException;

/** Validation and normalisation of names used in the authz / user files. */
final class Names
{
    public const USER_PATTERN = '/^[A-Za-z0-9_][A-Za-z0-9._@+\-]{0,99}$/D';
    public const GROUP_PATTERN = '/^[A-Za-z0-9_][A-Za-z0-9._\-]{0,99}$/D';
    public const REPOSITORY_PATTERN = '/^[A-Za-z0-9_][A-Za-z0-9._\-]{0,99}$/D';
    public const ACCESS_PATH_PATTERN = '/^(?:[A-Za-z0-9_][A-Za-z0-9._\-]*:)?\/[^\[\]\r\n\0]*$/D';

    public static function user(string $name): string
    {
        $name = trim($name);
        if (!preg_match(self::USER_PATTERN, $name)) {
            throw new InvalidArgumentException(
                'Invalid user name. Use letters, digits, ".", "_", "-", "+" or "@" (no spaces, must not start with a symbol).'
            );
        }

        return $name;
    }

    public const SPECIAL_TOKENS = ['*', '$authenticated', '$anonymous'];

    /**
     * Validates a member/rule token of the authz file: a user, "@group", "&alias",
     * one of the special tokens, optionally inverted with a single "~".
     */
    public static function token(string $token): string
    {
        $body = str_starts_with($token, '~') ? substr($token, 1) : $token;
        $valid = match (true) {
            in_array($body, self::SPECIAL_TOKENS, true) => true,
            str_starts_with($body, '@'), str_starts_with($body, '&') => (bool) preg_match(self::GROUP_PATTERN, substr($body, 1)),
            default => (bool) preg_match(self::USER_PATTERN, $body),
        };
        if (!$valid) {
            throw new InvalidArgumentException(sprintf('Invalid user or group "%s".', addcslashes(substr($token, 0, 60), "\0..\37")));
        }

        return $token;
    }

    public static function group(string $name): string
    {
        $name = trim($name);
        if (!preg_match(self::GROUP_PATTERN, $name)) {
            throw new InvalidArgumentException(
                sprintf('Invalid group name "%s". Allowed: letters, digits, ".", "_" and "-" (no spaces).', $name)
            );
        }

        return $name;
    }

    public static function repository(string $name): string
    {
        $name = trim($name);
        if (!preg_match(self::REPOSITORY_PATTERN, $name) || $name === '.' || $name === '..') {
            throw new InvalidArgumentException(
                'Invalid repository name. Allowed: letters, digits, ".", "_" and "-".'
            );
        }

        return $name;
    }

    /** Normalises "repo:/a/b/" to "repo:/a/b" and validates it. */
    public static function accessPath(string $path): string
    {
        $path = trim($path);
        $path = preg_replace('#/{2,}#', '/', $path) ?? $path;
        if (strlen($path) > 1 && str_ends_with($path, '/') && !str_ends_with($path, ':/')) {
            $path = rtrim($path, '/');
        }
        if ($path === '' || !preg_match(self::ACCESS_PATH_PATTERN, $path) || str_contains($path, '/../') || str_ends_with($path, '/..')) {
            throw new InvalidArgumentException(
                sprintf('Invalid access path "%s". Use "repository:/path" or "/path".', $path)
            );
        }

        return $path;
    }

    /** "repo:/trunk" => "repo", "/trunk" => null */
    public static function repositoryOfPath(string $path): ?string
    {
        $pos = strpos($path, ':/');

        return $pos === false ? null : substr($path, 0, $pos);
    }

    /** "repo:/trunk/x" => "trunk/x" */
    public static function relativeOfPath(string $path): string
    {
        $pos = strpos($path, ':/');
        $rel = $pos === false ? $path : substr($path, $pos + 2);

        return trim($rel, '/');
    }

    /** True when $path equals $scope or lies below it (component-wise). */
    public static function isWithin(string $path, string $scope): bool
    {
        if ($path === $scope) {
            return true;
        }
        $prefix = str_ends_with($scope, '/') ? $scope : $scope . '/';

        return str_starts_with($path, $prefix);
    }
}
