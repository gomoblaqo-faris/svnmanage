<?php

declare(strict_types=1);

namespace SvnManage\Tests\Http;

use SvnManage\Http\Response;

/** Repository pages end to end against real svnadmin/svnlook (skipped when not installed). */
final class RepositoryFlowTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['svn', 'svnadmin', 'svnlook'] as $bin) {
            if (trim((string) shell_exec('command -v ' . $bin)) === '') {
                self::markTestSkipped("$bin not installed");
            }
        }
        $this->install();
        $config = $this->dataDir . '/config.ini';
        file_put_contents($config, (string) preg_replace('/^(Repository(Dump|Load|Delete)Enabled)=false$/m', '$1=true', (string) file_get_contents($config)));
    }

    private function createRepo(string $name, string $structure = 'single'): string
    {
        $response = $this->post('/repositories', ['reponame' => $name, 'pi' => '0', 'repotype' => 'fsfs', 'repostructuretype' => $structure, 'accesspathcreate' => '1']);
        self::assertSame('/repositories/0/' . $name, $response->headers['Location'] ?? null, $this->flashText());
        $this->flashes();
        $paths = glob($this->dataDir . '/**/' . $name . '/format') ?: glob($this->dataDir . '/*/*/' . $name . '/format') ?: [];
        self::assertNotEmpty($paths, 'repository folder exists');

        return dirname($paths[0]);
    }

    private function commitFile(string $repoPath, string $path, string $content): void
    {
        $src = $this->dataDir . '/import-' . bin2hex(random_bytes(3));
        mkdir($src);
        file_put_contents($src . '/' . basename($path), $content);
        $url = 'file://' . $repoPath . '/' . dirname($path);
        shell_exec('svn import -q -m add ' . escapeshellarg($src) . ' ' . escapeshellarg($url) . ' 2>&1');
    }

    private static function streamed(Response $response): string
    {
        $streamer = (new \ReflectionProperty(Response::class, 'streamer'))->getValue($response);
        self::assertIsCallable($streamer);
        ob_start();
        $streamer();

        return (string) ob_get_clean();
    }

    public function testCreateShowBrowseReadDumpAndDelete(): void
    {
        self::assertSame(200, $this->get('/repositories/new')->status);
        $path = $this->createRepo('website');
        self::assertStringContainsString('[website:/]', $this->file('svn/authz'), 'root access path created');
        $this->commitFile($path, 'trunk/README.txt', "hello <b>world</b>\n");

        $list = $this->get('/repositories', ['q' => 'web'])->body;
        self::assertStringContainsString('/repositories/0/website', $list);
        self::assertStringContainsString('id="dump-0-website"', $list, 'dump is a CSRF-protected form');

        $show = $this->get('/repositories/0/website')->body;
        self::assertStringContainsString('Size on disk', $show);
        self::assertNotEmpty(glob($this->dataDir . '/cache/size-*.json'), 'size is cached');

        $root = $this->get('/repositories/0/website/browse')->body;
        self::assertStringContainsString('trunk/', $root);
        $trunk = $this->get('/repositories/0/website/browse', ['path' => 'trunk'])->body;
        self::assertStringContainsString('README.txt', $trunk);
        self::assertNotSame(200, $this->get('/repositories/0/website/browse', ['path' => '../../etc'])->status, 'no traversal out of the repository');

        $view = $this->get('/repositories/0/website/file', ['path' => 'trunk/README.txt']);
        self::assertSame(200, $view->status);
        self::assertStringContainsString('hello &lt;b&gt;world&lt;/b&gt;', $view->body, 'file contents are escaped');
        self::assertStringNotContainsString('<b>world</b>', $view->body);
        $raw = $this->get('/repositories/0/website/file', ['path' => 'trunk/README.txt', 'raw' => '1']);
        self::assertSame("hello <b>world</b>\n", $raw->body);
        self::assertSame('application/octet-stream', $raw->headers['Content-Type'] ?? null, 'never rendered by the browser');
        self::assertStringStartsWith('attachment; filename="README.txt"', $raw->headers['Content-Disposition'] ?? '');

        self::assertSame(419, $this->post('/repositories/0/website/dump', [], [], false)->status, 'dump without CSRF token is refused');
        $dump = $this->post('/repositories/0/website/dump');
        self::assertSame(200, $dump->status);
        self::assertMatchesRegularExpression('/^attachment; filename="website-\d{8}-\d{6}\.dump"; filename\*=UTF-8\'\'website-/', $dump->headers['Content-Disposition'] ?? '');
        self::assertStringStartsWith('SVN-fs-dump-format-version', self::streamed($dump));

        $load = $this->post('/repositories/0/website/load');
        self::assertSame(303, $load->status);
        self::assertStringContainsString('upload failed', $this->flashText());

        $this->post('/repositories/delete', ['pi' => '0', 'selected_repos' => ['website'], 'delete_ap' => '1']);
        self::assertStringContainsString('has been deleted', $this->flashText());
        self::assertDirectoryDoesNotExist($path);
        self::assertStringNotContainsString('[website:/]', $this->file('svn/authz'));
        self::assertSame(404, $this->get('/repositories/0/website')->status);
    }

    public function testInvalidAndDuplicateNamesAreRefused(): void
    {
        $this->createRepo('one', 'none');
        foreach (['one', '../escape', 'bad name', ''] as $name) {
            $response = $this->post('/repositories', ['reponame' => $name, 'pi' => '0', 'repotype' => 'fsfs', 'repostructuretype' => 'none']);
            self::assertSame('/repositories/new', $response->headers['Location'] ?? null, $name);
            $this->flashes();
        }
        self::assertDirectoryDoesNotExist(dirname($this->createRepoPathGuess()) . '/escape');
        $this->post('/repositories/delete', ['pi' => '0']);
        self::assertStringContainsString('select at least one', $this->flashText());
    }

    private function createRepoPathGuess(): string
    {
        return (glob($this->dataDir . '/*/*/one/format') ?: glob($this->dataDir . '/**/one/format') ?: [''])[0];
    }
}
