<?php

declare(strict_types=1);

namespace SvnManage\Directory;

use SvnManage\Users\User;

/** Read-only source of users and groups (LDAP / Active Directory). */
interface Directory
{
    /** @return list<User> */
    public function users(): array;

    /** @return list<DirectoryGroup> */
    public function groups(): array;

    public function authenticate(string $username, string $password): bool;
}
