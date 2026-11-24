<?php

declare(strict_types=1);

namespace SvnManage\Controllers;

use SvnManage\Acl\Acl;
use SvnManage\Acl\RoleAssignments;
use SvnManage\Authz\AuthzFile;
use SvnManage\Authz\Names;
use SvnManage\Authz\Permission;
use SvnManage\Http\HttpException;
use SvnManage\Http\Response;
use SvnManage\Svn\Repository;

final class AccessPathController extends Controller
{
    use GrantsPermissions;

    public function index(): Response
    {
        $this->authorize(Acl::MOD_ACCESSPATH, Acl::VIEW);
        $this->requireActive($this->services->hasAccessPathView());
        $authz = $this->services->authz()->load();
        $roles = $this->services->authEnabled() ? $this->services->roles()->load() : null;
        $q = $this->request->query('q');
        $paths = $this->filter($this->auth->filterPaths($authz->accessPaths()), $q, static fn (string $p): string => $p);

        $byRepo = [];
        foreach ($paths as $p) {
            $repo = Names::repositoryOfPath($p);
            $byRepo[$repo === '' ? '' : $repo][] = [
                'path' => $p,
                'users' => count($authz->userRules($p)),
                'groups' => count($authz->groupRules($p)),
                'managers' => $roles?->managersOf($p) ?? [],
            ];
        }
        ksort($byRepo, SORT_NATURAL | SORT_FLAG_CASE);

        return $this->page('access-paths/index', [
            'byRepo' => $byRepo,
            'total' => count($paths),
            'q' => $q,
            'canAdd' => $this->canEdit(Acl::ADD),
            'canDelete' => $this->canEdit(Acl::DELETE),
            'canManagers' => $this->canAssignManagers(),
            'users' => $this->canAssignManagers() ? $this->userNames() : [],
        ], 'Access-Paths', 'access-paths');
    }

    public function create(): Response
    {
        $this->authorize(Acl::MOD_ACCESSPATH, Acl::ADD);
        $this->requireActive($this->services->hasAccessPathEdit());
        $prefill = $this->request->query('path') ?: (string) $this->ctx->session->get('old_path', '');
        $repos = [];
        if ($this->services->hasRepoView()) {
            try {
                $repos = array_map(static fn (Repository $r): string => $r->name, $this->services->repositories()?->all() ?? []);
                $repos = array_values(array_unique($repos));
                sort($repos, SORT_NATURAL | SORT_FLAG_CASE);
            } catch (\Throwable) {
                $repos = [];
            }
        }

        return $this->page('access-paths/create', [
            'repos' => $repos,
            'prefillRepo' => Names::repositoryOfPath($prefill),
            'prefillPath' => $prefill === '' ? '/' : (str_contains($prefill, ':') ? substr($prefill, strpos($prefill, ':') + 1) : $prefill),
            'scoped' => $this->auth->isPathScoped(),
        ], 'New access path', 'access-paths.new');
    }

    public function store(): Response
    {
        $this->authorize(Acl::MOD_ACCESSPATH, Acl::ADD);
        $this->requireActive($this->services->hasAccessPathEdit());
        $repo = trim($this->request->input('repository'));
        $rel = '/' . trim(trim($this->request->input('path')), '/');
        $path = $repo === '' ? $rel : $repo . ':' . $rel;
        $this->ctx->session->set('old_path', $path);
        try {
            if (!$this->auth->canManagePath($path)) {
                throw new \InvalidArgumentException('You can only create access paths below the paths you manage.');
            }
            $this->services->authz()->update(static fn (AuthzFile $a): AuthzFile => $a->withAccessPath($path));
        } catch (\Throwable $e) {
            $this->fail($e);

            return $this->redirect('/access-paths/new');
        }
        $this->ctx->session->remove('old_path');
        $this->audit('accesspath.create', $path);
        $this->success('Created AccessPath "%0" successfully.', [$path]);

        return $this->toPath($path);
    }

    public function delete(): Response
    {
        $this->authorize(Acl::MOD_ACCESSPATH, Acl::DELETE);
        $this->requireActive($this->services->hasAccessPathEdit());
        $paths = $this->request->inputList('selected_paths');
        if ($paths === []) {
            $this->error('You have to select at least one access path.');

            return $this->back('/access-paths');
        }
        $this->each($paths, function (string $path): void {
            if (!$this->auth->canManagePath($path)) {
                throw HttpException::forbidden();
            }
            $this->services->authz()->update(static fn (AuthzFile $a): AuthzFile => $a->withoutAccessPath($path));
            if ($this->services->authEnabled()) {
                $this->services->roles()->update(static fn (RoleAssignments $r): RoleAssignments => $r->withoutPathEverywhere($path));
            }
            $this->audit('accesspath.delete', $path);
            $this->success('Removed Access-Path "%0"', [$path]);
        });

        return $this->redirect('/access-paths');
    }

    public function show(): Response
    {
        $this->authorize(Acl::MOD_ACCESSPATH, Acl::VIEW);
        $this->requireActive($this->services->hasAccessPathView());
        $path = $this->request->query('path');
        $authz = $this->services->authz()->load();
        if (!$authz->hasAccessPath($path)) {
            throw HttpException::notFound('The access path does not exist.');
        }
        if (!$this->auth->canManagePath($path)) {
            throw HttpException::forbidden();
        }
        $roles = $this->services->authEnabled() ? $this->services->roles()->load() : null;
        $userRules = $authz->userRules($path);
        $groupRules = $authz->groupRules($path);

        return $this->page('access-paths/show', [
            'path' => $path,
            'repo' => Names::repositoryOfPath($path),
            'userRules' => $userRules,
            'groupRules' => $groupRules,
            'otherRules' => array_diff_key($authz->rules($path), $userRules, array_flip(array_map(static fn ($g) => '@' . $g, array_keys($groupRules)))),
            'managers' => $roles?->managersOf($path) ?? [],
            'users' => array_values(array_diff($this->userNames(), array_keys($userRules))),
            'groups' => array_values(array_diff($authz->groupNames(), array_keys($groupRules))),
            'canGrant' => $this->canEdit(Acl::ASSIGN),
            'canRevoke' => $this->canEdit(Acl::UNASSIGN),
            'canDelete' => $this->canEdit(Acl::DELETE),
            'canManagers' => $this->canAssignManagers(),
            'allUsers' => $this->canAssignManagers() ? $this->userNames() : [],
        ], $path, 'access-paths');
    }

    public function grant(): Response
    {
        $this->authorize(Acl::MOD_ACCESSPATH, Acl::ASSIGN);
        $this->requireActive($this->services->hasAccessPathEdit());
        $path = $this->request->input('path');
        $this->grantMany([$path], $this->tokens(), Permission::fromInput($this->request->input('permission', 'r')));

        return $this->toPath($path);
    }

    public function revoke(): Response
    {
        $this->authorize(Acl::MOD_ACCESSPATH, Acl::UNASSIGN);
        $this->requireActive($this->services->hasAccessPathEdit());
        $path = $this->request->input('path');
        if (!$this->auth->canManagePath($path)) {
            throw HttpException::forbidden();
        }
        $this->each($this->request->inputList('members'), function (string $token) use ($path): void {
            $this->services->authz()->update(static fn (AuthzFile $a): AuthzFile => $a->withoutRule($path, $token));
            $this->audit('permission.revoke', $path, ['member' => $token]);
            $this->success('Removed permission of %0 on %1.', [$token, $path]);
        });

        return $this->toPath($path);
    }

    public function assignManagers(): Response
    {
        $this->requireManagerRights();
        $paths = $this->request->inputList('selected_paths') ?: $this->request->inputList('paths');
        $users = $this->request->inputList('users');
        if ($paths === [] || $users === []) {
            $this->error('Choose at least one user and one access path.');

            return $this->back('/access-paths');
        }
        try {
            $this->services->roles()->update(function (RoleAssignments $r) use ($paths, $users): RoleAssignments {
                foreach ($paths as $p) {
                    foreach ($users as $u) {
                        $r = $r->withManagedPath($u, $p);
                        $this->audit('manager.assign', $p, ['member' => $u]);
                    }
                }

                return $r;
            });
            $this->success('Assigned %0 project managers.', [count($paths) * count($users)]);
        } catch (\Throwable $e) {
            $this->fail($e);
        }

        return $this->back('/access-paths');
    }

    public function removeManagers(): Response
    {
        $this->requireManagerRights();
        $path = $this->request->input('path');
        $this->each($this->request->inputList('users'), function (string $u) use ($path): void {
            $this->services->roles()->update(static fn (RoleAssignments $r): RoleAssignments => $r->withoutManagedPath($u, $path));
            $this->audit('manager.unassign', $path, ['member' => $u]);
            $this->success('%0 no longer manages %1.', [$u, $path]);
        });

        return $this->back('/access-paths');
    }

    private function canEdit(string $action): bool
    {
        return $this->services->hasAccessPathEdit() && $this->auth->can(Acl::MOD_ACCESSPATH, $action);
    }

    private function canAssignManagers(): bool
    {
        return $this->services->authEnabled()
            && $this->auth->can(Acl::MOD_ACCESSPATH, Acl::ASSIGN)
            && !$this->auth->isPathScoped();
    }

    private function requireManagerRights(): void
    {
        $this->authorize(Acl::MOD_ACCESSPATH, Acl::ASSIGN);
        if (!$this->canAssignManagers()) {
            throw HttpException::forbidden();
        }
    }

    private function toPath(string $path): Response
    {
        return $this->redirect('/access-paths/view', ['path' => $path]);
    }
}
