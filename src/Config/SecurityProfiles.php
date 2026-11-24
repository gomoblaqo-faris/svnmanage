<?php

declare(strict_types=1);

namespace SvnManage\Config;

/**
 * Starting points for the setup wizard's security step. They only pre-fill the form:
 * network lists and proxy authentication depend on the site, so they keep their values.
 */
final class SecurityProfiles
{
    public const DEFAULT = 'public';

    /** profile => [title, description] */
    public const PROFILES = [
        'public' => ['Public internet', 'Reachable from anywhere. Administrators need two-factor authentication, and sessions are shorter.'],
        'private' => ['Private network', 'Only reachable inside a trusted network or VPN. Two-factor authentication stays optional.'],
    ];

    private const VALUES = [
        'public' => [
            'TwoFactor' => 'admins',
            'SessionTimeout' => '1800',
            'SessionMaxLifetime' => '28800',
            'MinPasswordLength' => '12',
            'LoginMaxAttempts' => '5',
            'LoginMaxAttemptsPerIp' => '20',
            'LoginLockSeconds' => '900',
        ],
        'private' => [
            'TwoFactor' => 'optional',
            'SessionTimeout' => '3600',
            'SessionMaxLifetime' => '43200',
            'MinPasswordLength' => '12',
            'LoginMaxAttempts' => '5',
            'LoginMaxAttemptsPerIp' => '20',
            'LoginLockSeconds' => '900',
        ],
    ];

    public static function normalize(string $profile): string
    {
        return isset(self::PROFILES[$profile]) ? $profile : self::DEFAULT;
    }

    /**
     * [Common] values for $profile. "Require HTTPS" is only proposed when this very request
     * is HTTPS: proposing it otherwise would be refused, or redirect forever.
     *
     * @return array<string, string>
     */
    public static function values(string $profile, bool $requestIsHttps): array
    {
        return self::VALUES[self::normalize($profile)] + ['ForceHttps' => $requestIsHttps ? 'true' : 'false'];
    }

    public static function apply(Config $config, string $profile, bool $requestIsHttps): Config
    {
        return $config->withAll(['Common' => self::values($profile, $requestIsHttps)]);
    }
}
