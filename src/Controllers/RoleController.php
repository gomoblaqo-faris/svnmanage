<?php

declare(strict_types=1);

namespace SvnManage\Controllers;

use SvnManage\Acl\Acl;
use SvnManage\Http\Response;
use SvnManage\Users\User;

final class RoleController extends Controller
{
    public function index(): Response
    {
        $this->authorize(Acl::MOD_ROLE, Acl::VIEW);
        $this->requireActive($this->services->authEnabled());
        $assignments = $this->services->roles()->load();
        $roles = [];
        foreach (Acl::roleNames() as $role) {
            $roles[] = [
                'name' => $role,
                'description' => Acl::description($role),
                'parents' => Acl::parents($role),
                'capabilities' => Acl::capabilities($role),
                'members' => $assignments->usersWithRole($role),
            ];
        }
        $users = [];
        try {
            $users = array_map(static fn (User $u): string => $u->name, $this->services->users()?->users() ?? []);
        } catch (\Throwable $e) {
            $this->info($this->errorText($e));
        }
        $canAssign = $this->auth->can(Acl::MOD_ROLE, Acl::ASSIGN);

        return $this->page('roles/index', [
            'roles' => $roles,
            'managers' => $assignments->allManagers(),
            'users' => $users,
            'canAssign' => $canAssign,
            'assignable' => array_values(array_filter(
                Acl::roleNames(),
                fn (string $r): bool => $canAssign && ($r !== Acl::ADMINISTRATOR || $this->auth->can(Acl::MOD_ROLE, Acl::ASSIGN_ADMIN)),
            )),
        ], 'Roles', 'roles');
    }
}
