<?php

declare(strict_types=1);

namespace SvnManage\Tests\Http;

final class ManagementFlowTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->install();
    }

    public function testUserGroupAccessPathLifecycle(): void
    {
        $this->post('/users', ['username' => 'alice', 'password' => 'Alice12345', 'password2' => 'Alice12345']);
        $this->post('/groups', ['groupname' => 'devs', 'users' => ['alice']]);
        $this->post('/groups', ['groupname' => 'all', 'groups' => ['devs'], 'users' => ['admin']]);
        $this->post('/access-paths', ['repository' => 'demo', 'path' => 'trunk/']);
        $this->post('/access-paths/grant', ['path' => 'demo:/trunk', 'groups' => ['devs'], 'permission' => 'rw']);
        $this->post('/permissions', ['paths' => ['demo:/trunk'], 'special' => ['*'], 'permission' => 'none']);
        $this->flashes();

        $authz = $this->file('svn/authz');
        self::assertStringContainsString("devs = alice\n", $authz);
        self::assertStringContainsString('all = @devs, admin', $authz);
        self::assertMatchesRegularExpression('/\[demo:\/trunk\]\n@devs = rw\n\* =\n/', $authz);

        $page = $this->get('/users/alice')->body;
        self::assertStringContainsString('demo:/trunk', $page, 'effective permission via @devs is listed');
        self::assertStringContainsString('via @devs', $page);

        $this->post('/groups/devs/rename', ['newname' => 'developers']);
        $authz = $this->file('svn/authz');
        self::assertStringContainsString('all = @developers, admin', $authz);
        self::assertStringContainsString('@developers = rw', $authz);

        $this->post('/users/delete', ['selected_users' => ['alice']]);
        self::assertStringContainsString('The user alice has been deleted.', $this->flashText());
        self::assertStringNotContainsString('alice', $this->file('svn/authz'));
        self::assertStringNotContainsString('alice:', $this->file('svn/passwd'));
    }

    public function testInvalidInputIsReportedNotWritten(): void
    {
        $this->post('/users', ['username' => 'bad name', 'password' => 'Abcdefgh1', 'password2' => 'Abcdefgh1']);
        self::assertStringContainsString('Invalid user name', $this->flashText());
        $this->post('/users', ['username' => 'short', 'password' => 'abc', 'password2' => 'abc']);
        self::assertStringContainsString('at least 8 characters', $this->flashText());
        $this->post('/users', ['username' => 'mismatch', 'password' => 'Abcdefgh1', 'password2' => 'Abcdefgh2']);
        self::assertStringContainsString("doesn't match", $this->flashText());
        $this->post('/access-paths', ['repository' => 'demo', 'path' => '/../etc']);
        self::assertNotSame('', $this->flashText());
        self::assertStringNotContainsString('bad name', $this->file('svn/passwd'));
        self::assertStringNotContainsString('..', $this->file('svn/authz'));
    }

    public function testCannotDeleteSelfOrLastAdministrator(): void
    {
        $this->post('/users/delete', ['selected_users' => ['admin']]);
        self::assertStringContainsString('cannot delete your own account', $this->flashText());
        $this->post('/users/admin/roles/remove', ['roles' => ['Administrator']]);
        self::assertStringContainsString('last administrator', $this->flashText());
        self::assertStringContainsString('Administrator=', $this->file('userroleassignments.ini'));
    }

    public function testRoleRestrictionsAreEnforced(): void
    {
        $this->post('/users', ['username' => 'bob', 'password' => 'Bob1234567', 'password2' => 'Bob1234567', 'roles' => ['User']]);
        $this->logout();
        $this->login('bob', 'Bob1234567');
        self::assertSame('bob', $_SESSION['user'] ?? null);

        self::assertSame(403, $this->get('/users')->status);
        self::assertSame(403, $this->get('/settings')->status);
        self::assertSame(403, $this->post('/groups', ['groupname' => 'hack'])->status);
        self::assertSame(403, $this->post('/users/bob/roles', ['roles' => ['Administrator']])->status);
        self::assertSame(200, $this->get('/users/bob')->status, 'own profile is visible');
        self::assertSame(200, $this->get('/account/password')->status);
        self::assertSame(403, $this->get('/users/admin/password')->status);
        self::assertStringNotContainsString('hack', $this->file('svn/authz'));
    }

    public function testDelegatedManagersCannotTakeOverAdministrators(): void
    {
        $this->post('/users', ['username' => 'rm', 'password' => 'Manager123', 'password2' => 'Manager123', 'roles' => ['Role-Manager', 'User-Group-Manager']]);
        $this->logout();
        $this->login('rm', 'Manager123');
        self::assertSame('rm', $_SESSION['user'] ?? null);

        self::assertSame(403, $this->get('/users/admin/password')->status);
        self::assertSame(403, $this->post('/users/admin/password', ['password' => 'Hijacked123', 'password2' => 'Hijacked123'])->status);
        $this->post('/users/delete', ['selected_users' => ['admin']]);
        self::assertStringContainsString('admin:', $this->file('svn/passwd'), 'admin account survives');
        $this->post('/users/roles', ['role' => 'User', 'selected_users' => ["x]\n[rm]\nAdministrator=\n[y"]]);
        $this->post('/users/rm/roles', ['roles' => ['Administrator']]);
        $roles = \SvnManage\Acl\RoleAssignments::parse($this->file('userroleassignments.ini'));
        self::assertNotContains('Administrator', $roles->rolesOf('rm'));
        self::assertSame(['admin'], $roles->usersWithRole('Administrator'));

        $this->logout();
        $this->login('admin', 'Secret123');
        self::assertSame('admin', $_SESSION['user'] ?? null, 'admin password unchanged');
    }

    public function testScopedManagerCannotInjectSections(): void
    {
        $this->post('/users', ['username' => 'pm', 'password' => 'Manager123', 'password2' => 'Manager123', 'roles' => ['User']]);
        $this->post('/access-paths', ['repository' => 'mine', 'path' => '/']);
        $this->post('/access-paths/managers', ['selected_paths' => ['mine:/'], 'users' => ['pm']]);
        $this->logout();
        $this->login('pm', 'Manager123');
        $this->post('/access-paths/grant', ['path' => 'mine:/', 'users' => ["evil = rw\n[/]\n*"], 'permission' => 'rw']);
        $this->post('/access-paths/grant', ['path' => 'mine:/', 'special' => ['*'], 'groups' => ["x]\n[/]\n* = rw\n[y"], 'permission' => 'rw']);
        self::assertStringNotContainsString('[/]', $this->file('svn/authz'));
    }

    public function testProjectManagerIsScopedToManagedPaths(): void
    {
        $this->post('/users', ['username' => 'pm', 'password' => 'Manager123', 'password2' => 'Manager123', 'roles' => ['User']]);
        $this->post('/access-paths', ['repository' => 'alpha', 'path' => '/']);
        $this->post('/access-paths', ['repository' => 'beta', 'path' => '/']);
        $this->post('/access-paths/managers', ['selected_paths' => ['alpha:/'], 'users' => ['pm']]);
        self::assertStringContainsString("[pm:AccessPaths]\n0=alpha:/", $this->file('userroleassignments.ini'));
        $this->logout();
        $this->login('pm', 'Manager123');

        $list = $this->get('/access-paths')->body;
        self::assertStringContainsString('alpha:/', $list);
        self::assertStringNotContainsString('beta:/', $list);
        self::assertSame(403, $this->get('/access-paths/view', ['path' => 'beta:/'])->status);

        $this->post('/access-paths', ['repository' => 'alpha', 'path' => '/trunk']);
        self::assertStringContainsString('[alpha:/trunk]', $this->file('svn/authz'));
        $this->post('/access-paths', ['repository' => 'beta', 'path' => '/trunk']);
        self::assertStringNotContainsString('[beta:/trunk]', $this->file('svn/authz'));

        $this->post('/access-paths/grant', ['path' => 'beta:/', 'users' => ['pm'], 'permission' => 'rw']);
        self::assertStringNotContainsString("[beta:/]\npm", $this->file('svn/authz'));
        self::assertSame(403, $this->post('/access-paths/managers', ['selected_paths' => ['alpha:/'], 'users' => ['admin']])->status);
    }

    public function testScopedManagerCannotReadOtherRepositories(): void
    {
        if (trim((string) shell_exec('command -v svnadmin')) === '') {
            self::markTestSkipped('svnadmin not installed');
        }
        foreach (['alpha', 'beta'] as $repo) {
            $this->post('/repositories', ['reponame' => $repo, 'pi' => '0', 'repotype' => 'fsfs', 'repostructuretype' => 'single', 'accesspathcreate' => '1']);
        }
        $this->post('/users', ['username' => 'pm', 'password' => 'Manager123', 'password2' => 'Manager123', 'roles' => ['User']]);
        $this->post('/access-paths', ['repository' => 'alpha', 'path' => '/trunk']);
        $this->post('/access-paths/managers', ['selected_paths' => ['alpha:/trunk'], 'users' => ['pm']]);
        $this->logout();
        $this->login('pm', 'Manager123');

        $list = $this->get('/repositories')->body;
        self::assertStringContainsString('alpha', $list);
        self::assertStringNotContainsString('/repositories/0/beta', $list);
        self::assertSame(403, $this->get('/repositories/0/beta')->status);
        self::assertSame(403, $this->get('/repositories/0/beta/browse')->status);
        $root = $this->get('/repositories/0/alpha/browse')->body;
        self::assertStringContainsString('trunk/', $root);
        self::assertStringNotContainsString('branches/', $root, 'siblings of the managed path are hidden');
        self::assertSame(403, $this->get('/repositories/0/alpha/browse', ['path' => 'branches'])->status);
        self::assertSame(403, $this->get('/repositories/0/alpha/file', ['path' => 'trunk/x.txt'])->status, 'file content needs the dump right');
        self::assertSame(405, $this->get('/repositories/0/alpha/dump')->status, 'dumps are POST-only (no cross-site GET triggers)');
        $show = $this->get('/repositories/0/alpha')->body;
        self::assertStringNotContainsString('Recent commits', $show, 'the whole-repository log is hidden from a path-scoped manager');
        rename($this->dataDir . '/repositories/alpha', $this->dataDir . '/repositories/alpha-moved');
        mkdir($this->dataDir . '/repositories/alpha');
        file_put_contents($this->dataDir . '/repositories/alpha/format', "5\n");
        $broken = $this->get('/repositories/0/alpha')->body;
        self::assertStringNotContainsString($this->dataDir, $broken, 'svn errors with server paths are not shown to non-administrators');
    }

    public function testGroupDeleteCascadesMembershipsAndRules(): void
    {
        $this->post('/groups', ['groupname' => 'child']);
        $this->post('/groups', ['groupname' => 'parent', 'groups' => ['child']]);
        $this->post('/access-paths', ['repository' => '', 'path' => '/']);
        $this->post('/access-paths/grant', ['path' => '/', 'groups' => ['child'], 'permission' => 'r']);
        $this->post('/groups/delete', ['selected_groups' => ['child']]);
        $authz = $this->file('svn/authz');
        self::assertStringNotContainsString('child', $authz);
        self::assertStringContainsString('parent =', $authz);
    }

    public function testEveryPageRendersForAdministrator(): void
    {
        $this->post('/users', ['username' => 'alice', 'password' => 'Alice12345', 'password2' => 'Alice12345']);
        $this->post('/groups', ['groupname' => 'devs', 'users' => ['alice']]);
        $this->post('/access-paths', ['repository' => 'demo', 'path' => '/']);
        foreach (['/', '/repositories', '/repositories/new', '/users', '/users/new', '/users/alice', '/users/alice/password',
            '/account/password', '/groups', '/groups/new', '/groups/devs', '/groups/memberships', '/access-paths',
            '/access-paths/new', '/permissions', '/roles', '/settings', '/activity', '/backups'] as $path) {
            $response = $this->get($path);
            self::assertSame(200, $response->status, $path . "\n" . strip_tags(substr($response->body, 0, 600)));
            self::assertStringContainsString('</html>', $response->body, $path);
        }
        self::assertSame(200, $this->get('/access-paths/view', ['path' => 'demo:/'])->status);
        self::assertSame(404, $this->get('/sync')->status, 'sync is inactive without LDAP/AD');
        self::assertSame(404, $this->get('/nope')->status);
    }

    public function testSettingsSaveAndTestEndpoint(): void
    {
        $this->post('/settings', ['GUI|RepositoryDeleteEnabled' => '1', 'Common|MinPasswordLength' => '12', 'Engine:Providers|AuthenticationStatus' => 'basic']);
        self::assertStringContainsString('Settings saved.', $this->flashText());
        $ini = $this->file('config.ini');
        self::assertStringContainsString('RepositoryDeleteEnabled=true', $ini);
        self::assertStringContainsString('MinPasswordLength=12', $ini);

        $this->post('/settings', ['Engine:Providers|AuthenticationStatus' => 'evil']);
        self::assertStringContainsString('Invalid value', $this->flashText());

        $fragment = $this->post('/settings/test', ['test' => 'SVNAuthFile'], ['HX-Request' => 'true']);
        self::assertSame(200, $fragment->status);
        self::assertStringContainsString('Test passed.', $fragment->body);
        self::assertStringNotContainsString('<html', $fragment->body);
    }

    public function testActivityLogAndBackupsRecordChanges(): void
    {
        $this->post('/groups', ['groupname' => 'audited']);
        self::assertStringContainsString('group.create', $this->get('/activity')->body);
        $backups = glob($this->dataDir . '/backup/*.bak') ?: [];
        self::assertNotEmpty($backups);
        $file = basename($backups[0]);
        self::assertSame(200, $this->get('/backups/' . $file)->status);
        self::assertSame(404, $this->get('/backups/..%2Fconfig.ini')->status);
    }
}
