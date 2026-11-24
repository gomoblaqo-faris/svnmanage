<?php

declare(strict_types=1);

namespace SvnManage\Users;

use RuntimeException;
use SvnManage\Support\FileStore;

/** htdigest-backed users (one realm). */
final class DigestUsers implements UserProvider, UserEditor
{
    private ?HtdigestFile $cached = null;

    public function __construct(
        private readonly FileStore $files,
        private readonly string $path,
        private readonly string $realm,
    ) {
    }

    public function kind(): string
    {
        return 'digest';
    }

    public function users(): array
    {
        $names = $this->file()->users();
        natcasesort($names);

        return array_values(array_map(static fn (string $n): User => new User($n), $names));
    }

    public function exists(string $name): bool
    {
        return $this->file()->has($name);
    }

    public function authenticate(string $name, string $password): bool
    {
        return $password !== '' && $this->file()->verify($name, $password);
    }

    public function create(string $name, string $password): void
    {
        $this->change(static fn (HtdigestFile $f): HtdigestFile => $f->withUser($name, $password));
    }

    public function delete(string $name): void
    {
        $this->change(static fn (HtdigestFile $f): HtdigestFile => $f->withoutUser($name));
    }

    public function changePassword(string $name, string $password): void
    {
        $this->change(static fn (HtdigestFile $f): HtdigestFile => $f->withPassword($name, $password));
    }

    private function file(): HtdigestFile
    {
        if ($this->cached === null) {
            if ($this->path === '' || !is_file($this->path)) {
                throw new RuntimeException(sprintf('The digest user file does not exist: %s', $this->path === '' ? '(not configured)' : $this->path));
            }
            $this->cached = HtdigestFile::parse($this->files->read($this->path), $this->realm);
        }

        return $this->cached;
    }

    /** @param callable(HtdigestFile): HtdigestFile $fn */
    private function change(callable $fn): void
    {
        if ($this->path === '') {
            throw new RuntimeException('No digest user file (SVNUserDigestFile) configured.');
        }
        $result = null;
        $realm = $this->realm;
        $this->files->update($this->path, static function (string $current) use ($fn, $realm, &$result): string {
            $result = $fn(HtdigestFile::parse($current, $realm));

            return $result->render();
        });
        $this->cached = $result;
    }
}
