<?php

declare(strict_types=1);

namespace SvnManage\Users;

/** Write access to a user file. */
interface UserEditor
{
    public function create(string $name, string $password): void;

    public function delete(string $name): void;

    public function changePassword(string $name, string $password): void;
}
