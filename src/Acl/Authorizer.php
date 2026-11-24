<?php

declare(strict_types=1);

namespace SvnManage\Acl;

use SvnManage\Authz\Names;

/** Permission decisions for the current request's user. */
final class Authorizer
{
    /**
     * @param list<string> $roles
     * @param list<string> $managedPaths
     */
    public function __construct(
        public readonly bool $authEnabled,
        public readonly ?string $username,
        public readonly array $roles,
        public readonly array $managedPaths,
    ) {
    }

    public static function anonymous(bool $authEnabled): self
    {
        return new self($authEnabled, null, [], []);
    }

    public function isLoggedIn(): bool
    {
        return $this->username !== null;
    }

    public function can(string $module, string $action): bool
    {
        if (!$this->authEnabled) {
            return true;
        }
        if ($this->username === null) {
            return false;
        }

        return Acl::allows($this->roles, $module, $action, $this->managedPaths !== []);
    }

    public function isAdmin(): bool
    {
        return !$this->authEnabled || in_array(Acl::ADMINISTRATOR, $this->roles, true);
    }

    public function isProjectManager(): bool
    {
        return $this->managedPaths !== [];
    }

    /**
     * Project managers without a real access-path role only see/manage paths
     * inside their assigned scope.
     */
    public function isPathScoped(): bool
    {
        return $this->authEnabled
            && $this->isProjectManager()
            && !Acl::allows($this->roles, Acl::MOD_ACCESSPATH, Acl::VIEW);
    }

    public function canManagePath(string $path): bool
    {
        if (!$this->isPathScoped()) {
            return true;
        }
        foreach ($this->managedPaths as $scope) {
            if (Names::isWithin($path, $scope)) {
                return true;
            }
        }

        return false;
    }

    /** A path-scoped manager only sees repositories that contain one of their paths. */
    public function canSeeRepository(string $repository): bool
    {
        if (!$this->isPathScoped()) {
            return true;
        }
        foreach ($this->managedPaths as $scope) {
            $repo = Names::repositoryOfPath($scope);
            if ($repo === null || $repo === '' || $repo === $repository) {
                return true;
            }
        }

        return false;
    }

    /** The commit log spans the whole repository, so it needs access to all of it. */
    public function canReadHistory(string $repository): bool
    {
        if (!$this->isPathScoped()) {
            return true;
        }
        foreach ($this->managedPaths as $scope) {
            if ($scope === $repository . ':/' || $scope === '/') {
                return true;
            }
        }

        return false;
    }

    /**
     * Browsing a folder: allowed inside a managed path, or on the way down to one
     * (an ancestor), so the tree can be navigated without revealing siblings.
     */
    public function canBrowse(string $repository, string $relative): bool
    {
        if (!$this->isPathScoped()) {
            return true;
        }
        $relative = trim($relative, '/');
        $here = $repository . ':/' . $relative;
        foreach ($this->managedPaths as $scope) {
            $repo = Names::repositoryOfPath($scope);
            $candidate = $repo === null || $repo === '' ? '/' . $relative : $here;
            $scopeInRepo = $repo === null || $repo === '' ? $scope : ($repo === $repository ? $scope : null);
            if ($scopeInRepo === null) {
                continue;
            }
            if (Names::isWithin($candidate, $scopeInRepo) || Names::isWithin($scopeInRepo, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    public function filterPaths(array $paths): array
    {
        return array_values(array_filter($paths, $this->canManagePath(...)));
    }
}
