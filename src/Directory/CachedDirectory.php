<?php

declare(strict_types=1);

namespace SvnManage\Directory;

use SvnManage\Users\User;

/**
 * Serves users and groups from a JSON snapshot (Ldap.CacheEnabled) so pages
 * do not hit the directory on every request. refresh() is run on sync.
 */
final class CachedDirectory implements Directory
{
    public function __construct(
        private readonly Directory $inner,
        private readonly string $cacheFile,
    ) {
    }

    public function users(): array
    {
        $data = $this->load();
        if ($data === null) {
            return $this->inner->users();
        }

        return array_map(static fn (array $u): User => new User((string) $u['name'], $u['displayName'] ?? null), $data['users'] ?? []);
    }

    public function groups(): array
    {
        $data = $this->load();
        if ($data === null) {
            return $this->inner->groups();
        }

        return array_map(
            static fn (array $g): DirectoryGroup => new DirectoryGroup((string) $g['name'], $g['users'] ?? [], $g['subgroups'] ?? []),
            $data['groups'] ?? [],
        );
    }

    public function authenticate(string $username, string $password): bool
    {
        return $this->inner->authenticate($username, $password);
    }

    public function refresh(): void
    {
        $payload = [
            'updated' => date(DATE_ATOM),
            'users' => array_map(static fn (User $u): array => ['name' => $u->name, 'displayName' => $u->displayName], $this->inner->users()),
            'groups' => array_map(
                static fn (DirectoryGroup $g): array => ['name' => $g->name, 'users' => $g->users, 'subgroups' => $g->subgroups],
                $this->inner->groups(),
            ),
        ];
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        if (@file_put_contents($this->cacheFile, json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            throw new \RuntimeException('Cannot write LDAP cache file: ' . $this->cacheFile);
        }
    }

    public function updatedAt(): ?string
    {
        return $this->load()['updated'] ?? null;
    }

    /** @return array<string, mixed>|null */
    private function load(): ?array
    {
        if (!is_file($this->cacheFile)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($this->cacheFile), true);

        return is_array($data) ? $data : null;
    }
}
