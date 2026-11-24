<?php

declare(strict_types=1);

namespace SvnManage\Svn;

use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SvnManage\Authz\Names;

/** Repository management across one or more SVNParentPath directories. */
final class RepositoryService
{
    public const FS_TYPES = ['fsfs', 'fsx', 'bdb'];
    public const STRUCTURES = ['none', 'single', 'multi'];

    /** @param list<RepositoryParent> $parents */
    public function __construct(
        private readonly array $parents,
        private readonly SvnTools $tools,
        private readonly ?string $cacheDir = null,
    ) {
    }

    /** @return list<RepositoryParent> */
    public function parents(): array
    {
        return $this->parents;
    }

    public function parent(int $id): RepositoryParent
    {
        foreach ($this->parents as $parent) {
            if ($parent->id === $id) {
                return $parent;
            }
        }
        throw new InvalidArgumentException(sprintf('Unknown repository location #%d.', $id));
    }

    /** @return list<Repository> */
    public function repositories(RepositoryParent $parent): array
    {
        if (!is_dir($parent->path)) {
            throw new SvnException(sprintf('The repository parent path does not exist: %s', $parent->path));
        }
        $out = [];
        foreach (scandir($parent->path) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (SvnTools::isRepository($parent->path . '/' . $name)) {
                $out[] = new Repository($name, $parent->id, $parent->path . '/' . $name);
            }
        }
        usort($out, static fn (Repository $a, Repository $b): int => strnatcasecmp($a->name, $b->name));

        return $out;
    }

    /** @return list<Repository> all repositories of all parents */
    public function all(): array
    {
        $out = [];
        foreach ($this->parents as $parent) {
            try {
                $out = [...$out, ...$this->repositories($parent)];
            } catch (SvnException) {
                continue;
            }
        }

        return $out;
    }

    public function find(int $parentId, string $name): Repository
    {
        $parent = $this->parent($parentId);
        $name = Names::repository($name);
        $path = $parent->path . '/' . $name;
        if (!SvnTools::isRepository($path)) {
            throw new InvalidArgumentException(sprintf('The repository "%s" does not exist.', $name));
        }

        return new Repository($name, $parentId, $path);
    }

    /**
     * @param 'none'|'single'|'multi' $structure
     */
    public function create(int $parentId, string $name, string $fsType, string $structure, string $projectName, ?string $author): Repository
    {
        $parent = $this->parent($parentId);
        $name = Names::repository($name);
        if (!in_array($fsType, self::FS_TYPES, true)) {
            throw new InvalidArgumentException('Unknown repository type.');
        }
        if (!is_dir($parent->path)) {
            throw new SvnException(sprintf("The repository parent path doesn't exist: %s", $parent->path));
        }
        $path = $parent->path . '/' . $name;
        if (file_exists($path)) {
            throw new InvalidArgumentException(sprintf('The repository "%s" already exists.', $name));
        }
        $dirs = $this->structureDirs($structure, $projectName);
        $this->tools->create($path, $fsType);
        $repo = new Repository($name, $parentId, $path);
        if ($dirs !== []) {
            $this->tools->mkdir($path, $dirs, 'Initial repository structure.', $author);
        }

        return $repo;
    }

    public function delete(Repository $repo): void
    {
        $parent = $this->parent($repo->parentId);
        $root = realpath($parent->path);
        $target = realpath($repo->path);
        if ($root === false || $target === false || dirname($target) !== $root || !SvnTools::isRepository($target)) {
            throw new SvnException(sprintf('Refusing to delete "%s": not a repository inside %s.', $repo->name, $parent->path));
        }
        self::removeTree($target);
    }

    /** @return list<BrowseEntry> */
    public function browse(Repository $repo, string $relative): array
    {
        return $this->tools->list($repo->path, self::cleanRelative($relative));
    }

    /** @return list<LogEntry> */
    public function log(Repository $repo, string $relative = '', int $limit = 20): array
    {
        return $this->tools->log($repo->path, self::cleanRelative($relative), $limit);
    }

    public function cat(Repository $repo, string $relative, int $maxBytes = 262144): string
    {
        return $this->tools->cat($repo->path, self::cleanRelative($relative), $maxBytes);
    }

    public function info(Repository $repo, bool $withSize = false): RepositoryInfo
    {
        $info = $this->tools->info($repo->path);

        return $withSize
            ? new RepositoryInfo($info->youngest, $info->uuid, $info->lastChanged, $info->lastAuthor, $info->fsType, $this->cachedSize($repo->path, $info->youngest))
            : $info;
    }

    /** @param callable(string): void $sink */
    public function dump(Repository $repo, callable $sink): void
    {
        $this->tools->dump($repo->path, $sink);
    }

    public function load(Repository $repo, string $dumpFile): void
    {
        $this->tools->load($repo->path, $dumpFile);
    }

    public function svnVersion(): string
    {
        return $this->tools->version();
    }

    public static function cleanRelative(string $relative): string
    {
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $relative)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                throw new InvalidArgumentException('Invalid path.');
            }
            $parts[] = $segment;
        }

        return implode('/', $parts);
    }

    /** @return list<string> */
    private function structureDirs(string $structure, string $projectName): array
    {
        return match ($structure) {
            'none', '' => [],
            'single' => ['trunk', 'branches', 'tags'],
            'multi' => (function () use ($projectName): array {
                $project = trim($projectName);
                if ($project === '' || preg_match('/^[^\/\\\\:*?"<>|]+$/', $project) !== 1 || $project === '..' || $project === '.') {
                    throw new InvalidArgumentException('Missing or invalid project name.');
                }

                return [$project . '/trunk', $project . '/branches', $project . '/tags'];
            })(),
            default => throw new InvalidArgumentException('Unknown repository structure.'),
        };
    }

    private static function removeTree(string $dir): void
    {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            $path = $file->getPathname();
            if ($file->isDir() && !$file->isLink()) {
                rmdir($path);
            } else {
                @chmod($path, 0666);
                unlink($path);
            }
        }
        rmdir($dir);
    }

    /**
     * Walking a large repository is expensive, and the size only changes with a new
     * revision, so it is cached per (repository, youngest revision).
     */
    private function cachedSize(string $dir, int $revision): int
    {
        if ($this->cacheDir === null) {
            return self::treeSize($dir);
        }
        $file = $this->cacheDir . '/size-' . sha1($dir) . '.json';
        $cached = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        if (is_array($cached) && ($cached['rev'] ?? null) === $revision && is_int($cached['size'] ?? null)) {
            return $cached['size'];
        }
        $size = self::treeSize($dir);
        if (is_dir($this->cacheDir) || @mkdir($this->cacheDir, 0770, true)) {
            @file_put_contents($file, (string) json_encode(['rev' => $revision, 'size' => $size]), LOCK_EX);
        }

        return $size;
    }

    private static function treeSize(string $dir): int
    {
        $size = 0;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }
}
