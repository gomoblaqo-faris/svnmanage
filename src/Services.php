<?php

declare(strict_types=1);

namespace SvnManage;

use SvnManage\Audit\AuditLog;
use SvnManage\Config\Config;
use SvnManage\Directory\CachedDirectory;
use SvnManage\Directory\Directory;
use SvnManage\Directory\LdapDirectory;
use SvnManage\Directory\LdapOptions;
use SvnManage\Security\ManagedPath;
use SvnManage\Security\SetupKey;
use SvnManage\Store\AuthzStore;
use SvnManage\Store\RoleStore;
use SvnManage\Support\FileStore;
use SvnManage\Svn\ProcessRunner;
use SvnManage\Svn\RepositoryParent;
use SvnManage\Svn\RepositoryService;
use SvnManage\Svn\SvnTools;
use SvnManage\Users\DigestUsers;
use SvnManage\Users\DirectoryUsers;
use SvnManage\Users\PasswdUsers;
use SvnManage\Users\UserEditor;
use SvnManage\Users\UserProvider;

/**
 * Lazily builds every collaborator from config.ini.
 * Provider keys are identical to iF.SVNAdmin's [Engine:Providers].
 */
final class Services
{
    public const VERSION = '2.0.0';
    private const P = 'Engine:Providers';

    private ?FileStore $files = null;
    private ?AuthzStore $authz = null;
    private ?RoleStore $roles = null;
    private ?\SvnManage\Security\TwoFactorStore $twoFactor = null;
    private ?UserProvider $users = null;
    private UserEditor|false|null $editor = null;
    private Directory|false|null $directory = null;
    private ?RepositoryService $repos = null;

    public function __construct(
        public readonly Config $config,
        public readonly string $appRoot,
        public readonly string $dataDir,
    ) {
    }

    public function withConfig(Config $config): self
    {
        return new self($config, $this->appRoot, $this->dataDir);
    }

    // ------------------------------------------------------------ providers

    public function type(string $key, string $default = 'off'): string
    {
        return strtolower($this->config->string(self::P, $key, $default));
    }

    public function authEnabled(): bool
    {
        // Only the exact value "off" disables login; anything else (typos included) fails closed.
        return $this->type('AuthenticationStatus', 'basic') !== 'off';
    }

    public function hasUserView(): bool
    {
        return in_array($this->type('UserViewProviderType'), ['passwd', 'digest', 'ldap', 'ad'], true);
    }

    public function hasUserEdit(): bool
    {
        return in_array($this->type('UserEditProviderType'), ['passwd', 'digest'], true);
    }

    public function groupSource(): string
    {
        $type = $this->type('GroupViewProviderType');

        return in_array($type, ['svnauthfile', 'ldap', 'ad'], true) ? $type : 'off';
    }

    public function hasGroupView(): bool
    {
        return $this->groupSource() !== 'off';
    }

    /** Groups are only editable when the authz file is their source of truth. */
    public function hasGroupEdit(): bool
    {
        return $this->groupSource() === 'svnauthfile' && $this->type('GroupEditProviderType') === 'svnauthfile';
    }

    public function hasAccessPathView(): bool
    {
        return $this->type('AccessPathViewProviderType', 'svnauthfile') === 'svnauthfile';
    }

    public function hasAccessPathEdit(): bool
    {
        return $this->hasAccessPathView() && $this->type('AccessPathEditProviderType', 'svnauthfile') === 'svnauthfile';
    }

    public function hasRepoView(): bool
    {
        return $this->type('RepositoryViewProviderType') === 'svnclient';
    }

    public function hasRepoEdit(): bool
    {
        return $this->hasRepoView() && $this->type('RepositoryEditProviderType') === 'svnclient';
    }

    /** A directory (LDAP/AD) is configured, so a synchronisation can run. */
    public function isUpdateable(): bool
    {
        return $this->directoryKind() !== null;
    }

    public function directoryKind(): ?string
    {
        foreach (['UserViewProviderType', 'GroupViewProviderType'] as $key) {
            $type = $this->type($key);
            if ($type === 'ldap' || $type === 'ad') {
                return $type;
            }
        }

        return null;
    }

    // ------------------------------------------------------------- services

    public function files(): FileStore
    {
        return $this->files ??= new FileStore(
            $this->config->path('Common', 'BackupFolder', './data/backup'),
            $this->config->int('Common', 'BackupCount', 30),
        );
    }

    public function authz(): AuthzStore
    {
        return $this->authz ??= new AuthzStore($this->files(), $this->config->path('Subversion', 'SVNAuthFile'));
    }

    public function roles(): RoleStore
    {
        return $this->roles ??= new RoleStore(
            $this->files(),
            $this->config->path('ACLManager', 'UserRoleAssignmentFile', './data/userroleassignments.ini'),
        );
    }

    public function users(): ?UserProvider
    {
        if ($this->users !== null) {
            return $this->users;
        }

        return $this->users = match ($this->type('UserViewProviderType')) {
            'passwd' => $this->passwd(),
            'digest' => $this->digest(),
            'ldap', 'ad' => ($dir = $this->directory()) !== null ? new DirectoryUsers($dir, $this->type('UserViewProviderType')) : null,
            default => null,
        };
    }

    public function userEditor(): ?UserEditor
    {
        if ($this->editor === null) {
            $view = $this->users();
            $type = $this->type('UserEditProviderType');
            $this->editor = match (true) {
                $type === 'passwd' => $view instanceof PasswdUsers ? $view : $this->passwd(),
                $type === 'digest' => $view instanceof DigestUsers ? $view : $this->digest(),
                default => false,
            };
        }

        return $this->editor === false ? null : $this->editor;
    }

    public function directory(): ?Directory
    {
        if ($this->directory === null) {
            $kind = $this->directoryKind();
            if ($kind === null) {
                $this->directory = false;
            } else {
                $options = $kind === 'ad' ? LdapOptions::fromAdConfig($this->config) : LdapOptions::fromLdapConfig($this->config);
                $ldap = new LdapDirectory($options);
                $this->directory = $this->config->bool('Ldap', 'CacheEnabled')
                    ? new CachedDirectory($ldap, $this->config->path('Ldap', 'CacheFile', './data/ldap.cache.json'))
                    : $ldap;
            }
        }

        return $this->directory === false ? null : $this->directory;
    }

    public function svnTools(): SvnTools
    {
        $svn = $this->config->string('Repositories:svnclient', 'SvnExecutable', 'svn');
        $admin = $this->config->string('Repositories:svnclient', 'SvnAdminExecutable', 'svnadmin');
        $look = $this->config->string('Repositories:svnclient', 'SvnLookExecutable');
        if ($look === '') {
            $look = str_contains($admin, '/') || str_contains($admin, '\\')
                ? preg_replace('/svnadmin(\.exe)?$/i', 'svnlook$1', $admin) ?? 'svnlook'
                : 'svnlook';
        }

        return new SvnTools(new ProcessRunner($this->config->int('Repositories:svnclient', 'Timeout', 600)), $svn, $admin, $look);
    }

    public function repositories(): ?RepositoryService
    {
        if (!$this->hasRepoView()) {
            return null;
        }

        return $this->repos ??= new RepositoryService($this->repositoryParents(), $this->svnTools(), $this->dataDir . '/cache');
    }

    /** @return list<RepositoryParent> */
    public function repositoryParents(): array
    {
        $parents = [new RepositoryParent(
            0,
            $this->config->path('Repositories:svnclient', 'SVNParentPath'),
            $this->config->string('Repositories:svnclient', 'Description', 'Repositories'),
        )];
        for ($i = 1; $i < 100; $i++) {
            $section = 'Repositories:svnclient:' . $i;
            $path = $this->config->path($section, 'SVNParentPath');
            if ($path === '') {
                break;
            }
            $parents[] = new RepositoryParent($i, $path, $this->config->string($section, 'Description', 'Repositories #' . $i));
        }

        return $parents;
    }

    public function audit(): AuditLog
    {
        return new AuditLog($this->dataDir . '/audit.log');
    }

    /** Per-address limit across all user names (password spraying, credential stuffing). */
    /** Signs the stateless login-form token with a per-installation key (data/app.key). */
    public function formTokens(): \SvnManage\Security\FormToken
    {
        return new \SvnManage\Security\FormToken(\SvnManage\Security\AppKey::load($this->dataDir . '/app.key'));
    }

    /**
     * Throttle for one kind of secret check: login, 2fa, setup, passwd. The per-account
     * ceiling (any address) is what stops botnets and IPv6 address rotation.
     */
    public function throttle(string $scope): \SvnManage\Security\Throttle
    {
        $perKey = max(1, $this->config->int('Common', 'LoginMaxAttempts', 5));
        $perAddress = max(1, $this->config->int('Common', 'LoginMaxAttemptsPerIp', 20));

        return new \SvnManage\Security\Throttle(
            $this->dataDir . '/ratelimit',
            $scope,
            $perKey,
            $perAddress,
            match ($scope) {
                'login' => max(10 * $perKey, $perAddress),
                'setup' => 100,
                // 80-bit codes cannot be guessed: no account-wide lock that others could trigger.
                '2fa-recovery' => PHP_INT_MAX,
                default => 2 * $perKey,
            },
            max(60, $this->config->int('Common', 'LoginLockSeconds', 900)),
        );
    }

    /**
     * Every file and folder svnmanage reads or writes, for the exposure checks.
     *
     * @return list<ManagedPath>
     */
    public function managedPaths(): array
    {
        $p = static fn (string $label, string $path, bool $secret = false): ManagedPath
            => new ManagedPath($label, $path, $secret);
        $paths = [
            $p('Authz file', $this->config->path('Subversion', 'SVNAuthFile')),
            $p('User file', $this->config->path('Users:passwd', 'SVNUserFile'), true),
            $p('Digest user file', $this->config->path('Users:digest', 'SVNUserDigestFile'), true),
            $p('Role file', $this->config->path('ACLManager', 'UserRoleAssignmentFile')),
            $p('Data folder', $this->dataDir),
            $p('Backup folder', $this->config->path('Common', 'BackupFolder', './data/backup')),
            $p('Configuration file', $this->configPath(), true),
            $p('Two-factor secrets', $this->dataDir . '/twofactor.ini', true),
            $p('App key', $this->dataDir . '/app.key', true),
        ];
        foreach ($this->repositoryParents() as $parent) {
            $paths[] = $p('Repository folder', $parent->path);
        }

        return $paths;
    }

    /** First start, or authentication enabled without any Administrator. */
    public function needsSetup(): bool
    {
        if ($this->config->string('Common', 'FirstStart', '0') === '1') {
            return true;
        }
        if (!$this->authEnabled()) {
            return false;
        }
        try {
            return !$this->roles()->load()->hasAdmin();
        } catch (\Throwable $e) {
            // Fail closed: a broken role file must never re-open the wizard.
            error_log('[svnmanage] cannot read role assignments: ' . $e->getMessage());

            return false;
        }
    }

    public function twoFactor(): \SvnManage\Security\TwoFactorStore
    {
        return $this->twoFactor ??= new \SvnManage\Security\TwoFactorStore($this->files(), $this->dataDir . '/twofactor.ini');
    }

    public function twoFactorRequiredFor(string $user): bool
    {
        return match ($this->twoFactorPolicy()) {
            'all' => true,
            'admins' => $this->roles()->load()->hasRole($user, \SvnManage\Acl\Acl::ADMINISTRATOR),
            default => false,
        };
    }

    /** off | optional | admins | all */
    public function twoFactorPolicy(): string
    {
        $policy = $this->config->string('Common', 'TwoFactor', 'optional');

        return in_array($policy, ['off', 'optional', 'admins', 'all'], true) ? $policy : 'optional';
    }

    public function revocations(): \SvnManage\Security\SessionRevocations
    {
        return new \SvnManage\Security\SessionRevocations($this->dataDir . '/revocations.json');
    }

    public function setupKey(): SetupKey
    {
        return new SetupKey($this->dataDir . '/setup.key');
    }

    public function configPath(): string
    {
        return $this->dataDir . '/config.ini';
    }

    public function minPasswordLength(): int
    {
        return max(1, $this->config->int('Common', 'MinPasswordLength', 8));
    }

    private function passwd(): PasswdUsers
    {
        return new PasswdUsers(
            $this->files(),
            $this->config->path('Users:passwd', 'SVNUserFile'),
            $this->config->string('Users:passwd', 'HashAlgorithm', 'bcrypt'),
        );
    }

    private function digest(): DigestUsers
    {
        return new DigestUsers(
            $this->files(),
            $this->config->path('Users:digest', 'SVNUserDigestFile'),
            $this->config->string('Users:digest', 'SVNDigestRealm', 'SVN Privat'),
        );
    }
}
