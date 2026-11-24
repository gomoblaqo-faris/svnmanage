<?php

declare(strict_types=1);

namespace SvnManage\Tests\Integration;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SvnManage\Svn\ProcessRunner;
use SvnManage\Svn\RepositoryParent;
use SvnManage\Svn\RepositoryService;
use SvnManage\Svn\SvnException;
use SvnManage\Svn\SvnTools;

final class RepositoryServiceTest extends TestCase
{
    private string $root;
    private RepositoryService $service;

    protected function setUp(): void
    {
        foreach (['svn', 'svnadmin', 'svnlook'] as $bin) {
            if (trim((string) shell_exec('command -v ' . $bin)) === '') {
                self::markTestSkipped("$bin not installed");
            }
        }
        $this->root = sys_get_temp_dir() . '/svnmanage-repo-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/a', 0777, true);
        mkdir($this->root . '/b', 0777, true);
        $this->service = new RepositoryService(
            [new RepositoryParent(0, $this->root . '/a', 'Main'), new RepositoryParent(1, $this->root . '/b', 'Second')],
            new SvnTools(new ProcessRunner(60)),
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->root) && is_dir($this->root)) {
            shell_exec('rm -rf ' . escapeshellarg($this->root));
        }
    }

    public function testCreateBrowseLogInfoDumpLoadDelete(): void
    {
        $repo = $this->service->create(0, 'demo', 'fsfs', 'multi', 'My Project', 'alice');
        self::assertSame(['demo'], array_map(static fn ($r) => $r->name, $this->service->repositories($this->service->parent(0))));

        $top = $this->service->browse($repo, '');
        self::assertSame('My Project', $top[0]->name);
        self::assertTrue($top[0]->isDir);
        self::assertSame(['branches', 'tags', 'trunk'], array_map(static fn ($e) => $e->name, $this->service->browse($repo, 'My Project')));

        $log = $this->service->log($repo);
        self::assertSame(1, $log[0]->revision);
        self::assertSame('alice', $log[0]->author);
        self::assertCount(4, $log[0]->paths);

        $info = $this->service->info($repo, true);
        self::assertSame(1, $info->youngest);
        self::assertSame('fsfs', $info->fsType);
        self::assertGreaterThan(0, $info->sizeBytes);

        $dump = '';
        $this->service->dump($repo, static function (string $c) use (&$dump): void {
            $dump .= $c;
        });
        self::assertStringStartsWith('SVN-fs-dump-format-version', $dump);

        $copy = $this->service->create(1, 'copy', 'fsfs', 'none', '', null);
        $file = $this->root . '/dump';
        file_put_contents($file, $dump);
        $this->service->load($copy, $file);
        self::assertSame(1, $this->service->info($copy)->youngest);

        $this->service->delete($repo);
        self::assertSame([], $this->service->repositories($this->service->parent(0)));
        self::assertCount(1, $this->service->all());
    }

    public function testSingleStructureAndValidation(): void
    {
        $repo = $this->service->create(0, 'single', 'fsfs', 'single', '', null);
        self::assertCount(3, $this->service->browse($repo, '/'));
        $this->expectException(InvalidArgumentException::class);
        $this->service->create(0, '../evil', 'fsfs', 'none', '', null);
    }

    public function testPathTraversalRejected(): void
    {
        $repo = $this->service->create(0, 'r', 'fsfs', 'none', '', null);
        $this->expectException(InvalidArgumentException::class);
        $this->service->browse($repo, 'a/../../etc');
    }

    public function testMissingParentReportsError(): void
    {
        $svc = new RepositoryService([new RepositoryParent(0, '/nonexistent/x', 'x')], new SvnTools(new ProcessRunner()));
        $this->expectException(SvnException::class);
        $svc->repositories($svc->parent(0));
    }

    public function testFileUrlEncoding(): void
    {
        self::assertSame('file:///tmp/a%20b/My%20Project/trunk', SvnTools::fileUrl('/tmp/a b', 'My Project/trunk'));
    }

    public function testRepositorySizeIsCachedPerRevision(): void
    {
        $cache = $this->root . '/cache';
        $service = new RepositoryService([new RepositoryParent(0, $this->root . '/a', 'Main')], new SvnTools(new ProcessRunner(60)), $cache);
        $repo = $service->create(0, 'sized', 'fsfs', 'single', '', 'admin');
        $size = $service->info($repo, true)->sizeBytes;
        self::assertGreaterThan(0, $size);
        $files = glob($cache . '/size-*.json') ?: [];
        self::assertCount(1, $files);
        $cached = json_decode((string) file_get_contents($files[0]), true);
        file_put_contents($files[0], json_encode(['rev' => $cached['rev'], 'size' => 42]));
        self::assertSame(42, $service->info($repo, true)->sizeBytes, 'served from cache while the revision is unchanged');
    }

    public function testBufferedOutputIsCapped(): void
    {
        $result = (new ProcessRunner(30, 1000))->run(['/bin/sh', '-c', 'yes x | head -c 50000']);
        self::assertLessThanOrEqual(1000, strlen($result->stdout));
        self::assertStringContainsString('too large', $result->stderr);
    }
}
