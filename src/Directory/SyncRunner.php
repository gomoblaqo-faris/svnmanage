<?php

declare(strict_types=1);

namespace SvnManage\Directory;

use SvnManage\Authz\AuthzFile;
use SvnManage\Services;
use SvnManage\Users\User;

/** Runs the directory → authz synchronisation (web "Synchronize" and CLI "update"). */
final class SyncRunner
{
    public function __construct(private readonly Services $services)
    {
    }

    /** Computes the plan against the current authz file without writing anything. */
    public function preview(bool $refreshCache = false): SyncPlan
    {
        [$groups, $users] = $this->snapshot($refreshCache);

        return $this->sync()->plan($this->services->authz()->load(), $groups, $users);
    }

    /** Applies the synchronisation; the plan is recomputed inside the file lock. */
    public function apply(): SyncPlan
    {
        [$groups, $users] = $this->snapshot(true);
        $plan = null;
        $sync = $this->sync();
        $this->services->authz()->update(static function (AuthzFile $authz) use ($sync, $groups, $users, &$plan): AuthzFile {
            $plan = $sync->plan($authz, $groups, $users);

            return $plan->authz;
        });

        return $plan ?? throw new \RuntimeException('Synchronisation failed.');
    }

    /** @return array{0: list<DirectoryGroup>|null, 1: list<string>} */
    private function snapshot(bool $refreshCache): array
    {
        $directory = $this->services->directory() ?? throw new \RuntimeException('No LDAP or Active Directory provider is configured.');
        if ($refreshCache && $directory instanceof CachedDirectory) {
            $directory->refresh();
        }
        $groupsFromDirectory = in_array($this->services->groupSource(), ['ldap', 'ad'], true);
        $groups = $groupsFromDirectory ? $directory->groups() : null;
        $users = array_map(static fn (User $u): string => $u->name, $this->services->users()?->users() ?? []);

        return [$groups, $users];
    }

    private function sync(): DirectorySync
    {
        return new DirectorySync(
            $this->services->config->bool('Update:ldap', 'AutoRemoveUsers', true),
            $this->services->config->bool('Update:ldap', 'AutoRemoveGroups', true),
        );
    }
}
