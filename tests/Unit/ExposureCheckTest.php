<?php

declare(strict_types=1);

namespace SvnManage\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SvnManage\Security\ExposureCheck;
use SvnManage\Security\ManagedPath;

/** Files svnmanage manages must not be published by the web server or open to every local account. */
final class ExposureCheckTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/svnmanage-exposure-' . bin2hex(random_bytes(5));
        mkdir($this->dir . '/web', 0750, true);
        mkdir($this->dir . '/private', 0750, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function file(string $relative, int $mode): string
    {
        $path = $this->dir . '/' . $relative;
        file_put_contents($path, 'x');
        chmod($path, $mode);

        return $path;
    }

    /** @return list<string> the messages with their arguments filled in */
    private function check(array $paths, array $webRoots): array
    {
        return array_map(
            static fn (array $w): string => ExposureCheck::format($w[0], $w[1]),
            ExposureCheck::run($paths, $webRoots),
        );
    }

    public function testFlagsManagedFilesInsideTheWebRoot(): void
    {
        $users = $this->file('web/.users', 0640);
        $repos = $this->dir . '/web/repos';
        mkdir($repos, 0750);
        $warnings = $this->check([new ManagedPath('User file', $users, true), new ManagedPath('Repository folder', $repos, false)], [$this->dir . '/web']);

        self::assertCount(2, $warnings);
        self::assertStringContainsString('User file: ' . realpath($users) . ' is inside the folder the web server publishes (' . realpath($this->dir . '/web') . ')', $warnings[0]);
        self::assertStringStartsWith('Repository folder: ', $warnings[1]);
    }

    public function testTheWebRootItselfAndSymlinkedRootsCount(): void
    {
        symlink($this->dir . '/private', $this->dir . '/web/hidden');
        $authz = $this->file('private/authz', 0640);

        self::assertCount(1, $this->check([new ManagedPath('Authz file', $authz, false)], [$this->dir . '/web/hidden']), 'reached through a symlinked web root');
        self::assertCount(1, $this->check([new ManagedPath('Repository folder', $this->dir . '/web', false)], [$this->dir . '/web']), 'the root itself');
    }

    public function testFilesOutsideTheWebRootAreFine(): void
    {
        $users = $this->file('private/users', 0640);
        mkdir($this->dir . '/web-other', 0750);

        self::assertSame([], $this->check([new ManagedPath('User file', $users, true)], [$this->dir . '/web', $this->dir . '/web-other', '']));
    }

    public function testFlagsSecretsReadableByEveryone(): void
    {
        $users = $this->file('private/users', 0644);
        $authz = $this->file('private/authz', 0644);
        $warnings = $this->check([new ManagedPath('User file', $users, true), new ManagedPath('Authz file', $authz, false)], []);

        self::assertCount(1, $warnings, 'only secrets need to be private; an authz file may be world-readable');
        self::assertStringContainsString('can be read by every account on this server', $warnings[0]);
    }

    public function testFlagsAnythingWritableByEveryone(): void
    {
        $authz = $this->file('private/authz', 0666);
        mkdir($this->dir . '/private/backup', 0777);
        chmod($this->dir . '/private/backup', 0777);
        $warnings = $this->check([new ManagedPath('Authz file', $authz, false), new ManagedPath('Backup folder', $this->dir . '/private/backup', false)], []);

        self::assertCount(2, $warnings);
        foreach ($warnings as $w) {
            self::assertStringContainsString('can be changed by every account on this server', $w);
        }
    }

    public function testWindowsPathsCompareBySegmentAndIgnoringCase(): void
    {
        self::assertTrue(ExposureCheck::isWithin('C:\\Sites\\Svn\\.users', 'c:\\sites\\svn', true));
        self::assertTrue(ExposureCheck::isWithin('C:\\Sites\\Svn', 'C:\\Sites\\Svn\\', true));
        self::assertFalse(ExposureCheck::isWithin('C:\\Sites\\Svn-other\\x', 'C:\\Sites\\Svn', true));
        self::assertFalse(ExposureCheck::isWithin('/srv/Web/x', '/srv/web', false), 'case matters elsewhere');
        self::assertTrue(ExposureCheck::isWithin('/srv/web/x', '/', false), 'the filesystem root contains everything');
    }

    public function testPathsWithPlaceholderLikeTextStayIntact(): void
    {
        $users = $this->file('private/users%1', 0644);
        $warnings = $this->check([new ManagedPath('User file', $users, true)], []);

        self::assertStringContainsString(realpath($users) . ' can be read', $warnings[0]);
    }

    public function testMissingAndEmptyPathsAreIgnored(): void
    {
        self::assertSame([], $this->check([new ManagedPath('User file', '', true), new ManagedPath('Authz file', $this->dir . '/nope', false)], [$this->dir . '/web']));
    }
}
