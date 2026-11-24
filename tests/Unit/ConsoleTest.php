<?php

declare(strict_types=1);

namespace SvnManage\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SvnManage\App;
use SvnManage\Cli\Console;

final class ConsoleTest extends TestCase
{
    private string $dataDir;

    protected function setUp(): void
    {
        $this->dataDir = sys_get_temp_dir() . '/svnmanage-cli-' . bin2hex(random_bytes(5));
        mkdir($this->dataDir . '/svn', 0777, true);
        touch($this->dataDir . '/svn/passwd');
        file_put_contents($this->dataDir . '/svn/authz', "[groups]\n");
        $tpl = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/config.tpl.ini');
        $tpl = preg_replace('/^SVNAuthFile=.*$/m', 'SVNAuthFile=./data/svn/authz', $tpl);
        $tpl = preg_replace('/^SVNUserFile=.*$/m', 'SVNUserFile=./data/svn/passwd', (string) $tpl);
        file_put_contents($this->dataDir . '/config.ini', (string) $tpl);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dataDir));
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function console(array $argv, string $stdin = ''): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $in = fopen('php://memory', 'w+');
        fwrite($in, $stdin);
        rewind($in);
        $code = (new Console(new App(dirname(__DIR__, 2), $this->dataDir), $out, $err, $in))->run($argv);
        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    public function testHelpAndUnknownCommand(): void
    {
        [$code, $out] = $this->console(['help']);
        self::assertSame(0, $code);
        self::assertStringContainsString('set-admin', $out);
        [$code, , $err] = $this->console(['bogus']);
        self::assertSame(2, $code);
        self::assertStringContainsString('Unknown command', $err);
    }

    public function testPasswdCreatesUserAndSetAdminAssignsRole(): void
    {
        [$code] = $this->console(['passwd', 'root'], "LongEnough123\n");
        self::assertSame(0, $code);
        self::assertStringStartsWith('root:$2y$', (string) file_get_contents($this->dataDir . '/svn/passwd'));

        [$code, $out] = $this->console(['set-admin', 'root']);
        self::assertSame(0, $code);
        self::assertStringContainsString('Administrator', $out);
        self::assertStringContainsString("[root]\nAdministrator=", (string) file_get_contents($this->dataDir . '/userroleassignments.ini'));
    }

    public function testPasswdRejectsShortPasswordAndMissingUser(): void
    {
        [$code, , $err] = $this->console(['passwd', 'root'], "short\n");
        self::assertSame(1, $code);
        self::assertStringContainsString('at least', $err);
        [$code, , $err] = $this->console(['set-admin']);
        self::assertSame(1, $code);
        self::assertStringContainsString('Usage', $err);
    }

    public function testUpdateWithoutDirectoryFails(): void
    {
        [$code, , $err] = $this->console(['update', '--dry-run']);
        self::assertSame(1, $code);
        self::assertStringContainsString('No LDAP or Active Directory', $err);
    }

    public function testCheckReportsExposedFiles(): void
    {
        chmod($this->dataDir . '/svn/passwd', 0640);
        chmod($this->dataDir . '/svn/authz', 0640);
        chmod($this->dataDir, 0750);
        chmod($this->dataDir . '/config.ini', 0640); // written with the default umask, like a copied iF.SVNAdmin config
        [, $out] = $this->console(['check']);
        self::assertStringNotContainsString('⚠', $out, 'private files outside any web root: no warnings');

        chmod($this->dataDir . '/svn/passwd', 0644);
        [$code, $out] = $this->console(['check', '--docroot=' . $this->dataDir . '/svn']);
        self::assertSame(1, $code);
        self::assertStringContainsString('⚠ User file: ' . realpath($this->dataDir . '/svn/passwd') . ' can be read by every account', $out);
        self::assertStringContainsString('is inside the folder the web server publishes (' . realpath($this->dataDir . '/svn') . ')', $out);
        self::assertStringContainsString('security warning', $out);
    }

    public function testTranslatorFillsPlaceholdersInOnePass(): void
    {
        $tr = \SvnManage\I18n\Translator::load(dirname(__DIR__, 2) . '/lang', 'en_US');
        self::assertSame('a %1 b x', $tr->t('%0 b %1', ['a %1', 'x']));
    }

    public function testTwoFactorResetRemovesTheSecret(): void
    {
        file_put_contents($this->dataDir . '/twofactor.ini', "[root]\nsecret=JBSWY3DPEHPK3PXP\nlast_step=1\n");
        [$code, $out] = $this->console(['2fa-reset', 'root']);
        self::assertSame(0, $code);
        self::assertStringContainsString('off for root', $out);
        self::assertStringNotContainsString('[root]', (string) file_get_contents($this->dataDir . '/twofactor.ini'));
    }
}
