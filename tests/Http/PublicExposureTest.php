<?php

declare(strict_types=1);

namespace SvnManage\Tests\Http;

use SvnManage\Http\Request;

/** Defences for running svnmanage on a public, internet-facing site. */
final class PublicExposureTest extends HttpTestCase
{
    public function testNewInstallsUseStrictDefaults(): void
    {
        $this->get('/setup');
        $config = $this->file('config.ini');
        self::assertStringContainsString('MinPasswordLength=12', $config);
        self::assertStringContainsString('SessionMaxLifetime=43200', $config);
        self::assertStringContainsString('LoginMaxAttemptsPerIp=20', $config);
    }

    public function testAllowedNetworksBlockOtherAddresses(): void
    {
        $this->install();
        $this->post('/settings', ['Common|AllowedNetworks' => '10.0.0.0/8']);
        self::assertStringContainsString('lock you out', $this->flashText(), 'refuses a list without your own address');
        self::assertStringNotContainsString('AllowedNetworks=10.', $this->file('config.ini'));

        $this->post('/settings', ['Common|AllowedNetworks' => '127.0.0.0/8, 10.0.0.0/8']);
        self::assertStringContainsString('AllowedNetworks=127.0.0.0/8, 10.0.0.0/8', $this->file('config.ini'));

        $blocked = $this->get('/login', [], ['REMOTE_ADDR' => '198.51.100.7']);
        self::assertSame(403, $blocked->status);
        self::assertStringNotContainsString('svnmanage', strtolower($blocked->body), 'reveals nothing about the application');
        self::assertSame(403, $this->post('/login', ['username' => 'admin', 'password' => 'Secret123'], ['REMOTE_ADDR' => '198.51.100.7'])->status);
        self::assertNotSame(403, $this->get('/login', [], ['REMOTE_ADDR' => '10.9.9.9'])->status, 'allowed range passes');
    }

    public function testInvalidNetworkListIsRejected(): void
    {
        $this->install();
        $this->post('/settings', ['Common|TrustedProxies' => '10.0.0.0/8, nope']);
        self::assertStringContainsString('not a valid IP address', $this->flashText());
    }

    public function testSecurityHeaders(): void
    {
        $plain = $this->get('/login');
        self::assertSame('same-origin', $plain->headers['Cross-Origin-Opener-Policy'] ?? null);
        self::assertSame('same-origin', $plain->headers['Cross-Origin-Resource-Policy'] ?? null);
        self::assertStringContainsString('camera=()', $plain->headers['Permissions-Policy'] ?? '');
        self::assertStringContainsString("base-uri 'none'", $plain->headers['Content-Security-Policy'] ?? '');
        self::assertArrayNotHasKey('Strict-Transport-Security', $plain->headers, 'no HSTS over plain HTTP');
        self::assertSame('no-store', $plain->headers['Cache-Control'] ?? null);
        self::assertSame('no-referrer', $plain->headers['Referrer-Policy'] ?? null);

        $tls = $this->get('/login', [], ['HTTPS' => 'on']);
        self::assertStringStartsWith('max-age=31536000', $tls->headers['Strict-Transport-Security'] ?? '');
    }

    public function testSessionsHaveAnAbsoluteLifetime(): void
    {
        $this->install();
        self::assertSame(200, $this->get('/')->status);
        $_SESSION['login_at'] = time() - 43201;
        $_SESSION['last_seen'] = time();
        $response = $this->get('/');
        self::assertSame(303, $response->status, 'expired after SessionMaxLifetime even while active');
        self::assertStringContainsString('/login', $response->headers['Location'] ?? '');
    }

    public function testLoginIsThrottledPerAddressAcrossUserNames(): void
    {
        $this->install();
        $this->logout();
        for ($i = 0; $i < 20; $i++) {
            $this->post('/login', ['username' => 'nobody' . $i, 'password' => 'wrong'], ['REMOTE_ADDR' => '198.51.100.20']);
            $this->flashes();
        }
        $this->post('/login', ['username' => 'admin', 'password' => 'Secret123'], ['REMOTE_ADDR' => '198.51.100.20']);
        self::assertStringContainsString('Too many failed', $this->flashText());
        self::assertArrayNotHasKey('user', $_SESSION);

        $this->post('/login', ['username' => 'admin', 'password' => 'Secret123'], ['REMOTE_ADDR' => '198.51.100.21']);
        self::assertSame('admin', $_SESSION['user'] ?? null, 'other addresses are not affected');
    }

    public function testForwardedForIsUsedOnlyFromTrustedProxies(): void
    {
        $this->install();
        $this->logout();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['username' => 'admin', 'password' => 'wrong'], ['X-Forwarded-For' => '6.6.6.' . $i]);
            $this->flashes();
        }
        $this->post('/login', ['username' => 'admin', 'password' => 'Secret123'], ['X-Forwarded-For' => '6.6.6.99']);
        self::assertStringContainsString('Too many failed', $this->flashText(), 'a spoofed header must not reset the limit');
    }

    public function testOversizedLoginInputIsRejectedCheaply(): void
    {
        $this->install();
        $this->logout();
        $before = strlen($this->file('audit.log'));
        $this->post('/login', ['username' => str_repeat('a', 100_000), 'password' => 'x']);
        self::assertStringContainsString('Wrong user/password', $this->flashText());
        $this->post('/login', ['username' => 'admin', 'password' => str_repeat('p', 5000)]);
        self::assertStringContainsString('Wrong user/password', $this->flashText());
        self::assertLessThan(2000, strlen($this->file('audit.log')) - $before, 'audit lines stay small');
        self::assertLessThan(300, strlen((string) ($_SESSION['login_user'] ?? '')));
    }

    public function testSessionsAreOnlyStartedWhenNeeded(): void
    {
        $anon = static fn (string $method, string $path, array $cookies = []): \SvnManage\Http\Request => new \SvnManage\Http\Request($method, $path, '', [], [], [], ['REMOTE_ADDR' => '1.2.3.4'], $cookies);
        self::assertFalse(\SvnManage\Http\Session::isNeeded($anon('GET', '/')), 'redirect to login needs no session');
        self::assertFalse(\SvnManage\Http\Session::isNeeded($anon('GET', '/nope')));
        self::assertFalse(\SvnManage\Http\Session::isNeeded($anon('POST', '/login')), 'a POST without a session cookie fails CSRF anyway');
        self::assertFalse(\SvnManage\Http\Session::isNeeded($anon('GET', '/login')), 'the login page uses a stateless token');
        self::assertTrue(\SvnManage\Http\Session::isNeeded($anon('GET', '/setup')));
        self::assertTrue(\SvnManage\Http\Session::isNeeded($anon('GET', '/', ['svnmanage' => 'abc'])));
        self::assertTrue(\SvnManage\Http\Session::isNeeded($anon('GET', '/', ['__Host-svnmanage' => 'abc'])));
    }

    public function testForwardedUserNeedsATrustedProxy(): void
    {
        $this->install();
        $this->post('/settings', ['Common|HttpProxyAuth' => '1']);
        $this->logout();
        $this->get('/', [], ['X-Forwarded-User' => 'admin', 'REMOTE_ADDR' => '198.51.100.9']);
        self::assertArrayNotHasKey('user', $_SESSION, 'a client cannot claim to be admin');

        $this->login('admin', 'Secret123');
        $this->post('/settings', ['Common|TrustedProxies' => '127.0.0.1', 'Common|HttpProxyAuth' => '1']);
        $this->logout();
        $this->get('/', [], ['X-Forwarded-User' => 'admin', 'REMOTE_ADDR' => '127.0.0.1']);
        self::assertSame('admin', $_SESSION['user'] ?? null, 'header honoured from the trusted proxy');
    }

    public function testAnonymousPagesRevealLittle(): void
    {
        $setup = $this->get('/setup')->body;
        self::assertStringNotContainsString($this->dataDir, $setup, 'no absolute server paths');
        self::assertStringContainsString('bin/svnmanage setup-key', $setup);
        $this->install();
        $this->logout();
        $login = $this->get('/login')->body;
        self::assertDoesNotMatchRegularExpression('/svnmanage \d+\.\d+/', $login, 'no version number for anonymous visitors');
    }

    public function testSetupUnlockIsThrottled(): void
    {
        $this->get('/setup');
        for ($i = 0; $i < 20; $i++) {
            $this->post('/setup/unlock', ['key' => 'wrong' . $i]);
            $this->flashes();
        }
        $this->post('/setup/unlock', ['key' => $this->file('setup.key')]);
        self::assertStringContainsString('Too many failed', $this->flashText());
        self::assertSame(403, $this->post('/setup', ['action' => 'defaults'])->status);
    }

    public function testPasswordChangeEndsOtherSessions(): void
    {
        $this->install();
        $this->post('/users', ['username' => 'carol', 'password' => 'Carol12345', 'password2' => 'Carol12345', 'roles' => ['User']]);
        $adminSession = $_SESSION;

        $this->logout();
        $this->login('carol', 'Carol12345');
        $carolSession = $_SESSION;
        $carolSession['login_at'] = time() - 5;
        $_SESSION = $carolSession;
        self::assertSame(200, $this->get('/')->status);

        $_SESSION = $adminSession;
        $this->post('/users/carol/password', ['password' => 'Changed12345', 'password2' => 'Changed12345']);

        $_SESSION = $carolSession;
        $response = $this->get('/');
        self::assertSame(303, $response->status, "carol's old session is gone");
        self::assertStringContainsString('/login', $response->headers['Location'] ?? '');
    }

    public function testOwnPasswordChangeKeepsTheCurrentSession(): void
    {
        $this->install();
        $_SESSION['login_at'] = time() - 5;
        $this->post('/users/admin/password', ['current' => 'Secret123', 'password' => 'Changed12345', 'password2' => 'Changed12345']);
        self::assertSame(200, $this->get('/')->status);
    }

    public function testForceHttpsRedirectsAndMarksCookiesSecure(): void
    {
        $this->install();
        $this->post('/settings', ['Common|ForceHttps' => '1'], ['HTTPS' => 'on']); // refused over plain HTTP (DeploymentHardeningTest)
        $response = $this->get('/users', ['q' => 'a']);
        self::assertSame(302, $response->status, 'temporary: a cached permanent redirect could pin a forged Host');
        self::assertSame('https://localhost/users?q=a', $response->headers['Location'] ?? null);
        self::assertSame(200, $this->get('/users', [], ['HTTPS' => 'on'])->status);
    }

    public function testSettingsCannotPointToolsAtArbitraryPrograms(): void
    {
        $this->install();
        foreach (['/bin/sh', '/tmp/evil', 'svn; rm -rf /', '../svn'] as $exe) {
            $this->post('/settings', ['Repositories:svnclient|SvnExecutable' => $exe]);
            self::assertStringContainsString('must be the', $this->flashText(), $exe);
        }
        $this->post('/settings', ['Repositories:svnclient|SvnExecutable' => '/opt/homebrew/bin/svn']);
        self::assertStringContainsString('SvnExecutable=/opt/homebrew/bin/svn', $this->file('config.ini'));
    }

    public function testSettingsCannotRedirectWritesIntoCode(): void
    {
        $this->install();
        foreach (['/var/www/svnmanage/public/x.php', '/tmp/a.phtml', '/tmp/.htaccess', '/tmp/x.phar'] as $path) {
            $this->post('/settings', ['Subversion|SVNAuthFile' => $path]);
            self::assertStringContainsString('not allowed', $this->flashText(), $path);
        }
    }

    public function testAuthenticationOffOnlyFromLocalhost(): void
    {
        $this->install();
        $this->post('/settings', ['Engine:Providers|AuthenticationStatus' => 'off']);
        self::assertSame(200, $this->get('/', [], ['REMOTE_ADDR' => '127.0.0.1'])->status);
        $remote = $this->get('/', [], ['REMOTE_ADDR' => '203.0.113.50']);
        self::assertSame(403, $remote->status, 'no anonymous admin from the internet');
    }

    public function testAuthOffIsRefusedThroughAnUntrustedLocalProxy(): void
    {
        $this->install();
        $this->post('/settings', ['Engine:Providers|AuthenticationStatus' => 'off']);
        $proxied = $this->get('/', [], ['REMOTE_ADDR' => '127.0.0.1', 'X-Forwarded-For' => '203.0.113.50']);
        self::assertSame(403, $proxied->status, 'a same-host proxy without TrustedProxies must not look local');
    }

    public function testUnknownAuthenticationValueFailsClosed(): void
    {
        $this->install();
        $config = $this->dataDir . '/config.ini';
        file_put_contents($config, (string) preg_replace('/^AuthenticationStatus=.*$/m', 'AuthenticationStatus=basci', (string) file_get_contents($config)));
        $this->logout();
        self::assertSame(303, $this->get('/users')->status, 'a typo must not switch authentication off');
    }

    public function testExecutableMustBeTheWholeFileName(): void
    {
        $this->install();
        foreach (['/usr/bin/xsvn', '/opt/svnadmin/fakesvn', 'mysvn'] as $exe) {
            $this->post('/settings', ['Repositories:svnclient|SvnExecutable' => $exe]);
            self::assertStringContainsString('must be the', $this->flashText(), $exe);
        }
        $this->post('/settings', ['Repositories:svnclient|SvnExecutable' => 'svn']);
        self::assertStringContainsString('SvnExecutable=svn', $this->file('config.ini'));
    }

    public function testLoginFormWorksWithoutAnAnonymousSession(): void
    {
        $this->install();
        $this->logout();
        $_SESSION = [];
        $page = $this->get('/login');
        $cookie = (string) ($page->headers['Set-Cookie'] ?? '');
        self::assertMatchesRegularExpression('/^svnmanage-form=([0-9a-f]{32}); .*HttpOnly; SameSite=Strict/', $cookie);
        preg_match('/^svnmanage-form=([0-9a-f]{32})/', $cookie, $c);
        preg_match('/name="_token" value="([^"]+)"/', $page->body, $t);
        self::assertArrayNotHasKey('_csrf', $_SESSION, 'no session token was minted for an anonymous visitor');

        $server = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost', 'SERVER_PORT' => '80'];
        $form = ['username' => 'admin', 'password' => 'Secret123', '_token' => $t[1]];
        $wrong = $this->app->handle(new Request('POST', '/login', '', [], $form, [], $server, ['svnmanage-form' => str_repeat('0', 32)]));
        self::assertSame(419, $wrong->status, 'the token only works with its own cookie');
        $ok = $this->app->handle(new Request('POST', '/login', '', [], $form, [], $server, ['svnmanage-form' => $c[1]]));
        self::assertSame(303, $ok->status);
        self::assertSame('admin', $_SESSION['user'] ?? null);
        self::assertSame(419, $this->app->handle(new Request('POST', '/users/delete', '', [], ['_token' => $t[1]], [], $server, ['svnmanage-form' => $c[1]]))->status, 'the form token is only for the login form');
        $_SESSION = [];
        $locale = $this->app->handle(new Request('POST', '/locale', '', [], ['_token' => $t[1], 'locale' => 'de_DE'], [], $server, ['svnmanage-form' => $c[1]]));
        self::assertSame(303, $locale->status, 'the language switch on the login page works without a session');
        self::assertStringContainsString('locale=de_DE', (string) ($locale->headers['Set-Cookie'] ?? ''));
    }
}
