<?php

declare(strict_types=1);

namespace SvnManage\Cli;

use SvnManage\Acl\Acl;
use SvnManage\Acl\RoleAssignments;
use SvnManage\App;
use SvnManage\Config\ConfigTester;
use SvnManage\Directory\SyncRunner;
use SvnManage\Security\ExposureCheck;
use SvnManage\Services;

/** bin/svnmanage — maintenance commands for cron and shell use. */
final class Console
{
    private const COMMANDS = [
        'update' => 'Synchronise LDAP/AD users and groups into the authz file (for cron). --dry-run previews.',
        'check' => 'Run every configuration test (files, folders, executables, directory) and the exposure checks. --docroot=<dir> names the web server\'s document root.',
        'set-admin' => 'Give <user> the Administrator role (recovers a locked-out installation).',
        'passwd' => 'Set the password of <user> (reads it from STDIN or prompts).',
        'setup-key' => 'Print the one-time key the setup wizard asks for.',
        '2fa-reset' => 'Turn off two-factor authentication for <user> (lost phone).',
        'version' => 'Show the svnmanage, PHP and Subversion versions.',
        'license' => 'Show the license.',
        'help' => 'Show this help.',
    ];

    /** @param resource $out @param resource $err @param resource $in */
    public function __construct(
        private readonly App $app,
        private $out = STDOUT,
        private $err = STDERR,
        private $in = STDIN,
    ) {
    }

    /** @param list<string> $argv without the script name */
    public function run(array $argv): int
    {
        $command = $argv[0] ?? 'help';
        $args = array_values(array_filter(array_slice($argv, 1), static fn (string $a): bool => !str_starts_with($a, '--')));
        $flags = array_values(array_filter($argv, static fn (string $a): bool => str_starts_with($a, '--')));
        try {
            return match ($command) {
                'update' => $this->update(in_array('--dry-run', $flags, true)),
                'check' => $this->check(self::option($flags, 'docroot')),
                'set-admin' => $this->setAdmin($args[0] ?? ''),
                'passwd' => $this->passwd($args[0] ?? ''),
                'setup-key' => $this->setupKey(),
                '2fa-reset' => $this->twoFactorReset($args[0] ?? ''),
                'version', '--version', '-V' => $this->version(),
                'license' => $this->license(),
                'help', '--help', '-h' => $this->help(),
                default => $this->unknown($command),
            };
        } catch (\Throwable $e) {
            $this->line($this->err, 'error: ' . $e->getMessage());

            return 1;
        }
    }

    private function services(): Services
    {
        return $this->app->boot();
    }

    private function update(bool $dryRun): int
    {
        $runner = new SyncRunner($this->services());
        $plan = $dryRun ? $runner->preview(true) : $runner->apply();
        foreach ($plan->messages as [$level, $text]) {
            $this->line($this->out, sprintf('[%s] %s', $level, $text));
        }
        $this->line($this->out, sprintf(
            '%s %d groups, %d memberships%s.',
            $dryRun ? 'Would sync' : 'Synced',
            $plan->groupCount,
            $plan->memberCount,
            $plan->changed ? '' : ' (no changes)',
        ));
        if (!$dryRun) {
            $this->services()->audit()->record('(cli)', '127.0.0.1', 'sync.run', 'cli', ['changed' => $plan->changed]);
        }

        return 0;
    }

    /** @param list<string> $docroots */
    private function check(array $docroots = []): int
    {
        $s = $this->services();
        $tests = ['SVNAuthFile', 'UserRoleAssignmentFile'];
        $userType = $s->type('UserEditProviderType');
        if ($userType === 'passwd' || $s->type('UserViewProviderType') === 'passwd') {
            $tests[] = 'SVNUserFile';
        }
        if ($userType === 'digest' || $s->type('UserViewProviderType') === 'digest') {
            $tests[] = 'SVNUserDigestFile';
        }
        if ($s->hasRepoView()) {
            array_push($tests, 'SVNParentPath', 'SvnExecutable', 'SvnAdminExecutable');
        }
        if ($s->directoryKind() === 'ldap') {
            array_push($tests, 'LdapConnection', 'LdapUser', 'LdapGroup');
        } elseif ($s->directoryKind() === 'ad') {
            $tests[] = 'AdConnection';
        }
        $tester = new ConfigTester($s->config);
        $failed = 0;
        foreach ($tests as $test) {
            [$ok, $message] = $tester->run($test);
            $failed += $ok ? 0 : 1;
            $this->line($this->out, sprintf('%s %-24s %s', $ok ? '✔' : '✘', $test, $message));
        }
        $warnings = $this->exposure($s, $docroots);
        $this->line($this->out, $failed === 0 ? 'All checks passed.' : sprintf('%d check(s) failed.', $failed));
        if ($warnings > 0) {
            $this->line($this->out, sprintf('%d security warning(s).', $warnings));
        }

        return $failed === 0 && $warnings === 0 ? 0 : 1;
    }

    /** @param list<string> $docroots @return int number of warnings printed */
    private function exposure(Services $s, array $docroots): int
    {
        $warnings = ExposureCheck::run($s->managedPaths(), [...$docroots, $s->appRoot . '/public']);
        foreach ($warnings as [$message, $args]) {
            $this->line($this->out, '⚠ ' . ExposureCheck::format($message, $args));
        }

        return count($warnings);
    }

    /** @param list<string> $flags @return list<string> the values of every --name=value */
    private static function option(array $flags, string $name): array
    {
        $prefix = '--' . $name . '=';

        return array_values(array_map(
            static fn (string $f): string => substr($f, strlen($prefix)),
            array_filter($flags, static fn (string $f): bool => str_starts_with($f, $prefix)),
        ));
    }

    private function twoFactorReset(string $user): int
    {
        if ($user === '') {
            throw new \InvalidArgumentException('Usage: svnmanage 2fa-reset <user>');
        }
        $s = $this->services();
        $s->twoFactor()->update(static fn ($f) => $f->without($user));
        $s->revocations()->revoke($user);
        $s->audit()->record('(cli)', '127.0.0.1', '2fa.reset', $user);
        $this->line($this->out, sprintf('Two-factor authentication is off for %s; their sessions were ended.', $user));

        return 0;
    }

    private function setAdmin(string $user): int
    {
        if ($user === '') {
            throw new \InvalidArgumentException('Usage: svnmanage set-admin <user>');
        }
        $s = $this->services();
        $s->roles()->update(static fn (RoleAssignments $r): RoleAssignments => $r->withRole($user, Acl::ADMINISTRATOR));
        $s->audit()->record('(cli)', '127.0.0.1', 'role.assign', $user, ['role' => Acl::ADMINISTRATOR]);
        $this->line($this->out, sprintf('%s is now an Administrator.', $user));
        if (!($s->users()?->exists($user) ?? false)) {
            $this->line($this->err, sprintf('warning: %s does not exist in the user provider yet.', $user));
        }

        return 0;
    }

    private function passwd(string $user): int
    {
        if ($user === '') {
            throw new \InvalidArgumentException('Usage: svnmanage passwd <user>   (password from STDIN)');
        }
        $s = $this->services();
        $editor = $s->userEditor() ?? throw new \RuntimeException('No user edit provider is configured.');
        $password = $this->readPassword();
        if (strlen($password) < $s->minPasswordLength()) {
            throw new \InvalidArgumentException(sprintf('The password must be at least %d characters long.', $s->minPasswordLength()));
        }
        $s->users()?->exists($user) ? $editor->changePassword($user, $password) : $editor->create($user, $password);
        $s->revocations()->revoke($user);
        $s->audit()->record('(cli)', '127.0.0.1', 'user.password', $user);
        $this->line($this->out, sprintf('Password of %s updated.', $user));

        return 0;
    }

    private function readPassword(): string
    {
        $interactive = function_exists('posix_isatty') && @posix_isatty($this->in);
        if ($interactive) {
            fwrite($this->out, 'New password: ');
            shell_exec('stty -echo 2>/dev/null');
        }
        $password = rtrim((string) fgets($this->in), "\r\n");
        if ($interactive) {
            shell_exec('stty echo 2>/dev/null');
            fwrite($this->out, "\n");
        }

        return $password;
    }

    private function setupKey(): int
    {
        $s = $this->services();
        if (!$s->needsSetup()) {
            $this->line($this->out, 'Setup is already complete; no key is needed.');

            return 0;
        }
        $this->line($this->out, $s->setupKey()->ensure());

        return 0;
    }

    private function version(): int
    {
        $svn = '';
        try {
            $svn = $this->services()->svnTools()->version();
        } catch (\Throwable) {
            $svn = 'not found';
        }
        $this->line($this->out, sprintf("svnmanage %s\nPHP %s\nSubversion %s", Services::VERSION, PHP_VERSION, $svn));

        return 0;
    }

    private function license(): int
    {
        $file = $this->app->appRoot . '/LICENSE';
        $this->line($this->out, is_file($file) ? (string) file_get_contents($file) : 'GPL-2.0-only');

        return 0;
    }

    private function help(): int
    {
        $this->line($this->out, "svnmanage " . Services::VERSION . " — Subversion administration\n\nUsage: bin/svnmanage <command> [args]\n");
        foreach (self::COMMANDS as $name => $desc) {
            $this->line($this->out, sprintf('  %-10s %s', $name, $desc));
        }
        $this->line($this->out, "\nThe data directory defaults to ./data (override with SVNMANAGE_DATA).");

        return 0;
    }

    private function unknown(string $command): int
    {
        $this->line($this->err, sprintf('Unknown command "%s". Run "svnmanage help".', $command));

        return 2;
    }

    /** @param resource $stream */
    private function line($stream, string $text): void
    {
        fwrite($stream, $text . "\n");
    }
}
