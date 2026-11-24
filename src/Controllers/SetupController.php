<?php

declare(strict_types=1);

namespace SvnManage\Controllers;

use SvnManage\Acl\Acl;
use SvnManage\Authz\AuthzFile;
use SvnManage\Config\Config;
use SvnManage\Config\ConfigForm;
use SvnManage\Config\SecurityProfiles;
use SvnManage\Config\SettingsSchema;
use SvnManage\Http\Response;
use SvnManage\Security\SetupChecks;

/** First-run wizard: 1) connect Subversion files  2) secure the site  3) choose an administrator. */
final class SetupController extends Controller
{
    /** Session flag: the security step was saved in this setup session. */
    private const SECURED = 'setup_secured';

    public function index(): Response
    {
        $s = $this->services;
        if ($this->ctx->session->get('setup_unlocked') !== true) {
            $key = $s->setupKey();
            $key->ensure();

            return Response::html($this->ctx->view->page('setup/unlock', [], $this->tr('Setup'), '', 'layout-auth'));
        }
        $step = match (true) {
            $s->config->string('Common', 'FirstStart', '0') === '1' => 1,
            $this->ctx->session->get(self::SECURED) !== true => 2,
            default => 3,
        };
        $vars = ['step' => $step, 'config' => $s->config, 'dataDir' => $s->dataDir];

        if ($step === 1) {
            $vars += [
                'groups' => array_values(array_filter(SettingsSchema::groups(), static fn (array $g): bool => $g['id'] !== 'security')),
                'extraParents' => ConfigForm::extraParents($s->config),
                'configPath' => $s->configPath(),
                'action' => '/setup',
                'mode' => 'setup',
            ];
        } elseif ($step === 2) {
            $profile = SecurityProfiles::normalize($this->request->query('profile'));
            $vars = [
                'step' => 2,
                'config' => SecurityProfiles::apply($s->config, $profile, $this->request->isSecure()),
                'dataDir' => $s->dataDir,
                'profile' => $profile,
                'checks' => SetupChecks::run($this->request, $s),
                'groups' => array_values(array_filter(SettingsSchema::groups(), static fn (array $g): bool => $g['id'] === 'security')),
                'extraParents' => [],
                'configPath' => $s->configPath(),
                'action' => '/setup',
                'mode' => 'setup-security',
                'hidden' => ['action' => 'security'],
            ];
        } else {
            $users = [];
            $userError = null;
            try {
                $users = $s->users()?->users() ?? [];
            } catch (\Throwable $e) {
                $userError = $e->getMessage();
            }
            $vars += ['users' => $users, 'userError' => $userError, 'canCreate' => $s->userEditor() !== null, 'minLength' => $s->minPasswordLength()];
        }

        return Response::html($this->ctx->view->page('setup/index', $vars, $this->tr('Setup'), '', 'layout-auth'));
    }

    public function unlock(): Response
    {
        $throttle = $this->services->throttle('setup');
        $wait = $throttle->reserve('', $this->request->ip());
        if ($wait > 0) {
            $this->error('Too many failed login attempts. Try again in %0 minutes.', [(int) ceil($wait / 60)]);

            return $this->redirect('/setup');
        }
        if (strlen($this->request->input('key')) <= 128 && $this->services->setupKey()->verify($this->request->input('key'))) {
            $throttle->succeeded('', $this->request->ip());
            $this->ctx->session->regenerate();
            $this->ctx->session->set('setup_unlocked', true);
        } else {
            $this->audit('setup.unlock.failed', 'setup');
            $this->error('The setup key is not correct.');
        }

        return $this->redirect('/setup');
    }

    public function save(): Response
    {
        $this->requireSetupUnlocked();
        try {
            $action = $this->request->input('action');
            if ($action === 'security') {
                // Only the security fields are on this form; ConfigForm leaves every other setting alone.
                $this->writeConfig(fn (Config $c): Config => ConfigForm::apply($this->request, $c));
                $this->audit('setup.security', 'config.ini');
                $this->ctx->session->set(self::SECURED, true);
                $this->success('Security settings saved.');

                return $this->redirect('/setup');
            }
            $this->ctx->session->remove(self::SECURED); // every other step comes before security
            if ($action === 'resecure') {
                return $this->redirect('/setup');
            }
            if ($action === 'reconfigure') {
                $this->writeConfig(static fn (Config $c): Config => $c->with('Common', 'FirstStart', '1'));

                return $this->redirect('/setup');
            }
            if ($action === 'defaults') {
                $this->writeConfig(fn (Config $c): Config => $this->localDefaults($c));
                $this->audit('setup.defaults', $this->services->dataDir);
                $this->success('Created a local Subversion setup in %0.', [$this->services->dataDir]);

                return $this->redirect('/setup');
            }
            $this->writeConfig(fn (Config $c): Config => ConfigForm::apply($this->request, $c)->with('Common', 'FirstStart', '0'));
            $this->audit('setup.config', 'config.ini');
        } catch (\Throwable $e) {
            $this->fail($e);
        }

        return $this->redirect('/setup');
    }

    public function admin(): Response
    {
        $this->requireSetupUnlocked();
        $s = $this->services;
        if ($s->config->string('Common', 'FirstStart', '0') === '1' || $this->ctx->session->get(self::SECURED) !== true) {
            return $this->redirect('/setup');
        }
        try {
            if ($this->request->input('mode') === 'create') {
                $editor = $s->userEditor() ?? throw new \RuntimeException('No user edit provider is configured.');
                $username = trim($this->request->input('username'));
                $password = $this->request->input('password');
                if ($password !== $this->request->input('password2')) {
                    throw new \InvalidArgumentException("The password's doesn't match each other.");
                }
                if (strlen($password) < $s->minPasswordLength()) {
                    throw new \InvalidArgumentException($this->tr('The password must be at least %0 characters long.', [$s->minPasswordLength()]));
                }
                $editor->create($username, $password);
            } else {
                $username = $this->request->input('username');
                if ($username === '' || !($s->users()?->exists($username) ?? false)) {
                    throw new \InvalidArgumentException('Please choose a user.');
                }
            }
            $s->roles()->update(static fn ($r) => $r->withRole($username, Acl::ADMINISTRATOR));
            $this->audit('setup.admin', $username);
            $s->setupKey()->remove();
            $this->ctx->session->remove('setup_unlocked');
            $this->ctx->session->remove(self::SECURED);
            if ($this->request->input('mode') === 'create') {
                $this->ctx->session->login($username);
                $this->success('Welcome to svnmanage! %0 is now an administrator.', [$username]);

                return $this->redirect('/');
            }
            $this->success('The user has been defined as admin. You can login now.');

            return $this->redirect('/login');
        } catch (\Throwable $e) {
            $this->fail($e);

            return $this->redirect('/setup');
        }
    }

    /** Creates data/svn/{authz,passwd} and data/repositories and points the config at them. */
    private function localDefaults(Config $config): Config
    {
        $data = $this->services->dataDir;
        foreach ([$data . '/svn', $data . '/repositories'] as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
                throw new \RuntimeException(sprintf('Cannot create folder %s.', $dir));
            }
        }
        $files = [
            $data . '/svn/authz' => "# Subversion authorization file managed by svnmanage\n\n" . AuthzFile::empty()->render(),
            $data . '/svn/passwd' => '',
        ];
        foreach ($files as $file => $initial) {
            if (!is_file($file)) {
                if (@file_put_contents($file, $initial) === false) {
                    throw new \RuntimeException(sprintf('Cannot create %s.', $file));
                }
                @chmod($file, \SvnManage\Support\FileStore::NEW_FILE_MODE);
            }
        }

        return $config->withAll([
            'Subversion' => ['SVNAuthFile' => './data/svn/authz'],
            'Users:passwd' => ['SVNUserFile' => './data/svn/passwd'],
            'Repositories:svnclient' => ['SVNParentPath' => './data/repositories'],
            'Engine:Providers' => [
                'AuthenticationStatus' => 'basic', 'UserViewProviderType' => 'passwd', 'UserEditProviderType' => 'passwd',
                'GroupViewProviderType' => 'svnauthfile', 'GroupEditProviderType' => 'svnauthfile',
                'AccessPathViewProviderType' => 'svnauthfile', 'AccessPathEditProviderType' => 'svnauthfile',
                'RepositoryViewProviderType' => 'svnclient', 'RepositoryEditProviderType' => 'svnclient',
            ],
            'Common' => ['FirstStart' => '0'],
        ]);
    }
}
