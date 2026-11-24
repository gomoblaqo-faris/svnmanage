<?php

declare(strict_types=1);

namespace SvnManage\Controllers;

use SvnManage\Acl\Acl;
use SvnManage\Authz\AuthzFile;
use SvnManage\Http\HttpException;
use SvnManage\Http\Response;
use SvnManage\Users\User;

final class GroupController extends Controller
{
    public function index(): Response
    {
        $this->authorize(Acl::MOD_GROUP, Acl::VIEW);
        $this->requireActive($this->services->hasGroupView());
        $authz = $this->services->authz()->load();
        $q = $this->request->query('q');
        $groups = [];
        foreach ($this->filter($authz->groupNames(), $q, static fn (string $g): string => $g) as $g) {
            $groups[] = [
                'name' => $g,
                'users' => count($authz->groupUsers($g)),
                'subgroups' => count($authz->groupSubgroups($g)),
                'paths' => count($authz->permissionsOfGroup($g)),
            ];
        }

        return $this->page('groups/index', [
            'groups' => $groups,
            'q' => $q,
            'canAdd' => $this->canEdit(Acl::ADD),
            'canDelete' => $this->canEdit(Acl::DELETE),
            'source' => $this->services->groupSource(),
        ], 'Groups', 'groups');
    }

    public function create(): Response
    {
        $this->authorize(Acl::MOD_GROUP, Acl::ADD);
        $this->requireActive($this->services->hasGroupEdit());

        return $this->page('groups/create', [
            'old' => $this->ctx->session->get('old_group', ''),
            'users' => $this->userNames(),
            'groups' => $this->services->authz()->load()->groupNames(),
            'canAssign' => $this->auth->can(Acl::MOD_GROUP, Acl::ASSIGN),
        ], 'New group', 'groups.new');
    }

    public function store(): Response
    {
        $this->authorize(Acl::MOD_GROUP, Acl::ADD);
        $this->requireActive($this->services->hasGroupEdit());
        $name = trim($this->request->input('groupname'));
        $members = $this->auth->can(Acl::MOD_GROUP, Acl::ASSIGN) ? $this->memberTokens() : [];
        $this->ctx->session->set('old_group', $name);
        try {
            $this->services->authz()->update(static function (AuthzFile $a) use ($name, $members): AuthzFile {
                $a = $a->withGroup($name);
                foreach ($members as $m) {
                    $a = $a->withGroupMember($name, $m);
                }

                return $a;
            });
        } catch (\Throwable $e) {
            $this->fail($e);

            return $this->redirect('/groups/new');
        }
        $this->ctx->session->remove('old_group');
        $this->audit('group.create', $name, ['members' => count($members)]);
        $this->success('The group %0 has been created successfully.', [$name]);

        return $this->toGroup($name);
    }

    public function delete(): Response
    {
        $this->authorize(Acl::MOD_GROUP, Acl::DELETE);
        $this->requireActive($this->services->hasGroupEdit());
        $names = $this->request->inputList('selected_groups');
        if ($names === []) {
            $this->error('You have to select at least one group.');

            return $this->back('/groups');
        }
        $this->each($names, function (string $g): void {
            $this->services->authz()->update(static fn (AuthzFile $a): AuthzFile => $a->withoutGroup($g));
            $this->audit('group.delete', $g);
            $this->success('The group %0 has been deleted.', [$g]);
        });

        return $this->redirect('/groups');
    }

    public function show(string $name): Response
    {
        $this->authorize(Acl::MOD_GROUP, Acl::VIEW);
        $this->requireActive($this->services->hasGroupView());
        $authz = $this->services->authz()->load();
        if (!$authz->hasGroup($name)) {
            throw HttpException::notFound('The group does not exist.');
        }
        $members = $authz->groupMembers($name);
        $permissions = array_values(array_filter(
            $authz->permissionsOfGroup($name),
            fn (array $p): bool => $this->auth->canManagePath($p['path']),
        ));
        $userCandidates = array_values(array_diff($this->userNames(), $members));
        $groupCandidates = array_values(array_filter(
            $authz->groupNames(),
            static fn (string $g): bool => !$authz->wouldCycle($name, $g) && !in_array('@' . $g, $members, true),
        ));

        return $this->page('groups/show', [
            'name' => $name,
            'users' => $authz->groupUsers($name),
            'subgroups' => $authz->groupSubgroups($name),
            'parents' => $authz->groupsContaining('@' . $name),
            'permissions' => $permissions,
            'userCandidates' => $userCandidates,
            'groupCandidates' => $groupCandidates,
            'canAssign' => $this->canEdit(Acl::ASSIGN),
            'canUnassign' => $this->canEdit(Acl::UNASSIGN),
            'canRename' => $this->canEdit(Acl::ADD) && $this->canEdit(Acl::DELETE),
            'canDelete' => $this->canEdit(Acl::DELETE),
            'canRevoke' => $this->services->hasAccessPathEdit() && $this->auth->can(Acl::MOD_ACCESSPATH, Acl::UNASSIGN),
        ], '@' . $name, 'groups');
    }

    public function addMembers(string $name): Response
    {
        $this->authorize(Acl::MOD_GROUP, Acl::ASSIGN);
        $this->requireActive($this->services->hasGroupEdit());
        $this->each($this->memberTokens(), function (string $m) use ($name): void {
            $this->services->authz()->update(static fn (AuthzFile $a): AuthzFile => $a->withGroupMember($name, $m));
            $this->audit('group.member.add', $name, ['member' => $m]);
            $this->success('Added %0 to group %1.', [$m, $name]);
        });

        return $this->toGroup($name);
    }

    public function removeMembers(string $name): Response
    {
        $this->authorize(Acl::MOD_GROUP, Acl::UNASSIGN);
        $this->requireActive($this->services->hasGroupEdit());
        $this->each($this->request->inputList('members'), function (string $m) use ($name): void {
            $this->services->authz()->update(static fn (AuthzFile $a): AuthzFile => $a->withoutGroupMember($name, $m));
            $this->audit('group.member.remove', $name, ['member' => $m]);
            $this->success('Removed %0 from group %1.', [$m, $name]);
        });

        return $this->toGroup($name);
    }

    public function rename(string $name): Response
    {
        $this->authorize(Acl::MOD_GROUP, Acl::ADD);
        $this->authorize(Acl::MOD_GROUP, Acl::DELETE);
        $this->requireActive($this->services->hasGroupEdit());
        $to = trim($this->request->input('newname'));
        try {
            $this->services->authz()->update(static fn (AuthzFile $a): AuthzFile => $a->withRenamedGroup($name, $to));
        } catch (\Throwable $e) {
            $this->fail($e);

            return $this->toGroup($name);
        }
        $this->audit('group.rename', $name, ['to' => $to]);
        $this->success('Renamed group %0 to %1.', [$name, $to]);

        return $this->toGroup($to);
    }

    public function removePermissions(string $name): Response
    {
        $this->authorize(Acl::MOD_ACCESSPATH, Acl::UNASSIGN);
        $this->requireActive($this->services->hasAccessPathEdit());
        $this->each($this->request->inputList('paths'), function (string $path) use ($name): void {
            if (!$this->auth->canManagePath($path)) {
                throw HttpException::forbidden();
            }
            $this->services->authz()->update(static fn (AuthzFile $a): AuthzFile => $a->withoutRule($path, '@' . $name));
            $this->audit('permission.revoke', $path, ['member' => '@' . $name]);
            $this->success('Removed permission of %0 on %1.', ['@' . $name, $path]);
        });

        return $this->toGroup($name);
    }

    /** Bulk page: many users/groups into (or out of) many groups at once. */
    public function memberships(): Response
    {
        $this->authorize(Acl::MOD_GROUP, Acl::ASSIGN);
        $this->requireActive($this->services->hasGroupEdit());

        return $this->page('groups/memberships', [
            'users' => $this->userNames(),
            'groups' => $this->services->authz()->load()->groupNames(),
            'canUnassign' => $this->auth->can(Acl::MOD_GROUP, Acl::UNASSIGN),
        ], 'Memberships', 'memberships');
    }

    public function saveMemberships(): Response
    {
        $remove = $this->request->input('mode') === 'remove';
        $this->authorize(Acl::MOD_GROUP, $remove ? Acl::UNASSIGN : Acl::ASSIGN);
        $this->requireActive($this->services->hasGroupEdit());
        $members = $this->memberTokens();
        $targets = $this->request->inputList('targets');
        if ($members === [] || $targets === []) {
            $this->error('Choose at least one member and one group.');

            return $this->redirect('/groups/memberships');
        }
        $done = 0;
        foreach ($targets as $g) {
            $done += $this->each($members, function (string $m) use ($g, $remove): void {
                $this->services->authz()->update(static fn (AuthzFile $a): AuthzFile => $remove ? $a->withoutGroupMember($g, $m) : $a->withGroupMember($g, $m));
                $this->audit($remove ? 'group.member.remove' : 'group.member.add', $g, ['member' => $m]);
            });
        }
        if ($done > 0) {
            $this->success($remove ? 'Removed %0 memberships.' : 'Added %0 memberships.', [$done]);
        }

        return $this->redirect('/groups/memberships');
    }

    // ------------------------------------------------------------- helpers

    private function canEdit(string $action): bool
    {
        return $this->services->hasGroupEdit() && $this->auth->can(Acl::MOD_GROUP, $action);
    }

    /** @return list<string> selected users and "@group" tokens */
    private function memberTokens(): array
    {
        $users = $this->request->inputList('users');
        $groups = array_map(static fn (string $g): string => '@' . ltrim($g, '@'), $this->request->inputList('groups'));

        return array_values(array_unique([...$groups, ...$users]));
    }

    /** @return list<string> */
    private function userNames(): array
    {
        $provider = $this->services->users();
        if ($provider === null) {
            return [];
        }
        try {
            return array_values(array_filter(
                array_map(static fn (User $u): string => $u->name, $provider->users()),
                static fn (string $n): bool => $n !== '*',
            ));
        } catch (\Throwable $e) {
            $this->info($e->getMessage());

            return [];
        }
    }

    private function toGroup(string $name): Response
    {
        return $this->redirect('/groups/' . rawurlencode($name));
    }
}
