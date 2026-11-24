<?php

declare(strict_types=1);

namespace SvnManage\Tests\Http;

/**
 * Lessons from a real deployment: TLS ended at a tunnel whose address the web server had
 * already replaced (mod_remoteip), and the old document root also held the repositories
 * and the password file.
 */
final class DeploymentHardeningTest extends HttpTestCase
{
    public function testRequireHttpsIsRefusedWhenThisRequestIsNotSeenAsHttps(): void
    {
        $this->install();
        $this->post('/settings', ['Common|ForceHttps' => '1']);

        self::assertStringContainsString('would redirect forever', $this->flashText());
        self::assertStringNotContainsString('ForceHttps=true', $this->file('config.ini'));
        self::assertSame(200, $this->get('/users')->status, 'still reachable: nothing was locked');
    }

    public function testRequireHttpsCanBeTurnedOnOverHttps(): void
    {
        $this->install();
        $this->post('/settings', ['Common|ForceHttps' => '1'], ['HTTPS' => 'on']);

        self::assertStringContainsString('ForceHttps=true', $this->file('config.ini'));
    }

    public function testRequireHttpsAndItsProxyCanBeSavedTogether(): void
    {
        $this->install();
        $this->post(
            '/settings',
            ['Common|ForceHttps' => '1', 'Common|TrustedProxies' => '127.0.0.1'],
            ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '127.0.0.1'],
        );

        self::assertStringContainsString('ForceHttps=true', $this->file('config.ini'), 'the proxy listed in the same form makes the request HTTPS');
    }

    public function testRemovingTheProxyThatMakesRequestsHttpsIsRefused(): void
    {
        $this->install();
        $proxied = ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '127.0.0.1'];
        $this->post('/settings', ['Common|ForceHttps' => '1', 'Common|TrustedProxies' => '127.0.0.1'], $proxied);
        $this->flashes();
        $this->post('/settings', ['Common|ForceHttps' => '1', 'Common|TrustedProxies' => ''], $proxied);

        self::assertStringContainsString('would redirect forever', $this->flashText());
        self::assertStringContainsString('TrustedProxies=127.0.0.1', $this->file('config.ini'));
    }

    public function testAnUntrustedProxyClaimingHttpsGetsAnExplanationNotARedirectLoop(): void
    {
        $this->install();
        $this->post('/settings', ['Common|ForceHttps' => '1'], ['HTTPS' => 'on']);

        $looping = $this->get('/users', [], ['REMOTE_ADDR' => '203.0.113.9', 'X-Forwarded-Proto' => 'https']);
        self::assertSame(403, $looping->status);
        self::assertArrayNotHasKey('Location', $looping->headers);
        self::assertStringContainsString('could not be confirmed as HTTPS', $looping->body);
        self::assertStringNotContainsString('TrustedProxies', $looping->body, 'the fix is for the error log, not for visitors');

        $plain = $this->get('/users', [], ['REMOTE_ADDR' => '203.0.113.9']);
        self::assertSame(302, $plain->status, 'a plain HTTP visitor is still sent to HTTPS');
    }

    public function testDashboardWarnsAdminsAboutFilesTheWebServerPublishes(): void
    {
        $this->install();
        $body = $this->get('/', [], ['DOCUMENT_ROOT' => $this->dataDir])->body;

        self::assertStringContainsString('is inside the folder the web server publishes', $body);
        self::assertStringContainsString(realpath($this->dataDir . '/svn/passwd'), $body);
        self::assertStringNotContainsString('the web server publishes', $this->get('/', [], ['DOCUMENT_ROOT' => sys_get_temp_dir() . '/svnmanage-none'])->body);
    }

    public function testDashboardWarnsAdminsAboutPasswordHashesEveryoneCanRead(): void
    {
        $this->install();
        chmod($this->dataDir . '/svn/passwd', 0644);

        self::assertStringContainsString('can be read by every account on this server', $this->get('/')->body);
    }
}
