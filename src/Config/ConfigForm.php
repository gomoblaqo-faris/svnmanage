<?php

declare(strict_types=1);

namespace SvnManage\Config;

use InvalidArgumentException;
use SvnManage\Security\Network;
use SvnManage\Http\Request;

/** Converts the settings form submission into an updated Config. */
final class ConfigForm
{
    public static function apply(Request $request, Config $config): Config
    {
        $next = $config;
        foreach (SettingsSchema::fields() as $field) {
            $name = SettingsSchema::name($field);
            $value = trim(str_replace(["\r", "\n", "\0"], '', $request->input($name)));
            switch ($field['type']) {
                case 'bool':
                    // The form sends every checkbox (a hidden "0" precedes it); absent means "not on this form".
                    if (!$request->has($name)) {
                        continue 2;
                    }
                    $value = $request->input($name) !== '0' ? 'true' : 'false';
                    break;
                case 'password':
                    if ($value === '') {
                        continue 2;
                    }
                    break;
                case 'select':
                    if (!$request->has($name)) {
                        continue 2;
                    }
                    if (!array_key_exists($value, $field['options'])) {
                        throw new InvalidArgumentException(sprintf('Invalid value for "%s".', $field['label']));
                    }
                    if ($field['key'] === 'AuthenticationStatus' && $value === 'off' && !\SvnManage\App::noAuthAllowed($request)) {
                        throw new InvalidArgumentException('Authentication can only be turned off from the server itself (or with SVNMANAGE_ALLOW_NOAUTH=1).');
                    }
                    break;
                case 'path':
                    if (!$request->has($name)) {
                        continue 2;
                    }
                    self::assertSafePath($field['key'], $value);
                    break;
                case 'networks':
                    if (!$request->has($name)) {
                        continue 2;
                    }
                    $value = implode(', ', Network::parseList($value, $field['key'] === 'TrustedProxies'));
                    if ($field['key'] === 'AllowedNetworks' && $value !== '' && !Network::matchesAny($request->ip(), Network::parseList($value))) {
                        throw new InvalidArgumentException(sprintf('Your current address %s is not in the allowed networks. Saving would lock you out.', $request->ip()));
                    }
                    break;
                case 'number':
                    if (!$request->has($name)) {
                        continue 2;
                    }
                    if ($value !== '' && (!ctype_digit($value) || (int) $value > 31536000)) {
                        throw new InvalidArgumentException(sprintf('"%s" must be a positive number.', $field['label']));
                    }
                    if ($value !== '' && (int) $value < (self::MINIMUMS[$field['key']] ?? 0)) {
                        throw new InvalidArgumentException(sprintf('"%s" must be at least %d.', $field['label'], self::MINIMUMS[$field['key']]));
                    }
                    break;
                default:
                    if (!$request->has($name)) {
                        continue 2;
                    }
            }
            $next = $next->with($field['section'], $field['key'], $value);
        }
        self::assertHttpsReachable($request, $next);

        return self::applyParents($request, $next);
    }

    public const HTTPS_LOOP = 'HTTPS cannot be required yet: this request did not arrive over HTTPS as far as svnmanage can tell, so every page would redirect forever. Open the settings through HTTPS first. If a proxy or tunnel handles HTTPS, have the web server set the HTTPS variable for its requests (needed when the web server already replaces the client address, as mod_remoteip does), or list the proxy under Trusted reverse proxies.';

    /**
     * "Require HTTPS" must hold for the request saving it, judged with the proxy list being
     * saved: otherwise every later request is redirected to itself and nobody gets back in.
     */
    private static function assertHttpsReachable(Request $request, Config $next): void
    {
        if (!$next->bool('Common', 'ForceHttps')) {
            return;
        }
        $proxies = Network::parseList($next->string('Common', 'TrustedProxies', ''), true);
        if (!$request->withTrustedProxies($proxies)->isSecure()) {
            throw new InvalidArgumentException(self::HTTPS_LOOP);
        }
    }

    /** Values below these would silently switch a protection off. */
    private const MINIMUMS = ['BackupCount' => 1, 'MinPasswordLength' => 8, 'LoginMaxAttempts' => 1, 'LoginMaxAttemptsPerIp' => 1, 'LoginLockSeconds' => 60];

    private const EXECUTABLES = ['SvnExecutable' => 'svn', 'SvnAdminExecutable' => 'svnadmin', 'SvnLookExecutable' => 'svnlook'];

    /**
     * Settings must not become a way to run arbitrary programs or to write files the
     * web server would execute, even for an administrator whose account was taken over.
     */
    private static function assertSafePath(string $key, string $value): void
    {
        if ($value === '') {
            return;
        }
        if (isset(self::EXECUTABLES[$key])) {
            $tool = self::EXECUTABLES[$key];
            // Either the bare program name, or an absolute path whose last segment is it.
            $ok = preg_match('#^(?:(?:/[A-Za-z0-9._+-]+)*/)?' . $tool . '(?:\.exe)?$#D', $value) === 1
                && ($value === $tool || $value === $tool . '.exe' || str_starts_with($value, '/'))
                && !str_contains($value, '/../') && !str_contains($value, '/./');
            if (!$ok) {
                throw new InvalidArgumentException(sprintf('The %1$s setting must be the %2$s program: "%2$s" or an absolute path ending in /%2$s.', $key, $tool));
            }

            return;
        }
        $base = strtolower(basename($value));
        if (preg_match('/\.(php\d?|phtml|phar|pht|phps|inc|cgi|pl|py|sh)$/D', $base) || in_array($base, ['.htaccess', '.user.ini', 'web.config'], true)) {
            throw new InvalidArgumentException(sprintf('The file name "%s" is not allowed for %s.', basename($value), $key));
        }
        if (str_contains($value, "\0")) {
            throw new InvalidArgumentException('Invalid path.');
        }
    }

    /** @return list<array{path: string, description: string}> extra repository locations */
    public static function extraParents(Config $config): array
    {
        $out = [];
        for ($i = 1; $i < 100; $i++) {
            $path = $config->raw('Repositories:svnclient:' . $i, 'SVNParentPath');
            if ($path === '') {
                break;
            }
            $out[] = ['path' => $path, 'description' => $config->raw('Repositories:svnclient:' . $i, 'Description')];
        }

        return $out;
    }

    private static function applyParents(Request $request, Config $config): Config
    {
        if (!$request->has('extra_path')) {
            return $config;
        }
        $paths = self::rawList($request, 'extra_path');
        $descs = self::rawList($request, 'extra_desc');
        $next = $config;
        for ($i = 1; $i < 100; $i++) {
            if ($next->raw('Repositories:svnclient:' . $i, 'SVNParentPath') === '' && !in_array('Repositories:svnclient:' . $i, $next->sections(), true)) {
                break;
            }
            $next = $next->withoutSection('Repositories:svnclient:' . $i);
        }
        $index = 1;
        foreach ($paths as $pos => $path) {
            $path = trim(str_replace(["\r", "\n"], '', $path));
            if ($path === '') {
                continue;
            }
            $section = 'Repositories:svnclient:' . $index++;
            $next = $next->with($section, 'SVNParentPath', $path)
                ->with($section, 'Description', trim(str_replace(["\r", "\n"], '', $descs[$pos] ?? '')));
        }

        return $next;
    }

    /** @return array<int, string> keeps positions (empty rows included) */
    private static function rawList(Request $request, string $key): array
    {
        $all = $request->allPost()[$key] ?? [];

        return is_array($all) ? array_map('strval', $all) : [];
    }
}
