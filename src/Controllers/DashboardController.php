<?php

declare(strict_types=1);

namespace SvnManage\Controllers;

use SvnManage\Acl\Acl;
use SvnManage\Http\Response;
use SvnManage\Security\ExposureCheck;
use SvnManage\Services;

final class DashboardController extends Controller
{
    public function index(): Response
    {
        $this->requireLogin();
        $s = $this->services;
        $stats = [];
        $problems = [];

        $count = function (string $key, callable $fn) use (&$stats, &$problems): void {
            try {
                $stats[$key] = $fn();
            } catch (\Throwable $e) {
                $stats[$key] = null;
                $problems[] = $e->getMessage();
            }
        };

        if ($s->hasRepoView() && $this->auth->can(Acl::MOD_REPO, Acl::VIEW)) {
            $count('repositories', fn (): int => count($s->repositories()?->all() ?? []));
        }
        if ($s->hasUserView() && $this->auth->can(Acl::MOD_USER, Acl::VIEW)) {
            $count('users', fn (): int => count($s->users()?->users() ?? []));
        }
        if ($s->hasGroupView() && $this->auth->can(Acl::MOD_GROUP, Acl::VIEW)) {
            $count('groups', fn (): int => count($s->authz()->load()->groupNames()));
        }
        if ($s->hasAccessPathView() && $this->auth->can(Acl::MOD_ACCESSPATH, Acl::VIEW)) {
            $count('paths', fn (): int => count($this->auth->filterPaths($s->authz()->load()->accessPaths())));
        }

        $isAdmin = $this->auth->can(Acl::MOD_SETTINGS, Acl::CHANGE);
        $myPermissions = [];
        if ($this->auth->username !== null && $s->hasAccessPathView()) {
            try {
                $myPermissions = $s->authz()->load()->effectivePermissions($this->auth->username);
            } catch (\Throwable) {
                $myPermissions = [];
            }
        }

        return $this->page('dashboard', [
            'stats' => $stats,
            'problems' => array_values(array_unique($problems)),
            'svnVersion' => $isAdmin && $s->hasRepoView() ? $this->svnVersion() : null,
            'phpVersion' => PHP_VERSION,
            'appVersion' => Services::VERSION,
            'isAdmin' => $isAdmin,
            'activity' => $isAdmin ? $s->audit()->recent(6) : [],
            'codeAlerts' => $isAdmin ? $this->codeAlerts() : [],
            'exposure' => $isAdmin ? $this->exposure() : [],
            'myPermissions' => $myPermissions,
            'providers' => $this->providers(),
        ], 'Dashboard', 'dashboard');
    }

    /**
     * Wrong two-factor codes in the last 24 hours, per user. Only someone who already
     * has the password reaches the code step, so this means a password is known.
     *
     * @return array<string, int>
     */
    private function codeAlerts(): array
    {
        $since = time() - 86400;
        $counts = [];
        foreach ($this->services->audit()->recent(500, 'login.code_failed') as $row) {
            if ($row['action'] === 'login.code_failed' && strtotime($row['ts']) >= $since) {
                $counts[$row['target']] = ($counts[$row['target']] ?? 0) + 1;
            }
        }
        arsort($counts);

        return $counts;
    }

    /**
     * Managed files the web server publishes, or that every local account can read or change.
     *
     * @return list<array{0: string, 1: list<string>}>
     */
    private function exposure(): array
    {
        try {
            $webRoots = [$this->request->server('DOCUMENT_ROOT'), $this->services->appRoot . '/public'];

            return ExposureCheck::run($this->services->managedPaths(), $webRoots);
        } catch (\Throwable $e) {
            error_log('[svnmanage] exposure check failed: ' . $e->getMessage());

            return [];
        }
    }

    private function svnVersion(): string
    {
        try {
            return $this->services->svnTools()->version();
        } catch (\Throwable) {
            return '';
        }
    }

    /** @return list<array{0: string, 1: string}> */
    private function providers(): array
    {
        $s = $this->services;

        return [
            ['Authentication', $s->authEnabled() ? 'basic' : 'off'],
            ['Users', $s->type('UserViewProviderType') . ($s->hasUserEdit() ? ' · ' . $s->type('UserEditProviderType') : '')],
            ['Groups', $s->groupSource() . ($s->hasGroupEdit() ? ' · editable' : '')],
            ['Access-Paths', $s->hasAccessPathView() ? 'svnauthfile' . ($s->hasAccessPathEdit() ? ' · editable' : '') : 'off'],
            ['Repositories', $s->hasRepoView() ? 'svnclient' . ($s->hasRepoEdit() ? ' · editable' : '') : 'off'],
        ];
    }
}
