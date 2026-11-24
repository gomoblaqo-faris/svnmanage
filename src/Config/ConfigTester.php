<?php

declare(strict_types=1);

namespace SvnManage\Config;

use SvnManage\Directory\LdapDirectory;
use SvnManage\Directory\LdapOptions;
use SvnManage\Svn\ProcessRunner;

/** The "Test" buttons of the settings page. Returns [ok, message]. */
final class ConfigTester
{
    public const TESTS = [
        'SVNAuthFile' => ['Subversion', 'SVNAuthFile', 'file'],
        'SVNUserFile' => ['Users:passwd', 'SVNUserFile', 'file'],
        'SVNUserDigestFile' => ['Users:digest', 'SVNUserDigestFile', 'file'],
        'UserRoleAssignmentFile' => ['ACLManager', 'UserRoleAssignmentFile', 'file'],
        'SVNParentPath' => ['Repositories:svnclient', 'SVNParentPath', 'dir'],
        'SvnExecutable' => ['Repositories:svnclient', 'SvnExecutable', 'exe'],
        'SvnAdminExecutable' => ['Repositories:svnclient', 'SvnAdminExecutable', 'exe'],
        'SvnLookExecutable' => ['Repositories:svnclient', 'SvnLookExecutable', 'exe'],
        'LdapConnection' => ['Ldap', '', 'ldap-connection'],
        'LdapUser' => ['Users:ldap', '', 'ldap-users'],
        'LdapGroup' => ['Groups:ldap', '', 'ldap-groups'],
        'AdDomainControllerDetect' => ['Ad', '', 'ad-detect'],
        'AdConnection' => ['Ad', '', 'ad-connection'],
    ];

    public function __construct(private readonly Config $config)
    {
    }

    /** @return array{0: bool, 1: string} */
    public function run(string $test): array
    {
        if (!isset(self::TESTS[$test])) {
            return [false, 'Invalid request.'];
        }
        [$section, $key, $kind] = self::TESTS[$test];
        try {
            return match ($kind) {
                'file' => $this->file($this->config->path($section, $key)),
                'dir' => $this->dir($this->config->path($section, $key)),
                'exe' => $this->exe($this->config->string($section, $key, $test === 'SvnLookExecutable' ? 'svnlook' : '')),
                'ldap-connection' => $this->ldap(LdapOptions::fromLdapConfig($this->config), 'connection'),
                'ldap-users' => $this->ldap(LdapOptions::fromLdapConfig($this->config), 'users'),
                'ldap-groups' => $this->ldap(LdapOptions::fromLdapConfig($this->config), 'groups'),
                'ad-detect' => $this->detectDomainControllers($this->config->string('Ad', 'DomainName')),
                'ad-connection' => $this->ldap(LdapOptions::fromAdConfig($this->config), 'connection'),
                default => [false, 'Invalid request.'],
            };
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    /** @return array{0: bool, 1: string} */
    private function file(string $path): array
    {
        if ($path === '') {
            return [false, 'No path configured.'];
        }
        if (!file_exists($path)) {
            return is_writable(dirname($path))
                ? [false, 'The file does not exist (its folder is writable, so it can be created).']
                : [false, 'The file does not exist.'];
        }
        if (!is_file($path)) {
            return [false, 'The path is not a file.'];
        }

        return is_writable($path) ? [true, 'Test passed.'] : [false, 'The file exists but is not writable.'];
    }

    /** @return array{0: bool, 1: string} */
    private function dir(string $path): array
    {
        if ($path === '') {
            return [false, 'No path configured.'];
        }
        if (!is_dir($path)) {
            return [false, 'The folder does not exist.'];
        }

        return is_writable($path) ? [true, 'Test passed.'] : [false, 'The folder exists but is not writable.'];
    }

    /** @return array{0: bool, 1: string} */
    private function exe(string $exe): array
    {
        if ($exe === '') {
            return [false, 'No path configured.'];
        }
        if ((str_contains($exe, '/') || str_contains($exe, '\\'))) {
            if (!file_exists($exe)) {
                return [false, 'The file does not exist.'];
            }
            if (!is_executable($exe)) {
                return [false, 'The file exists but is not executable.'];
            }
        }
        $result = (new ProcessRunner(20))->run([$exe, '--version', '--quiet']);
        if (!$result->ok()) {
            return [false, 'The program could not be executed: ' . trim($result->stderr)];
        }

        return [true, 'Test passed. Version ' . trim($result->stdout)];
    }

    /** @return array{0: bool, 1: string} */
    private function ldap(LdapOptions $options, string $what): array
    {
        if (!LdapDirectory::available()) {
            return [false, 'PHP LDAP extension is not available.'];
        }
        $dir = new LdapDirectory($options);
        if ($what === 'connection') {
            return [true, 'Test passed. ' . $dir->test()];
        }
        if ($what === 'users') {
            $names = array_map(static fn ($u): string => $u->name, $dir->users());

            return [true, sprintf('Found %d users. %s', count($names), self::preview($names))];
        }
        $names = array_map(static fn ($g): string => $g->name, $dir->groups());

        return [true, sprintf('Found %d groups. %s', count($names), self::preview($names))];
    }

    /** @return array{0: bool, 1: string} */
    private function detectDomainControllers(string $domain): array
    {
        if (trim($domain) === '') {
            return [false, 'No domain name configured.'];
        }
        $records = @dns_get_record('_ldap._tcp.dc._msdcs.' . trim($domain), DNS_SRV);
        if (!is_array($records) || $records === []) {
            return [false, 'No Domain Controller found.'];
        }

        return [true, implode(', ', array_map(static fn (array $r): string => (string) $r['target'], $records))];
    }

    /** @param list<string> $names */
    private static function preview(array $names): string
    {
        $shown = array_slice($names, 0, 25);

        return implode(', ', $shown) . (count($names) > 25 ? ' …' : '');
    }
}
