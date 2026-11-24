<?php

declare(strict_types=1);

namespace SvnManage\Tests\Http;

use SvnManage\Http\Response;

/** The setup wizard: 1) connect Subversion  2) secure the site  3) choose an administrator. */
final class SetupWizardTest extends HttpTestCase
{
    /** Unlocks the wizard and creates the local Subversion setup (end of step 1). */
    private function connect(): void
    {
        $this->get('/setup');
        $this->post('/setup/unlock', ['key' => $this->file('setup.key')]);
        $this->post('/setup', ['action' => 'defaults']);
        $this->flashes();
    }

    /** @param array<string, string> $fields @param array<string, string> $headers */
    private function secure(array $fields = [], array $headers = []): Response
    {
        return $this->post('/setup', ['action' => 'security', ...$fields], $headers);
    }

    private function selected(string $body, string $name): ?string
    {
        $select = preg_match('#<select[^>]*name="' . preg_quote($name, '#') . '"[^>]*>(.*?)</select>#s', $body, $m) === 1 ? $m[1] : '';

        return preg_match('#<option value="([^"]*)" selected#', $select, $o) === 1 ? $o[1] : null;
    }

    private function value(string $body, string $name): ?string
    {
        return preg_match('#name="' . preg_quote($name, '#') . '" value="([^"]*)"#', $body, $m) === 1 ? $m[1] : null;
    }

    private function checked(string $body, string $name): bool
    {
        return preg_match('#type="checkbox"[^>]*name="' . preg_quote($name, '#') . '" value="1" checked#', $body) === 1;
    }

    public function testSecurityIsItsOwnStepBetweenConnectingAndTheAdministrator(): void
    {
        $this->connect();
        $page = $this->get('/setup')->body;

        self::assertStringContainsString('Secure the site', $page);
        self::assertStringContainsString('Public internet', $page);
        self::assertStringNotContainsString('Create the administrator account', $page);
        self::assertStringNotContainsString('name="Subversion|SVNAuthFile"', $page, 'step 2 shows only the security settings');
    }

    public function testTheAdministratorStepCannotSkipTheSecurityStep(): void
    {
        $this->connect();
        $response = $this->post('/setup/admin', ['mode' => 'create', 'username' => 'eve', 'password' => 'LongEnough12345', 'password2' => 'LongEnough12345']);

        self::assertSame('/setup', $response->headers['Location'] ?? null);
        self::assertStringNotContainsString('[eve]', $this->file('userroleassignments.ini'));
        self::assertFileExists($this->dataDir . '/setup.key', 'setup is not finished');
    }

    public function testThePublicProfileIsTheDefaultAndIsStrict(): void
    {
        $this->connect();
        $page = $this->get('/setup')->body;

        self::assertSame('admins', $this->selected($page, 'Common|TwoFactor'));
        self::assertSame('1800', $this->value($page, 'Common|SessionTimeout'));
        self::assertSame('28800', $this->value($page, 'Common|SessionMaxLifetime'));
        self::assertSame('12', $this->value($page, 'Common|MinPasswordLength'));
        self::assertFalse($this->checked($page, 'Common|ForceHttps'), 'over plain HTTP, Require HTTPS would lock everyone out');
        self::assertTrue($this->checked($this->get('/setup', [], ['HTTPS' => 'on'])->body, 'Common|ForceHttps'), 'over HTTPS it is on');
    }

    public function testThePrivateProfileIsMoreRelaxed(): void
    {
        $this->connect();
        $page = $this->get('/setup', ['profile' => 'private'])->body;

        self::assertSame('optional', $this->selected($page, 'Common|TwoFactor'));
        self::assertSame('3600', $this->value($page, 'Common|SessionTimeout'));
        $unknown = $this->get('/setup', ['profile' => '<script>x'])->body;
        self::assertSame('admins', $this->selected($unknown, 'Common|TwoFactor'), 'unknown profiles fall back to public');
        self::assertStringNotContainsString('<script>x', $unknown);
    }

    public function testSavingTheSecurityStepWritesTheSettingsAndMovesOn(): void
    {
        $this->connect();
        $this->secure(['Common|TwoFactor' => 'admins', 'Common|SessionTimeout' => '1800', 'Common|MinPasswordLength' => '14', 'Common|ForceHttps' => '0']);
        $config = $this->file('config.ini');

        self::assertStringContainsString('TwoFactor=admins', $config);
        self::assertStringContainsString('SessionTimeout=1800', $config);
        self::assertStringContainsString('MinPasswordLength=14', $config);
        self::assertStringContainsString('SVNAuthFile=./data/svn/authz', $config, 'the Subversion settings from step 1 are kept');
        $page = $this->get('/setup')->body;
        self::assertStringContainsString('Create the administrator account', $page);
        self::assertStringContainsString('minlength="14"', $page, 'the new password rule already applies to the first administrator');
    }

    public function testTheSecurityStepShowsWhatSvnmanageSees(): void
    {
        $this->connect();
        $plain = $this->get('/setup', [], ['REMOTE_ADDR' => '198.51.100.7'])->body;
        self::assertStringContainsString('opened over plain HTTP', $plain);
        self::assertStringContainsString('198.51.100.7', $plain);

        $https = $this->get('/setup', [], ['HTTPS' => 'on'])->body;
        self::assertStringContainsString('opened over HTTPS', $https);

        $tunnel = $this->get('/setup', [], ['REMOTE_ADDR' => '198.51.100.7', 'X-Forwarded-Proto' => 'https'])->body;
        self::assertStringContainsString('reports HTTPS, but svnmanage does not trust it', $tunnel);

        $exposed = $this->get('/setup', [], ['DOCUMENT_ROOT' => $this->dataDir])->body;
        self::assertStringContainsString('is inside the folder the web server publishes', $exposed);
        self::assertStringNotContainsString('is inside the folder the web server publishes', $plain);
    }

    public function testTheSecurityStepKeepsTheLockoutGuards(): void
    {
        $this->connect();
        $this->secure(['Common|ForceHttps' => '1']);
        self::assertStringContainsString('would redirect forever', $this->flashText());
        $this->secure(['Common|AllowedNetworks' => '10.0.0.0/8'], ['REMOTE_ADDR' => '198.51.100.7']);
        self::assertStringContainsString('lock you out', $this->flashText());

        self::assertStringContainsString('Secure the site', $this->get('/setup')->body, 'still on the security step');
        self::assertStringNotContainsString('ForceHttps=true', $this->file('config.ini'));
    }

    public function testBackLinksReturnToTheEarlierSteps(): void
    {
        $this->connect();
        $this->secure(['Common|TwoFactor' => 'optional']);
        $this->post('/setup', ['action' => 'resecure']);
        self::assertStringContainsString('Secure the site', $this->get('/setup')->body);

        $this->post('/setup', ['action' => 'reconfigure']);
        self::assertStringContainsString('Use local defaults', $this->get('/setup')->body);
        $this->post('/setup', ['action' => 'defaults']);
        self::assertStringContainsString('Secure the site', $this->get('/setup')->body, 'reconnecting asks about security again');
    }

    public function testAnAdministratorCreatedUnderThePublicProfileMustEnrolTwoFactor(): void
    {
        $this->connect();
        $this->secure(['Common|TwoFactor' => 'admins']);
        $this->post('/setup/admin', ['mode' => 'create', 'username' => 'root', 'password' => 'LongEnough12345', 'password2' => 'LongEnough12345']);

        $home = $this->get('/');
        self::assertSame(303, $home->status);
        self::assertStringEndsWith('/account/two-factor', $home->headers['Location'] ?? '');
    }

    public function testFormsThatLeaveOutFieldsKeepThoseSettings(): void
    {
        $this->install();
        $before = $this->file('config.ini');
        self::assertMatchesRegularExpression('/^SessionTimeout=\d+$/m', $before);
        $this->post('/settings', ['Repositories:svnclient|SvnExecutable' => 'svn']);
        $after = $this->file('config.ini');

        foreach (['SessionTimeout', 'LoginLockSeconds', 'RepositoryDeleteEnabled', 'ForceHttps', 'HttpProxyAuth'] as $key) {
            preg_match('/^' . $key . '=.*$/m', $before, $was);
            preg_match('/^' . $key . '=.*$/m', $after, $is);
            self::assertSame($was[0] ?? null, $is[0] ?? null, $key . ' must not change when the form leaves it out');
        }
    }
}
