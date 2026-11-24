<?php

declare(strict_types=1);

namespace SvnManage\Store;

use RuntimeException;
use SvnManage\Authz\AuthzFile;
use SvnManage\Support\FileStore;

/** Loads and atomically updates the Subversion authz file. */
final class AuthzStore
{
    private ?AuthzFile $cached = null;

    public function __construct(
        private readonly FileStore $files,
        public readonly string $path,
    ) {
    }

    public function load(): AuthzFile
    {
        if ($this->cached !== null) {
            return $this->cached;
        }
        if ($this->path === '') {
            throw new RuntimeException('No SVN authorization file (SVNAuthFile) configured. Configure it in the settings.');
        }
        if (!is_file($this->path)) {
            throw new RuntimeException(sprintf('The SVN authorization file does not exist: %s', $this->path));
        }

        return $this->cached = AuthzFile::parse($this->files->read($this->path));
    }

    /** @param callable(AuthzFile): AuthzFile $change */
    public function update(callable $change): AuthzFile
    {
        if ($this->path === '') {
            throw new RuntimeException('No SVN authorization file (SVNAuthFile) configured.');
        }
        $result = null;
        $this->files->update($this->path, static function (string $current) use ($change, &$result): string {
            $authz = trim($current) === '' ? AuthzFile::empty() : AuthzFile::parse($current);
            $result = $change($authz);

            return $result->render();
        });
        $this->cached = $result;

        return $result;
    }
}
