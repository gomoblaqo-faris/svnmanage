<?php

declare(strict_types=1);

namespace SvnManage\Users;

use RuntimeException;
use SvnManage\Support\FileStore;

/** htpasswd-backed users. */
final class PasswdUsers implements UserProvider, UserEditor
{
    private ?HtpasswdFile $cached = null;

    public function __construct(
        private readonly FileStore $files,
        private readonly string $path,
        private readonly string $algorithm = 'bcrypt',
    ) {
    }

    public function kind(): string
    {
        return 'passwd';
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
        $this->change(fn (HtpasswdFile $f): HtpasswdFile => $f->withUser($name, $password, $this->algorithm));
    }

    public function delete(string $name): void
    {
        $this->change(static fn (HtpasswdFile $f): HtpasswdFile => $f->withoutUser($name));
    }

    public function changePassword(string $name, string $password): void
    {
        $this->change(fn (HtpasswdFile $f): HtpasswdFile => $f->withPassword($name, $password, $this->algorithm));
    }

    private function file(): HtpasswdFile
    {
        if ($this->cached === null) {
            $this->assertConfigured();
            if (!is_file($this->path)) {
                throw new RuntimeException(sprintf('The user file does not exist: %s', $this->path));
            }
            $this->cached = HtpasswdFile::parse($this->files->read($this->path));
        }

        return $this->cached;
    }

    /** @param callable(HtpasswdFile): HtpasswdFile $fn */
    private function change(callable $fn): void
    {
        $this->assertConfigured();
        $result = null;
        $this->files->update($this->path, static function (string $current) use ($fn, &$result): string {
            $result = $fn(HtpasswdFile::parse($current));

            return $result->render();
        });
        $this->cached = $result;
    }

    private function assertConfigured(): void
    {
        if ($this->path === '') {
            throw new RuntimeException('No user file (SVNUserFile) configured. Configure it in the settings.');
        }
    }
}
