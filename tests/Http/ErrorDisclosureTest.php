<?php

declare(strict_types=1);

namespace SvnManage\Tests\Http;

/** Server-side error details (file paths, provider output) reach administrators only. */
final class ErrorDisclosureTest extends HttpTestCase
{
    private const GENERIC = 'The operation failed. The details were written to the server log.';

    private string $missing;
    private string $logFile;
    private string $previousLog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->install();
        $this->post('/users', ['username' => 'apm', 'password' => 'Manager123', 'password2' => 'Manager123', 'roles' => ['Access-Path-Manager']]);
        $this->flashes();
        $this->missing = $this->dataDir . '/nowhere/secret-location';
        $this->logFile = $this->dataDir . '/php-error.log';
        $this->previousLog = (string) ini_get('error_log');
        ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousLog);
        parent::tearDown();
    }

    private function pointSetting(string $key, string $path): void
    {
        $config = $this->dataDir . '/config.ini';
        $ini = (string) preg_replace('/^' . preg_quote($key, '/') . '=.*$/m', $key . '=' . $path, (string) file_get_contents($config));
        file_put_contents($config, $ini);
    }

    private function signInAsManager(): void
    {
        $this->logout();
        self::assertSame(303, $this->login('apm', 'Manager123')->status);
        $this->flashes();
    }

    public function testDashboardHidesProviderErrorsFromNonAdministrators(): void
    {
        $this->pointSetting('SVNAuthFile', $this->missing);
        $this->signInAsManager();

        $body = $this->get('/')->body;

        self::assertStringNotContainsString('secret-location', $body);
        self::assertStringContainsString(self::GENERIC, $body);
        self::assertStringContainsString('secret-location', (string) @file_get_contents($this->logFile), 'details go to the server log');
    }

    public function testDashboardShowsProviderErrorsToAdministrators(): void
    {
        $this->pointSetting('SVNAuthFile', $this->missing);

        self::assertStringContainsString('secret-location', $this->get('/')->body);
    }

    public function testPermissionPagesHideUserFileErrorsFromNonAdministrators(): void
    {
        $this->pointSetting('SVNUserFile', $this->missing);
        $this->signInAsManager();

        $body = $this->get('/permissions')->body . $this->flashText();

        self::assertStringNotContainsString('secret-location', $body);
    }

    public function testRoleAndGroupPagesHideUserFileErrorsFromNonAdministrators(): void
    {
        $this->post('/users', ['username' => 'ugm', 'password' => 'Manager123', 'password2' => 'Manager123', 'roles' => ['User-Group-Manager', 'Role-Manager']]);
        $this->post('/groups', ['groupname' => 'team']);
        $this->flashes();
        $this->pointSetting('SVNUserFile', $this->missing);
        $this->logout();
        $this->login('ugm', 'Manager123');
        $this->flashes();

        foreach (['/roles', '/groups/team', '/groups/memberships'] as $page) {
            $body = $this->get($page)->body . $this->flashText();
            self::assertStringNotContainsString('secret-location', $body, $page);
        }
    }
}
