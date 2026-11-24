<?php

declare(strict_types=1);

namespace SvnManage\Security;

/**
 * Finds managed files the web server would publish (a document root that also holds the
 * repositories or the password file) and files every local account can read or change.
 * svnmanage creates its own files 0640, but files it adopts keep the mode they had.
 */
final class ExposureCheck
{
    public const IN_WEB_ROOT = '%0: %1 is inside the folder the web server publishes (%2). Move it out, or make sure the web server can never serve it.';
    public const WORLD_WRITABLE = '%0: %1 can be changed by every account on this server. Remove write access for others (chmod o-w).';
    public const WORLD_READABLE = '%0: %1 can be read by every account on this server, and it holds password hashes or keys. Remove read access for others (chmod o-r).';

    /**
     * @param list<ManagedPath> $paths
     * @param list<string> $webRoots folders the web server serves (empty entries are ignored)
     * @return list<array{0: string, 1: list<string>}> translatable message and its arguments
     */
    public static function run(array $paths, array $webRoots): array
    {
        // On Windows fileperms() reports 0666/0777 for everything: the mode checks would only cry wolf.
        $windows = PHP_OS_FAMILY === 'Windows';
        $roots = self::existing($webRoots);
        $warnings = [];
        foreach ($paths as $managed) {
            $real = $managed->path === '' ? false : realpath($managed->path);
            if ($real === false) {
                continue;
            }
            foreach ($roots as $root) {
                if (self::isWithin($real, $root, $windows)) {
                    $warnings[] = [self::IN_WEB_ROOT, [$managed->label, $real, $root]];
                    break;
                }
            }
            $mode = $windows ? false : @fileperms($real);
            if ($mode === false) {
                continue;
            }
            if (($mode & 0o002) !== 0) {
                $warnings[] = [self::WORLD_WRITABLE, [$managed->label, $real]];
            }
            if ($managed->secret && is_file($real) && ($mode & 0o004) !== 0) {
                $warnings[] = [self::WORLD_READABLE, [$managed->label, $real]];
            }
        }

        return $warnings;
    }

    /** @param list<string> $paths @return list<string> resolved, unique, existing folders */
    private static function existing(array $paths): array
    {
        $out = [];
        foreach ($paths as $path) {
            $real = $path === '' ? false : realpath($path);
            if ($real !== false && is_dir($real)) {
                $out[$real] = $real;
            }
        }

        return array_values($out);
    }

    /** Whether $path is $root or below it, comparing whole path segments. */
    public static function isWithin(string $path, string $root, bool $windows = PHP_OS_FAMILY === 'Windows'): bool
    {
        if ($windows) {
            $path = strtolower(str_replace('\\', '/', $path));
            $root = strtolower(str_replace('\\', '/', $root));
        }
        $root = rtrim($root, '/');

        return $path === $root || $root === '' || str_starts_with($path, $root . '/');
    }

    /** Fills %0, %1 … in one pass, so a path that contains "%1" stays as it is. */
    public static function format(string $message, array $args): string
    {
        $map = [];
        foreach (array_values($args) as $i => $arg) {
            $map['%' . $i] = (string) $arg;
        }

        return strtr($message, $map);
    }
}
