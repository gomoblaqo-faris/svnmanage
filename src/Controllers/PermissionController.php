<?php

declare(strict_types=1);

namespace SvnManage\Controllers;

use SvnManage\Acl\Acl;
use SvnManage\Authz\Permission;
use SvnManage\Http\Response;

/** Bulk page: grant one permission to many users/groups on many access paths. */
final class PermissionController extends Controller
{
    use GrantsPermissions;

    public function index(): Response
    {
        $this->authorize(Acl::MOD_ACCESSPATH, Acl::ASSIGN);
        $this->requireActive($this->services->hasAccessPathEdit());
        $authz = $this->services->authz()->load();

        return $this->page('access-paths/permissions', [
            'paths' => $this->auth->filterPaths($authz->accessPaths()),
            'users' => $this->userNames(),
            'groups' => $authz->groupNames(),
            'preselect' => $this->request->query('path'),
        ], 'Permissions', 'permissions');
    }

    public function store(): Response
    {
        $this->authorize(Acl::MOD_ACCESSPATH, Acl::ASSIGN);
        $this->requireActive($this->services->hasAccessPathEdit());
        $this->grantMany(
            $this->request->inputList('paths'),
            $this->tokens(),
            Permission::fromInput($this->request->input('permission', 'r')),
        );

        return $this->redirect('/permissions');
    }
}
