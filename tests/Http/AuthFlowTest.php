<?php

declare(strict_types=1);

namespace SvnManage\Tests\Http;

final class AuthFlowTest extends HttpTestCase
{
    public function testFreshInstallRedirectsToSetup(): void
    {
        $response = $this->get('/');
        self::assertSame(303, $response->status);
        self::assertSame('/setup', $response->headers['Location']);
        self::assertStringContainsString('Setup key', $this->get('/setup')->body);
        $this->post('/setup/unlock', ['key' => $this->file('setup.key')]);
        self::assertStringContainsString('Use local defaults', $this->get('/setup')->body);
    }

    public function testSetupRequiresTheServerSideKey(): void
    {
        $page = $this->get('/setup')->body;
        self::assertStringContainsString('setup.key', $page, 'tells the installer where the key is');
        $key = $this->file('setup.key');
        self::assertMatchesRegularExpression('/^[A-Za-z0-9]{20,}$/', $key);
        self::assertSame(0600, fileperms($this->dataDir . '/setup.key') & 0777);

        self::assertSame(403, $this->post('/setup', ['action' => 'defaults'])->status);
        self::assertSame(403, $this->post('/setup/test', ['test' => 'SvnExecutable'])->status);
        self::assertSame(403, $this->post('/setup/admin', ['mode' => 'create', 'username' => 'eve', 'password' => 'Evil12345', 'password2' => 'Evil12345'])->status);
        self::assertStringContainsString('FirstStart=1', $this->file('config.ini'));

        $this->post('/setup/unlock', ['key' => 'wrong']);
        self::assertStringContainsString('setup key is not correct', $this->flashText());
        self::assertSame(403, $this->post('/setup', ['action' => 'defaults'])->status);

        $this->post('/setup/unlock', ['key' => $key]);
        self::assertSame(303, $this->post('/setup', ['action' => 'defaults'])->status);
        self::assertStringContainsString('FirstStart=0', $this->file('config.ini'));
    }

    public function testSetupKeyIsRemovedAndWizardStaysClosedOnUnreadableRoles(): void
    {
        $this->install();
        self::assertFileDoesNotExist($this->dataDir . '/setup.key');
        unlink($this->dataDir . '/userroleassignments.ini');
        mkdir($this->dataDir . '/userroleassignments.ini');
        $this->logout();
        self::assertNotSame('/setup', $this->get('/')->headers['Location'] ?? '', 'an unreadable role file must not re-open setup');
    }

    public function testSetupCreatesFilesAndAdministrator(): void
    {
        $this->install();
        self::assertStringContainsString('admin:$2y$', $this->file('svn/passwd'));
        self::assertStringContainsString("[admin]\nAdministrator=", $this->file('userroleassignments.ini'));
        self::assertStringContainsString('FirstStart=0', $this->file('config.ini'));
        $dashboard = $this->get('/');
        self::assertSame(200, $dashboard->status);
        self::assertStringContainsString('Dashboard', $dashboard->body);
        self::assertSame('/', $this->get('/setup')->headers['Location'] ?? null, 'setup is locked once done');
    }

    public function testAnonymousUserIsSentToLoginWithNext(): void
    {
        $this->install();
        $this->logout();
        $response = $this->get('/users');
        self::assertSame(303, $response->status);
        self::assertSame('/login?next=%2Fusers', $response->headers['Location']);
    }

    public function testLoginSucceedsAndHonoursSafeNext(): void
    {
        $this->install();
        $this->logout();
        $response = $this->post('/login', ['username' => 'admin', 'password' => 'Secret123', 'next' => '/users']);
        self::assertSame('/users', $response->headers['Location']);
        self::assertSame('admin', $_SESSION['user']);
    }

    public function testLoginRejectsOpenRedirects(): void
    {
        $this->install();
        foreach (['//evil.example', 'https://evil.example', '/\\evil.example', "/\t/evil.example", "/\n/evil.example", "/ /evil.example", "/\x7f/evil"] as $next) {
            $this->logout();
            $response = $this->post('/login', ['username' => 'admin', 'password' => 'Secret123', 'next' => $next]);
            self::assertSame('/', $response->headers['Location'], $next);
        }
    }

    public function testWrongPasswordIsRejectedAndRateLimited(): void
    {
        $this->install();
        $this->logout();
        for ($i = 0; $i < 5; $i++) {
            $this->login('admin', 'wrong');
            self::assertStringContainsString('Wrong user/password combination.', $this->flashText());
        }
        $this->login('admin', 'Secret123');
        self::assertArrayNotHasKey('user', $_SESSION, 'locked out even with the right password');
        self::assertStringContainsString('Too many failed login attempts', $this->flashText());
    }

    public function testUserWithoutRoleCannotSignIn(): void
    {
        $this->install();
        $this->post('/users', ['username' => 'carol', 'password' => 'Carol12345', 'password2' => 'Carol12345']);
        $this->logout();
        $this->login('carol', 'Carol12345');
        self::assertArrayNotHasKey('user', $_SESSION);
    }

    public function testPostWithoutCsrfTokenIsRejected(): void
    {
        $this->install();
        $response = $this->post('/groups', ['groupname' => 'x'], [], false);
        self::assertSame(419, $response->status);
        self::assertStringNotContainsString('[groups]' . "\nx", $this->file('svn/authz'));
    }

    public function testCsrfHeaderIsAcceptedForHtmx(): void
    {
        $this->install();
        $response = $this->post('/groups', ['groupname' => 'viaheader'], ['X-CSRF-Token' => $this->token()], false);
        self::assertSame(303, $response->status);
    }

    public function testSecurityHeaders(): void
    {
        $this->install();
        $headers = $this->get('/')->headers;
        self::assertStringContainsString("script-src 'self'", $headers['Content-Security-Policy'] ?? '');
        self::assertSame('nosniff', $headers['X-Content-Type-Options'] ?? null);
        self::assertArrayHasKey('Referrer-Policy', $headers);
    }

    public function testFragmentErrorsAreRetargetedToToasts(): void
    {
        $this->install();
        $response = $this->get('/groups/does-not-exist', [], ['HX-Request' => 'true']);
        self::assertSame(404, $response->status);
        self::assertSame('#toasts', $response->headers['HX-Retarget'] ?? null);
    }

    public function testOwnPasswordChangeRequiresCurrentPassword(): void
    {
        $this->install();
        $this->post('/users/admin/password', ['current' => 'nope', 'password' => 'NewSecret1', 'password2' => 'NewSecret1']);
        self::assertStringContainsString('current password is not correct', $this->flashText());
        $this->post('/users/admin/password', ['current' => 'Secret123', 'password' => 'NewSecret1', 'password2' => 'NewSecret1']);
        self::assertStringContainsString('Password changed', $this->flashText());
        $this->logout();
        $this->login('admin', 'NewSecret1');
        self::assertSame('admin', $_SESSION['user'] ?? null);
    }

    public function testLocaleSwitchSetsCookie(): void
    {
        $this->install();
        $response = $this->post('/locale', ['locale' => 'de_DE', '_back' => '/users']);
        self::assertSame('/users', $response->headers['Location']);
        self::assertStringStartsWith('locale=de_DE;', $response->headers['Set-Cookie'] ?? '');
        self::assertSame('/', $this->post('/locale', ['locale' => 'de_DE', '_back' => "/\t/evil.example"])->headers['Location']);
        $external = $this->post('/locale', ['locale' => 'xx', '_back' => 'https://evil.example/']);
        self::assertSame('/', $external->headers['Location']);
        self::assertStringStartsWith('locale=en_US;', $external->headers['Set-Cookie'] ?? '');
    }

    public function testLoginDoesNotLeakProviderErrors(): void
    {
        $this->install();
        $ini = $this->file('config.ini');
        $ini = (string) preg_replace('/^UserViewProviderType=.*$/m', 'UserViewProviderType=ldap', $ini);
        $ini = (string) preg_replace('/^HostAddress=.*$/m', 'HostAddress=secret-ldap.internal.example:bogus', $ini);
        file_put_contents($this->dataDir . '/config.ini', $ini);
        $this->logout();
        $this->login('admin', 'Secret123');
        $flash = $this->flashText();
        self::assertStringNotContainsString('secret-ldap', $flash);
        self::assertStringContainsString('Login is currently not possible', $flash);
    }

    public function testProxyAuthIgnoresInvalidNames(): void
    {
        $this->install();
        $this->post('/settings', ['Common|HttpProxyAuth' => '1']);
        $this->post('/users', ['username' => 'norole', 'password' => 'NoRole1234', 'password2' => 'NoRole1234']);
        $this->logout();
        $response = $this->app->handle(new \SvnManage\Http\Request('GET', '/', '', [], [], [], ['REMOTE_ADDR' => '127.0.0.1', 'REMOTE_USER' => "admin\nx"]));
        self::assertSame(303, $response->status);
        self::assertArrayNotHasKey('user', $_SESSION);
        $this->app->handle(new \SvnManage\Http\Request('GET', '/', '', [], [], [], ['REMOTE_ADDR' => '127.0.0.1', 'REMOTE_USER' => 'norole']));
        self::assertArrayNotHasKey('user', $_SESSION, 'proxy users still need a role that grants login');
        $ok = $this->app->handle(new \SvnManage\Http\Request('GET', '/', '', [], [], [], ['REMOTE_ADDR' => '127.0.0.1', 'REMOTE_USER' => 'admin']));
        self::assertSame(200, $ok->status);
    }

    public function testSensitiveFilesAreNotWorldReadable(): void
    {
        $this->install();
        foreach (['config.ini', 'svn/passwd', 'svn/authz', 'userroleassignments.ini', 'audit.log'] as $file) {
            self::assertSame(0, fileperms($this->dataDir . '/' . $file) & 0007, $file . ' must not be world-readable');
        }
        foreach (glob($this->dataDir . '/backup/*.bak') ?: [] as $backup) {
            self::assertSame(0, fileperms($backup) & 0007, basename($backup));
        }
    }

    public function testCurrentPasswordCheckIsThrottled(): void
    {
        $this->install();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/users/admin/password', ['current' => 'nope' . $i, 'password' => 'Changed123', 'password2' => 'Changed123']);
        }
        $this->post('/users/admin/password', ['current' => 'Secret123', 'password' => 'Changed123', 'password2' => 'Changed123']);
        self::assertStringContainsString('Too many failed', $this->flashText());
        $this->logout();
        $this->login('admin', 'Changed123');
        self::assertArrayNotHasKey('user', $_SESSION, 'password must not have changed while throttled');
    }
}
