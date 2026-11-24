<?php

declare(strict_types=1);

namespace SvnManage\Controllers;

use SvnManage\Acl\Acl;
use SvnManage\Acl\RoleAssignments;
use SvnManage\Authz\AuthzFile;
use SvnManage\Http\HttpException;
use SvnManage\Http\Response;
use SvnManage\Users\User;
use SvnManage\Users\UserProvider;

final class UserController extends Controller
{
    private const PER_PAGE = 50;

    public function index(): Response
    {
        $this->authorize(Acl::MOD_USER, Acl::VIEW);
        $q = $this->request->query('q');
        $users = $this->filter($this->provider()->users(), $q, static fn (User $u): string => $u->name . ' ' . $u->displayName);
        $total = count($users);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int) $this->request->query('page', '1')));
        $slice = array_slice($users, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        $roles = $this->services->authEnabled() ? $this->services->roles()->load() : null;
        $authz = $this->authzOrNull();

        return $this->page('users/index', [
            'users' => $slice,
            'q' => $q,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'roles' => $roles,
            'authz' => $authz,
            'canAdd' => $this->services->hasUserEdit() && $this->auth->can(Acl::MOD_USER, Acl::ADD),
            'canDelete' => $this->services->hasUserEdit() && $this->auth->can(Acl::MOD_USER, Acl::DELETE),
            'canAssignRole' => $this->services->authEnabled() && $this->auth->can(Acl::MOD_ROLE, Acl::ASSIGN),
            'assignableRoles' => $this->assignableRoles(),
        ], 'Users', 'users');
    }

    public function create(): Response
    {
        $this->authorize(Acl::MOD_USER, Acl::ADD);
        $this->requireActive($this->services->hasUserEdit());

        return $this->page('users/create', [
            'minLength' => $this->services->minPasswordLength(),
            'old' => $this->ctx->session->get('old_user', ''),
            'roles' => $this->services->authEnabled() && $this->auth->can(Acl::MOD_ROLE, Acl::ASSIGN) ? $this->assignableRoles() : [],
            'groups' => $this->services->hasGroupEdit() && $this->auth->can(Acl::MOD_GROUP, Acl::ASSIGN) ? $this->authzOrEmpty()->groupNames() : [],
        ], 'New user', 'users.new');
    }

    public function store(): Response
    {
        $this->authorize(Acl::MOD_USER, Acl::ADD);
        $editor = $this->services->userEditor();
        $this->requireActive($editor !== null);
        $name = trim($this->request->input('username'));
        $this->ctx->session->set('old_user', $name);
        try {
            $this->assertPassword($this->request->input('password'), $this->request->input('password2'));
            $editor->create($name, $this->request->input('password'));
        } catch (\Throwable $e) {
            $this->fail($e);

            return $this->redirect('/users/new');
        }
        $this->ctx->session->remove('old_user');
        $this->audit('user.create', $name);
        $this->success('The user %0 has been created successfully.', [$name]);

        $roles = array_intersect($this->request->inputList('roles'), $this->assignableRoles());
        if ($roles !== []) {
            $this->tryRoles(static function (RoleAssignments $r) use ($name, $roles): RoleAssignments {
                foreach ($roles as $role) {
                    $r = $r->withRole($name, $role);
                }

                return $r;
            });
        }
        $groups = $this->request->inputList('groups');
        if ($groups !== [] && $this->services->hasGroupEdit() && $this->auth->can(Acl::MOD_GROUP, Acl::ASSIGN)) {
            $this->tryAuthz(static function (AuthzFile $a) use ($name, $groups): AuthzFile {
                foreach ($groups as $g) {
                    $a = $a->withGroupMember($g, $name);
                }

                return $a;
            });
        }

        return $this->redirect('/users/' . rawurlencode($name));
    }

    public function delete(): Response
    {
        $this->authorize(Acl::MOD_USER, Acl::DELETE);
        $editor = $this->services->userEditor();
        $this->requireActive($editor !== null);
        $names = $this->request->inputList('selected_users');
        if ($names === []) {
            $this->error('You have to select at least one user.');

            return $this->back('/users');
        }
        $this->each($names, function (string $name) use ($editor): void {
            if ($name === $this->auth->username) {
                throw new \InvalidArgumentException('You cannot delete your own account.');
            }
            $this->guardAdminTarget($name);
            $this->guardLastAdmin($name, Acl::ADMINISTRATOR);
            $editor->delete($name);
            $this->cascadeDelete($name);
            $this->audit('user.delete', $name);
            $this->success('The user %0 has been deleted.', [$name]);
        });

        return $this->redirect('/users');
    }

    public function bulkRole(): Response
    {
        $this->authorize(Acl::MOD_ROLE, Acl::ASSIGN);
        $role = $this->request->input('role');
        $names = $this->request->inputList('selected_users');
        if (!in_array($role, $this->assignableRoles(), true) || $names === []) {
            $this->error('Choose a role and at least one user.');

            return $this->back('/users');
        }
        $this->tryRoles(function (RoleAssignments $r) use ($names, $role): RoleAssignments {
            foreach ($names as $n) {
                $r = $r->withRole($n, $role);
                $this->audit('role.assign', $n, ['role' => $role]);
            }

            return $r;
        }, ['Assigned role %0 to %1 users.', [$role, count($names)]]);

        return $this->back('/users');
    }

    public function show(string $name): Response
    {
        $isSelf = $name === $this->auth->username;
        if (!$isSelf) {
            $this->authorize(Acl::MOD_USER, Acl::VIEW);
        } else {
            $this->requireLogin();
        }
        $provider = $this->provider();
        $exists = $provider->exists($name);
        $authz = $this->authzOrNull();
        if (!$exists && ($authz === null || !in_array($name, $authz->usersInRules(), true))) {
            throw HttpException::notFound('The user does not exist.');
        }
        $roles = $this->services->authEnabled() ? $this->services->roles()->load() : null;
        $permissions = $authz?->effectivePermissions($name) ?? [];
        $permissions = array_values(array_filter($permissions, fn (array $p): bool => $this->auth->canManagePath($p['path'])));
        $user = array_values(array_filter($provider->users(), static fn (User $u): bool => $u->name === $name))[0] ?? new User($name);
        $protected = !$isSelf && $roles !== null && $roles->hasRole($name, Acl::ADMINISTRATOR) && !$this->auth->can(Acl::MOD_ROLE, Acl::ASSIGN_ADMIN);

        return $this->page('users/show', [
            'user' => $user,
            'exists' => $exists,
            'isSelf' => $isSelf,
            'groups' => $authz?->effectiveGroupsOfUser($name) ?? [],
            'allGroups' => $authz?->groupNames() ?? [],
            'permissions' => $permissions,
            'userRoles' => $roles?->rolesOf($name) ?? [],
            'managed' => $roles?->managedPaths($name) ?? [],
            'assignableRoles' => $this->assignableRoles(),
            'canGroups' => $this->services->hasGroupEdit() && $this->auth->can(Acl::MOD_GROUP, Acl::ASSIGN),
            'canUngroup' => $this->services->hasGroupEdit() && $this->auth->can(Acl::MOD_GROUP, Acl::UNASSIGN),
            'canRoles' => $roles !== null && $this->auth->can(Acl::MOD_ROLE, Acl::ASSIGN),
            'canUnrole' => $roles !== null && $this->auth->can(Acl::MOD_ROLE, Acl::UNASSIGN),
            'canRevoke' => $this->services->hasAccessPathEdit() && $this->auth->can(Acl::MOD_ACCESSPATH, Acl::UNASSIGN),
            'canPassword' => $exists && !$protected && $this->services->hasUserEdit() && ($isSelf ? $this->auth->can(Acl::MOD_USER, Acl::CHANGEPASS) : $this->auth->can(Acl::MOD_USER, Acl::CHANGEPASS_OTHER)),
            'canDelete' => $exists && !$isSelf && !$protected && $this->services->hasUserEdit() && $this->auth->can(Acl::MOD_USER, Acl::DELETE),
            'twoFactor' => $this->services->authEnabled() && $this->services->twoFactor()->load()->isEnabled($name),
            'canResetTwoFactor' => !$isSelf && !$protected && $this->services->authEnabled() && $this->auth->can(Acl::MOD_USER, Acl::CHANGEPASS_OTHER),
        ], $name, $isSelf ? 'account' : 'users');
    }

    public function addGroups(string $name): Response
    {
        $this->authorize(Acl::MOD_GROUP, Acl::ASSIGN);
        $this->requireActive($this->services->hasGroupEdit());
        $groups = $this->request->inputList('groups');
        $this->each($groups, function (string $g) use ($name): void {
            $this->services->authz()->update(static fn (AuthzFile $a): AuthzFile => $a->withGroupMember($g, $name));
            $this->audit('group.member.add', $g, ['member' => $name]);
            $this->success('Added %0 to group %1.', [$name, $g]);
        });

        return $this->toUser($name);
    }

    public function removeGroups(string $name): Response
    {
        $this->authorize(Acl::MOD_GROUP, Acl::UNASSIGN);
        $this->requireActive($this->services->hasGroupEdit());
        $this->each($this->request->inputList('groups'), function (string $g) use ($name): void {
            $this->services->authz()->update(static fn (AuthzFile $a): AuthzFile => $a->withoutGroupMember($g, $name));
            $this->audit('group.member.remove', $g, ['member' => $name]);
            $this->success('Removed %0 from group %1.', [$name, $g]);
        });

        return $this->toUser($name);
    }

    public function addRoles(string $name): Response
    {
        $this->authorize(Acl::MOD_ROLE, Acl::ASSIGN);
        $roles = array_intersect($this->request->inputList('roles'), $this->assignableRoles());
        $this->each($roles, function (string $role) use ($name): void {
            $this->services->roles()->update(static fn (RoleAssignments $r): RoleAssignments => $r->withRole($name, $role));
            $this->audit('role.assign', $name, ['role' => $role]);
            $this->success('Assigned role %0 to %1.', [$role, $name]);
        });

        return $this->toUser($name);
    }

    public function removeRoles(string $name): Response
    {
        $this->authorize(Acl::MOD_ROLE, Acl::UNASSIGN);
        $this->each($this->request->inputList('roles'), function (string $role) use ($name): void {
            if ($role === Acl::ADMINISTRATOR && !$this->auth->can(Acl::MOD_ROLE, Acl::UNASSIGN_ADMIN)) {
                throw HttpException::forbidden();
            }
            $this->guardLastAdmin($name, $role);
            $this->services->roles()->update(static fn (RoleAssignments $r): RoleAssignments => $r->withoutRole($name, $role));
            $this->audit('role.unassign', $name, ['role' => $role]);
            $this->success('Removed role %0 from %1.', [$role, $name]);
        });

        return $this->toUser($name);
    }

    public function removePermissions(string $name): Response
    {
        $this->authorize(Acl::MOD_ACCESSPATH, Acl::UNASSIGN);
        $this->requireActive($this->services->hasAccessPathEdit());
        $this->each($this->request->inputList('paths'), function (string $path) use ($name): void {
            if (!$this->auth->canManagePath($path)) {
                throw HttpException::forbidden();
            }
            $this->services->authz()->update(static fn (AuthzFile $a): AuthzFile => $a->withoutRule($path, $name));
            $this->audit('permission.revoke', $path, ['member' => $name]);
            $this->success('Removed permission of %0 on %1.', [$name, $path]);
        });

        return $this->toUser($name);
    }

    public function removeManaged(string $name): Response
    {
        $this->authorize(Acl::MOD_ACCESSPATH, Acl::UNASSIGN);
        $this->requireActive($this->services->authEnabled() && !$this->auth->isPathScoped());
        $this->each($this->request->inputList('paths'), function (string $path) use ($name): void {
            $this->services->roles()->update(static fn (RoleAssignments $r): RoleAssignments => $r->withoutManagedPath($name, $path));
            $this->audit('manager.unassign', $path, ['member' => $name]);
            $this->success('%0 no longer manages %1.', [$name, $path]);
        });

        return $this->toUser($name);
    }

    public function ownPassword(): Response
    {
        $this->requireLogin();
        $name = (string) $this->auth->username;
        $this->requireActive($this->services->hasUserEdit());

        return $this->passwordForm($name);
    }

    public function passwordForm(string $name): Response
    {
        $isSelf = $this->guardPassword($name);

        return $this->page('users/password', [
            'name' => $name,
            'isSelf' => $isSelf,
            'minLength' => $this->services->minPasswordLength(),
        ], $this->tr('Change password'), $isSelf ? 'account' : 'users');
    }

    public function password(string $name): Response
    {
        $isSelf = $this->guardPassword($name);
        $editor = $this->services->userEditor();
        $this->requireActive($editor !== null);
        $target = $isSelf ? '/account/password' : '/users/' . rawurlencode($name) . '/password';
        try {
            if ($isSelf && $this->services->authEnabled()) {
                $this->checkCurrentPassword($name);
            }
            $this->assertPassword($this->request->input('password'), $this->request->input('password2'));
            $editor->changePassword($name, $this->request->input('password'));
            // Every other session of this user ends; the one making the change stays.
            $this->services->revocations()->revoke($name);
            if ($isSelf) {
                $this->ctx->session->set('login_at', microtime(true));
            }
        } catch (\Throwable $e) {
            $this->fail($e);

            return $this->redirect($target);
        }
        $this->audit('user.password', $name);
        $this->success('Password changed successfully.');

        return $this->redirect($isSelf ? '/' : '/users/' . rawurlencode($name));
    }

    // ------------------------------------------------------------- helpers

    private function provider(): UserProvider
    {
        $provider = $this->services->users();
        $this->requireActive($provider !== null);

        return $provider;
    }

    private function guardPassword(string $name): bool
    {
        $isSelf = $name === $this->auth->username;
        $this->authorize(Acl::MOD_USER, $isSelf ? Acl::CHANGEPASS : Acl::CHANGEPASS_OTHER);
        $this->requireActive($this->services->hasUserEdit());
        if (!$this->provider()->exists($name)) {
            throw HttpException::notFound('The user does not exist.');
        }
        if (!$isSelf) {
            $this->guardAdminTarget($name);
        }

        return $isSelf;
    }

    /** @return list<string> roles the current user may hand out */
    private function assignableRoles(): array
    {
        if (!$this->services->authEnabled() || !$this->auth->can(Acl::MOD_ROLE, Acl::ASSIGN)) {
            return [];
        }

        return array_values(array_filter(
            Acl::roleNames(),
            fn (string $r): bool => $r !== Acl::ADMINISTRATOR || $this->auth->can(Acl::MOD_ROLE, Acl::ASSIGN_ADMIN),
        ));
    }

    /** The "current password" field is a password oracle, so it shares the login throttle. */
    private function checkCurrentPassword(string $name): void
    {
        $throttle = $this->services->throttle('passwd');
        $wait = $throttle->reserve($name, $this->request->ip());
        if ($wait > 0) {
            throw new \InvalidArgumentException($this->tr('Too many failed login attempts. Try again in %0 minutes.', [(int) ceil($wait / 60)]));
        }
        if (!$this->provider()->authenticate($name, $this->request->input('current'))) {
            $this->audit('password.check.failed', $name);
            throw new \InvalidArgumentException('The current password is not correct.');
        }
        $throttle->succeeded($name, $this->request->ip());
    }

    /** Only administrators may change or delete another administrator's account. */
    private function guardAdminTarget(string $name): void
    {
        if (!$this->services->authEnabled() || $this->auth->can(Acl::MOD_ROLE, Acl::ASSIGN_ADMIN)) {
            return;
        }
        if ($this->services->roles()->load()->hasRole($name, Acl::ADMINISTRATOR)) {
            throw HttpException::forbidden('Only administrators can change administrator accounts.');
        }
    }

    private function guardLastAdmin(string $name, string $role): void
    {
        if ($role !== Acl::ADMINISTRATOR || !$this->services->authEnabled()) {
            return;
        }
        $admins = $this->services->roles()->load()->usersWithRole(Acl::ADMINISTRATOR);
        if ($admins === [$name]) {
            throw new \InvalidArgumentException('The last administrator cannot be removed.');
        }
    }

    private function assertPassword(string $password, string $repeat): void
    {
        if ($password !== $repeat) {
            throw new \InvalidArgumentException("The password's doesn't match each other.");
        }
        if (strlen($password) > 1024) {
            throw new \InvalidArgumentException('The password is too long.');
        }
        $min = $this->services->minPasswordLength();
        if (strlen($password) < $min) {
            throw new \InvalidArgumentException($this->tr('The password must be at least %0 characters long.', [$min]));
        }
    }

    private function cascadeDelete(string $name): void
    {
        if ($this->services->hasAccessPathEdit() || $this->services->hasGroupEdit()) {
            $this->services->authz()->update(static fn (AuthzFile $a): AuthzFile => $a->withoutUser($name));
        }
        if ($this->services->authEnabled()) {
            $this->services->roles()->update(static fn (RoleAssignments $r): RoleAssignments => $r->withoutUser($name));
            $this->services->twoFactor()->update(static fn ($t) => $t->without($name));
            $this->services->revocations()->revoke($name);
        }
    }

    private function authzOrNull(): ?AuthzFile
    {
        if (!$this->services->hasAccessPathView() && !$this->services->hasGroupView()) {
            return null;
        }
        try {
            return $this->services->authz()->load();
        } catch (\Throwable $e) {
            $this->info($e->getMessage());

            return null;
        }
    }

    private function authzOrEmpty(): AuthzFile
    {
        return $this->authzOrNull() ?? AuthzFile::empty();
    }

    /** @param array{0: string, 1: list<string|int>}|null $message */
    private function tryRoles(callable $change, ?array $message = null): void
    {
        try {
            $this->services->roles()->update($change);
            if ($message !== null) {
                $this->success(...$message);
            }
        } catch (\Throwable $e) {
            $this->fail($e);
        }
    }

    private function tryAuthz(callable $change): void
    {
        try {
            $this->services->authz()->update($change);
        } catch (\Throwable $e) {
            $this->fail($e);
        }
    }

    private function toUser(string $name): Response
    {
        return $this->redirect('/users/' . rawurlencode($name));
    }
}
