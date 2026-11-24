<?php

declare(strict_types=1);

namespace SvnManage\Controllers;

use SvnManage\Acl\Acl;
use SvnManage\Directory\CachedDirectory;
use SvnManage\Directory\SyncRunner;
use SvnManage\Http\HttpException;
use SvnManage\Http\Response;

/** Synchronises LDAP / Active Directory users and groups into the authz file. */
final class SyncController extends Controller
{
    public function index(): Response
    {
        $this->guard();
        $plan = null;
        $error = null;
        if ($this->request->query('preview') === '1') {
            try {
                $plan = (new SyncRunner($this->services))->preview($this->request->query('fresh') === '1');
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }
        $directory = $this->services->directory();
        $vars = [
            'plan' => $plan,
            'error' => $error,
            'kind' => strtoupper((string) $this->services->directoryKind()),
            'groupSource' => $this->services->groupSource(),
            'cachedAt' => $directory instanceof CachedDirectory ? $directory->updatedAt() : null,
            'autoUsers' => $this->services->config->bool('Update:ldap', 'AutoRemoveUsers', true),
            'autoGroups' => $this->services->config->bool('Update:ldap', 'AutoRemoveGroups', true),
        ];
        if ($this->request->wantsFragment() && $this->request->query('preview') === '1') {
            return $this->fragment('sync/preview', $vars);
        }

        return $this->page('sync/index', $vars, 'Synchronize', 'sync');
    }

    public function run(): Response
    {
        $this->guard();
        try {
            $plan = (new SyncRunner($this->services))->apply();
            foreach ($plan->messages as [$level, $text]) {
                $this->ctx->session->flash($level === 'warning' ? 'info' : $level, $text);
            }
            $this->audit('sync.run', strtolower((string) $this->services->directoryKind()), [
                'groups' => $plan->groupCount,
                'members' => $plan->memberCount,
                'changed' => $plan->changed,
            ]);
            $this->success($plan->changed ? 'Synchronisation finished: %0 groups, %1 memberships.' : 'Everything is already up to date.', [$plan->groupCount, $plan->memberCount]);
        } catch (\Throwable $e) {
            $this->fail($e);
        }

        return $this->redirect('/sync');
    }

    private function guard(): void
    {
        $this->authorize(Acl::MOD_UPDATE, Acl::SYNCHRONIZE);
        $this->requireActive($this->services->isUpdateable());
        if (!$this->services->config->bool('GUI', 'AllowUpdateByGui', true)) {
            throw HttpException::inactive('Synchronisation from the web interface is disabled (GUI.AllowUpdateByGui).');
        }
    }
}
