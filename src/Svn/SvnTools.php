<?php

declare(strict_types=1);

namespace SvnManage\Svn;

use DateTimeImmutable;
use SimpleXMLElement;

/** Thin adapter over the svn, svnadmin and svnlook executables. */
final class SvnTools
{
    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly string $svn = 'svn',
        private readonly string $svnadmin = 'svnadmin',
        private readonly string $svnlook = 'svnlook',
    ) {
    }

    public static function fileUrl(string $repoPath, string $relative = ''): string
    {
        $segments = array_filter(explode('/', str_replace('\\', '/', $repoPath . '/' . $relative)), static fn (string $s): bool => $s !== '');
        $encoded = array_map(
            static fn (string $s): string => preg_match('/^[A-Za-z]:$/', $s) === 1 ? $s : rawurlencode($s),
            array_values($segments),
        );

        return 'file:///' . implode('/', $encoded);
    }

    public function version(): string
    {
        $result = $this->runner->run([$this->svn, '--version', '--quiet']);

        return $result->ok() ? trim($result->stdout) : '';
    }

    public function create(string $path, string $fsType = 'fsfs'): void
    {
        $argv = [$this->svnadmin, 'create'];
        if ($fsType !== '') {
            $argv = [...$argv, '--fs-type', $fsType];
        }
        $result = $this->runner->run([...$argv, $path]);
        if (!$result->ok()) {
            throw SvnException::fromResult('svnadmin create', $result);
        }
    }

    /** @param callable(string): void $sink */
    public function dump(string $path, callable $sink): void
    {
        $result = $this->runner->run([$this->svnadmin, 'dump', '--quiet', $path], null, $sink);
        if (!$result->ok()) {
            throw SvnException::fromResult('svnadmin dump', $result);
        }
    }

    public function load(string $path, string $dumpFile): string
    {
        $in = fopen($dumpFile, 'rb');
        if ($in === false) {
            throw new SvnException('Cannot read the uploaded dump file.');
        }
        try {
            $result = $this->runner->run([$this->svnadmin, 'load', '--quiet', $path], $in);
        } finally {
            if (is_resource($in)) {
                fclose($in);
            }
        }
        if (!$result->ok()) {
            throw SvnException::fromResult('svnadmin load', $result);
        }

        return trim($result->stdout);
    }

    /** @param list<string> $relativePaths */
    public function mkdir(string $repoPath, array $relativePaths, string $message, ?string $author = null): void
    {
        if ($relativePaths === []) {
            return;
        }
        $urls = array_map(static fn (string $p): string => self::fileUrl($repoPath, $p), $relativePaths);
        $argv = [$this->svn, 'mkdir', '--parents', '--non-interactive', '--quiet', '-m', $message];
        if ($author !== null && $author !== '') {
            $argv = [...$argv, '--username', $author];
        }
        $result = $this->runner->run([...$argv, ...$urls]);
        if (!$result->ok()) {
            throw SvnException::fromResult('svn mkdir', $result);
        }
    }

    /** @return list<BrowseEntry> */
    public function list(string $repoPath, string $relative = ''): array
    {
        $result = $this->runner->run([$this->svn, 'list', '--xml', '--non-interactive', self::fileUrl($repoPath, $relative)]);
        if (!$result->ok()) {
            throw SvnException::fromResult('svn list', $result);
        }
        $xml = self::xml($result->stdout);
        $out = [];
        foreach ($xml->list->entry ?? [] as $entry) {
            $out[] = new BrowseEntry(
                (string) $entry->name,
                (string) $entry['kind'] === 'dir',
                isset($entry->size) ? (int) $entry->size : null,
                (int) ($entry->commit['revision'] ?? 0),
                (string) ($entry->commit->author ?? ''),
                self::date((string) ($entry->commit->date ?? '')),
            );
        }
        usort($out, static fn (BrowseEntry $a, BrowseEntry $b): int => [$b->isDir, strnatcasecmp($a->name, $b->name)] <=> [$a->isDir, 0]);

        return $out;
    }

    /** @return list<LogEntry> */
    public function log(string $repoPath, string $relative = '', int $limit = 20): array
    {
        $result = $this->runner->run([
            $this->svn, 'log', '--xml', '--verbose', '--non-interactive', '--limit', (string) max(1, $limit),
            self::fileUrl($repoPath, $relative),
        ]);
        if (!$result->ok()) {
            throw SvnException::fromResult('svn log', $result);
        }
        $out = [];
        foreach (self::xml($result->stdout)->logentry ?? [] as $entry) {
            $paths = [];
            foreach ($entry->paths->path ?? [] as $p) {
                $paths[] = ['action' => (string) $p['action'], 'path' => (string) $p];
            }
            $out[] = new LogEntry(
                (int) $entry['revision'],
                (string) ($entry->author ?? ''),
                self::date((string) ($entry->date ?? '')),
                trim((string) ($entry->msg ?? '')),
                $paths,
            );
        }

        return $out;
    }

    public function cat(string $repoPath, string $relative, int $maxBytes): string
    {
        $buffer = '';
        $truncated = false;
        $result = $this->runner->run(
            [$this->svn, 'cat', '--non-interactive', self::fileUrl($repoPath, $relative)],
            null,
            static function (string $chunk) use (&$buffer, &$truncated, $maxBytes): void {
                if (strlen($buffer) < $maxBytes) {
                    $buffer .= $chunk;
                } else {
                    $truncated = true;
                }
            },
        );
        if (!$result->ok() && !$truncated) {
            throw SvnException::fromResult('svn cat', $result);
        }

        return substr($buffer, 0, $maxBytes);
    }

    public function info(string $repoPath): RepositoryInfo
    {
        $youngest = $this->runner->run([$this->svnlook, 'youngest', $repoPath]);
        if (!$youngest->ok()) {
            throw SvnException::fromResult('svnlook youngest', $youngest);
        }
        $uuid = $this->runner->run([$this->svnlook, 'uuid', $repoPath]);
        $info = $this->runner->run([$this->svnlook, 'info', $repoPath]);
        $lines = $info->ok() ? explode("\n", $info->stdout) : [];
        $fsType = is_file($repoPath . '/db/fs-type') ? trim((string) file_get_contents($repoPath . '/db/fs-type')) : '';

        return new RepositoryInfo(
            (int) trim($youngest->stdout),
            trim($uuid->stdout),
            self::date(substr($lines[1] ?? '', 0, 25)),
            trim($lines[0] ?? ''),
            $fsType,
            null,
        );
    }

    public static function isRepository(string $dir): bool
    {
        return is_dir($dir) && is_file($dir . '/format') && is_dir($dir . '/db');
    }

    private static function xml(string $text): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($text, SimpleXMLElement::class, LIBXML_NONET);
        } finally {
            libxml_use_internal_errors($previous);
        }
        if ($xml === false) {
            throw new SvnException('Unexpected output from svn (invalid XML).');
        }

        return $xml;
    }

    private static function date(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
