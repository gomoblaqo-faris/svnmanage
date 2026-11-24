<?php

declare(strict_types=1);

namespace SvnManage\Users;

use SvnManage\Directory\Directory;

/** Read-only users from LDAP / Active Directory. */
final class DirectoryUsers implements UserProvider
{
    /** @var list<User>|null */
    private ?array $cached = null;

    public function __construct(
        private readonly Directory $directory,
        private readonly string $kind,
    ) {
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function users(): array
    {
        return $this->cached ??= array_values(array_filter($this->directory->users(), static fn (User $u): bool => !$u->isEveryone()));
    }

    public function exists(string $name): bool
    {
        foreach ($this->users() as $user) {
            if (strcasecmp($user->name, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    public function authenticate(string $name, string $password): bool
    {
        return $this->directory->authenticate($name, $password);
    }
}
