<?php

declare(strict_types=1);

namespace SvnManage\Controllers;

use SvnManage\Authz\AuthzFile;
use SvnManage\Authz\Permission;
use SvnManage\Users\User;

/** Permission granting shared by the access-path page and the bulk permission page. */
trait GrantsPermissions
{
    /**
     * @param list<string> $paths
     * @param list<string> $tokens
     */
    protected function grantMany(array $paths, array $tokens, Permission $permission): void
    {
        if ($paths === [] || $tokens === []) {
            $this->error('Choose at least one access path and one user or group.');

            return;
        }
        $done = 0;
        foreach ($paths as $path) {
            if (!$this->auth->canManagePath($path)) {
                $this->error('Access denied: %0', [$path]);
                continue;
            }
            try {
                $this->services->authz()->update(static function (AuthzFile $a) use ($path, $tokens, $permission): AuthzFile {
                    foreach ($tokens as $t) {
                        $a = $a->withRule($path, $t, $permission);
                    }

                    return $a;
                });
                $this->audit('permission.grant', $path, ['members' => implode(',', $tokens), 'permission' => $permission->inputValue()]);
                $done++;
            } catch (\Throwable $e) {
                $this->fail($e);
            }
        }
        if ($done > 0) {
            $this->success('Granted %0 to %1 members on %2 paths.', [$this->tr($permission->label()), count($tokens), $done]);
        }
    }

    /** @return list<string> users, "@group" tokens and optionally "*" */
    protected function tokens(): array
    {
        $users = $this->request->inputList('users');
        $groups = array_map(static fn (string $g): string => '@' . ltrim($g, '@'), $this->request->inputList('groups'));
        $special = array_values(array_intersect($this->request->inputList('special'), ['*', '$authenticated', '$anonymous']));

        return array_values(array_unique([...$special, ...$groups, ...$users]));
    }

    /** @return list<string> */
    protected function userNames(): array
    {
        try {
            return array_values(array_filter(
                array_map(static fn (User $u): string => $u->name, $this->services->users()?->users() ?? []),
                static fn (string $n): bool => $n !== '*',
            ));
        } catch (\Throwable $e) {
            $this->info($e->getMessage());

            return [];
        }
    }
}
