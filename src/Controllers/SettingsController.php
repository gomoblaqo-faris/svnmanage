<?php

declare(strict_types=1);

namespace SvnManage\Controllers;

use SvnManage\Acl\Acl;
use SvnManage\Config\Config;
use SvnManage\Config\ConfigForm;
use SvnManage\Config\ConfigTester;
use SvnManage\Config\SettingsSchema;
use SvnManage\Http\Response;

final class SettingsController extends Controller
{
    public function index(): Response
    {
        $this->authorize(Acl::MOD_SETTINGS, Acl::CHANGE);

        return $this->page('settings/index', [
            'config' => $this->services->config,
            'groups' => SettingsSchema::groups(),
            'extraParents' => ConfigForm::extraParents($this->services->config),
            'configPath' => $this->services->configPath(),
            'action' => '/settings',
            'mode' => 'settings',
        ], 'Settings', 'settings');
    }

    public function save(): Response
    {
        $this->authorize(Acl::MOD_SETTINGS, Acl::CHANGE);
        try {
            $this->writeConfig(fn (Config $c): Config => ConfigForm::apply($this->request, $c)->with('Common', 'FirstStart', '0'));
            $this->audit('settings.save', 'config.ini');
            $this->success('Settings saved.');
        } catch (\Throwable $e) {
            $this->fail($e);
        }

        return $this->redirect('/settings', ['tab' => $this->request->input('_tab') ?: null]);
    }

    /** Runs one "Test" button against the values currently in the form (unsaved). */
    public function test(): Response
    {
        if ($this->services->needsSetup()) {
            $this->requireSetupUnlocked();
        } else {
            $this->authorize(Acl::MOD_SETTINGS, Acl::CHANGE);
        }
        $test = $this->request->input('test');
        try {
            $config = ConfigForm::apply($this->request, $this->services->config);
            [$ok, $message] = (new ConfigTester($config))->run($test);
        } catch (\Throwable $e) {
            [$ok, $message] = [false, $e->getMessage()];
        }

        return $this->fragment('settings/test-result', ['ok' => $ok, 'message' => $message]);
    }
}
