<?php

declare(strict_types=1);

namespace SvnManage\Users;

/** A Subversion user as exposed by a user provider. */
final class User
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $displayName = null,
    ) {
    }

    public function label(): string
    {
        return $this->displayName !== null && $this->displayName !== '' ? $this->displayName : $this->name;
    }

    public function isEveryone(): bool
    {
        return $this->name === '*';
    }
}
