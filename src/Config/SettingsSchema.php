<?php

declare(strict_types=1);

namespace SvnManage\Config;

use SvnManage\I18n\Translator;
use SvnManage\Security\PasswordHasher;

/**
 * Declarative description of every editable config.ini key, grouped for
 * the settings page. Field names are "Section|Key".
 */
final class SettingsSchema
{
    /**
     * @return list<array{id: string, title: string, icon: string, intro: string, fields: list<array{section: string, key: string, label: string, type: string, options?: array<string, string>, hint?: string, test?: string, placeholder?: string, essential?: bool}>}>
     */
    public static function groups(): array
    {
        $onOff = ['off' => 'off'];

        return [
            [
                'id' => 'providers',
                'title' => 'Data providers',
                'icon' => 'plug',
                'intro' => 'Choose where users, groups, access paths and repositories come from.',
                'fields' => [
                    self::select('Engine:Providers', 'AuthenticationStatus', 'Authentication', ['basic' => 'basic (login required)', 'off' => 'off (no login, everyone is admin)'], 'Turning authentication off is only safe on a trusted, private network.', true),
                    self::select('Engine:Providers', 'UserViewProviderType', 'User view provider', ['passwd' => 'passwd (htpasswd file)', 'digest' => 'digest (htdigest file)', 'ldap' => 'ldap', 'ad' => 'ad (Active Directory)'] + $onOff, null, true),
                    self::select('Engine:Providers', 'UserEditProviderType', 'User edit provider', ['passwd' => 'passwd', 'digest' => 'digest'] + $onOff, 'LDAP and AD users are read-only.', true),
                    self::select('Engine:Providers', 'GroupViewProviderType', 'Group view provider', ['svnauthfile' => 'svnauthfile', 'ldap' => 'ldap', 'ad' => 'ad'] + $onOff, 'LDAP/AD groups are copied into the authz file by the synchronisation.', true),
                    self::select('Engine:Providers', 'GroupEditProviderType', 'Group edit provider', ['svnauthfile' => 'svnauthfile'] + $onOff, null, true),
                    self::select('Engine:Providers', 'AccessPathViewProviderType', 'Access-Path view provider', ['svnauthfile' => 'svnauthfile'] + $onOff),
                    self::select('Engine:Providers', 'AccessPathEditProviderType', 'Access-Path edit provider', ['svnauthfile' => 'svnauthfile'] + $onOff),
                    self::select('Engine:Providers', 'RepositoryViewProviderType', 'Repository view provider', ['svnclient' => 'svnclient'] + $onOff, null, true),
                    self::select('Engine:Providers', 'RepositoryEditProviderType', 'Repository edit provider', ['svnclient' => 'svnclient'] + $onOff, null, true),
                ],
            ],
            [
                'id' => 'files',
                'title' => 'Subversion files',
                'icon' => 'file-text',
                'intro' => 'The authorization file and the user file used by Apache / svnserve.',
                'fields' => [
                    self::path('Subversion', 'SVNAuthFile', 'Subversion authorization file', 'SVNAuthFile', '/etc/subversion/authz', 'The file referenced by AuthzSVNAccessFile (Apache) or authz-db (svnserve).'),
                    self::path('Users:passwd', 'SVNUserFile', 'User file (htpasswd)', 'SVNUserFile', '/etc/subversion/passwd'),
                    self::select('Users:passwd', 'HashAlgorithm', 'Password hash for new passwords', array_combine(PasswordHasher::ALGORITHMS, ['bcrypt (recommended)', 'apr1 (Apache MD5)', 'sha1 (legacy)']), 'All formats are accepted at login; this only affects newly set passwords.'),
                    self::path('Users:digest', 'SVNUserDigestFile', 'User file (htdigest)', 'SVNUserDigestFile', '/etc/subversion/digest'),
                    self::text('Users:digest', 'SVNDigestRealm', 'Digest realm', 'SVN Privat', 'Must match AuthName of your Apache configuration.'),
                    self::path('ACLManager', 'UserRoleAssignmentFile', 'Web role assignment file', 'UserRoleAssignmentFile', './data/userroleassignments.ini'),
                ],
            ],
            [
                'id' => 'repositories',
                'title' => 'Repositories',
                'icon' => 'database',
                'intro' => 'Where repositories live and which Subversion tools manage them.',
                'fields' => [
                    self::path('Repositories:svnclient', 'SVNParentPath', 'Repository parent folder', 'SVNParentPath', '/var/svn', 'Local folder containing the repositories (no URL).'),
                    self::text('Repositories:svnclient', 'Description', 'Description', 'Repositories'),
                    self::path('Repositories:svnclient', 'SvnExecutable', 'svn executable', 'SvnExecutable', 'svn'),
                    self::path('Repositories:svnclient', 'SvnAdminExecutable', 'svnadmin executable', 'SvnAdminExecutable', 'svnadmin'),
                    self::path('Repositories:svnclient', 'SvnLookExecutable', 'svnlook executable', 'SvnLookExecutable', 'svnlook', 'Leave empty to use the svnlook next to svnadmin.'),
                ],
            ],
            [
                'id' => 'ldap',
                'title' => 'LDAP',
                'icon' => 'network',
                'intro' => 'Used when a provider is set to "ldap".',
                'fields' => [
                    self::text('Ldap', 'HostAddress', 'Host address', 'ldap://ldap.example.com:389/', null, 'LdapConnection'),
                    self::select('Ldap', 'ProtocolVersion', 'Protocol version', ['3' => '3', '2' => '2']),
                    self::text('Ldap', 'BindDN', 'Bind DN', 'cn=svnmanage,ou=services,dc=example,dc=com'),
                    self::password('Ldap', 'BindPassword', 'Bind password'),
                    self::bool('Ldap', 'StartTLS', 'Use StartTLS'),
                    self::text('Users:ldap', 'BaseDN', 'User base DN', 'ou=people,dc=example,dc=com'),
                    self::text('Users:ldap', 'SearchFilter', 'User search filter', '(objectClass=person)', null, 'LdapUser'),
                    self::text('Users:ldap', 'Attributes', 'User attributes', 'uid', 'Comma separated. The first one is the Subversion user name.'),
                    self::text('Users:ldap', 'DisplayNameFormat', 'Display name format', '%givenName %sn (%uid)', 'Attributes used here must be listed above.'),
                    self::text('Groups:ldap', 'BaseDN', 'Group base DN', 'ou=groups,dc=example,dc=com'),
                    self::text('Groups:ldap', 'SearchFilter', 'Group search filter', '(objectClass=groupOfNames)', null, 'LdapGroup'),
                    self::text('Groups:ldap', 'Attributes', 'Group name attribute', 'cn'),
                    self::text('Groups:ldap', 'GroupsToUserAttribute', 'Group member attribute', 'member'),
                    self::text('Groups:ldap', 'GroupsToUserAttributeValue', 'Member value attribute', 'distinguishedName', 'The user attribute stored in the member attribute (e.g. distinguishedName or uid).'),
                    self::bool('Ldap', 'CacheEnabled', 'Cache users and groups', 'Serves users/groups from a snapshot refreshed on every synchronisation.'),
                    self::path('Ldap', 'CacheFile', 'Cache file', '', './data/ldap.cache.json'),
                    self::bool('Update:ldap', 'AutoRemoveUsers', 'Remove permissions of deleted users on sync'),
                    self::bool('Update:ldap', 'AutoRemoveGroups', 'Remove permissions of deleted groups on sync'),
                ],
            ],
            [
                'id' => 'ad',
                'title' => 'Active Directory',
                'icon' => 'server',
                'intro' => 'Used when a provider is set to "ad". Nested group membership is resolved automatically.',
                'fields' => [
                    self::text('Ad', 'DomainName', 'Domain name', 'corp.example.com', null, 'AdDomainControllerDetect'),
                    self::text('Ad', 'DomainController', 'Domain controllers', 'dc1.corp.example.com, dc2.corp.example.com', 'Comma separated.'),
                    self::text('Ad', 'BindUser', 'Bind user', 'svc-svn@corp.example.com', 'userPrincipalName, DN or sAMAccountName.', 'AdConnection'),
                    self::password('Ad', 'BindPassword', 'Bind password'),
                    self::bool('Ad', 'StartTLS', 'Use StartTLS'),
                    self::text('Groups:ad', 'Filter', 'Group name filter', 'SVN-*', 'Only groups matching this pattern are used (wildcard *).'),
                    self::bool('Groups:ad', 'StrictMode', 'Strict mode', 'Only list users who are members of a matching group.'),
                ],
            ],
            [
                'id' => 'interface',
                'title' => 'Interface',
                'icon' => 'shield',
                'intro' => 'Feature switches, browser links, language and backups.',
                'fields' => [
                    self::bool('GUI', 'RepositoryDeleteEnabled', 'Allow deleting repositories'),
                    self::bool('GUI', 'RepositoryDumpEnabled', 'Allow downloading repository dumps'),
                    self::bool('GUI', 'RepositoryLoadEnabled', 'Allow loading dump files into repositories'),
                    self::bool('GUI', 'AllowUpdateByGui', 'Allow synchronisation from the web interface', 'Otherwise run "bin/svnmanage update" from cron.'),
                    self::text('GUI', 'ApacheDirectoryListing', 'Apache directory listing URL', 'https://svn.example.com/repos/%1/%2', '%1 = repository, %2 = relative path.'),
                    self::text('GUI', 'CustomDirectoryListing', 'Repository browser URL (WebSVN, ViewVC…)', 'https://svn.example.com/websvn/listing.php?repname=%1&path=/%2'),
                    self::select('GUI', 'DefaultLocale', 'Default language', Translator::LOCALES),
                    self::path('Common', 'BackupFolder', 'Backup folder', '', './data/backup/'),
                    self::number('Common', 'BackupCount', 'Backups kept per file', '30'),
                ],
            ],
            [
                'id' => 'security',
                'title' => 'Security',
                'icon' => 'shield-check',
                'intro' => 'Who may reach the site, how people sign in, and how long sessions last.',
                'fields' => [
                    self::networks('Common', 'AllowedNetworks', 'Allowed networks', '203.0.113.0/24, 2001:db8::/32', 'Only these addresses or ranges can open svnmanage. Leave empty to allow everyone. Your current address must be included.'),
                    self::networks('Common', 'TrustedProxies', 'Trusted reverse proxies', '127.0.0.1, 10.0.0.0/8', 'Only requests from these addresses may set X-Forwarded-For and X-Forwarded-Proto.'),
                    self::select('Common', 'TwoFactor', 'Two-factor authentication', [
                        'optional' => 'Optional (each user decides)',
                        'admins' => 'Required for administrators',
                        'all' => 'Required for everyone',
                        'off' => 'Off',
                    ], 'Time-based one-time codes from an authenticator app (TOTP).'),
                    self::bool('Common', 'ForceHttps', 'Require HTTPS', 'Redirect plain HTTP to HTTPS. Behind a TLS proxy, list it under trusted proxies first.'),
                    self::bool('Common', 'HttpProxyAuth', 'Trust proxy authentication', 'Accept the user from REMOTE_USER / X-Forwarded-User. Only behind a trusted reverse proxy!'),
                    self::number('Common', 'SessionTimeout', 'Idle session timeout (seconds)', '3600'),
                    self::number('Common', 'SessionMaxLifetime', 'Maximum session length (seconds)', '43200'),
                    self::number('Common', 'MinPasswordLength', 'Minimum password length', '12'),
                    self::number('Common', 'LoginMaxAttempts', 'Failed logins per user and address before lock', '5'),
                    self::number('Common', 'LoginMaxAttemptsPerIp', 'Failed logins per address before lock', '20'),
                    self::number('Common', 'LoginLockSeconds', 'Login lock duration (seconds)', '900'),
                ],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function fields(): array
    {
        $out = [];
        foreach (self::groups() as $group) {
            foreach ($group['fields'] as $field) {
                $out[] = $field;
            }
        }

        return $out;
    }

    public static function name(array $field): string
    {
        return $field['section'] . '|' . $field['key'];
    }

    private static function select(string $s, string $k, string $label, array $options, ?string $hint = null, bool $essential = false): array
    {
        return ['section' => $s, 'key' => $k, 'label' => $label, 'type' => 'select', 'options' => $options, 'hint' => $hint, 'essential' => $essential];
    }

    private static function path(string $s, string $k, string $label, string $test, string $placeholder, ?string $hint = null): array
    {
        return ['section' => $s, 'key' => $k, 'label' => $label, 'type' => 'path', 'test' => $test === '' ? null : $test, 'placeholder' => $placeholder, 'hint' => $hint, 'essential' => $test !== ''];
    }

    private static function text(string $s, string $k, string $label, string $placeholder = '', ?string $hint = null, ?string $test = null): array
    {
        return ['section' => $s, 'key' => $k, 'label' => $label, 'type' => 'text', 'placeholder' => $placeholder, 'hint' => $hint, 'test' => $test];
    }

    private static function password(string $s, string $k, string $label): array
    {
        return ['section' => $s, 'key' => $k, 'label' => $label, 'type' => 'password', 'hint' => 'Leave empty to keep the current password.'];
    }

    private static function bool(string $s, string $k, string $label, ?string $hint = null): array
    {
        return ['section' => $s, 'key' => $k, 'label' => $label, 'type' => 'bool', 'hint' => $hint];
    }

    private static function networks(string $s, string $k, string $label, string $placeholder, string $hint): array
    {
        return ['section' => $s, 'key' => $k, 'label' => $label, 'type' => 'networks', 'placeholder' => $placeholder, 'hint' => $hint];
    }

    private static function number(string $s, string $k, string $label, string $placeholder): array
    {
        return ['section' => $s, 'key' => $k, 'label' => $label, 'type' => 'number', 'placeholder' => $placeholder];
    }
}
