<?php

declare(strict_types=1);

namespace SvnManage\Users;

/** Read access to the users Subversion authenticates against. */
interface UserProvider
{
    /** @return list<User> sorted, without the "*" pseudo user */
    public function users(): array;

    public function exists(string $name): bool;

    public function authenticate(string $name, string $password): bool;

    /** passwd | digest | ldap | ad */
    public function kind(): string;
}
