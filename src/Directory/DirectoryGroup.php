<?php

declare(strict_types=1);

namespace SvnManage\Directory;

final class DirectoryGroup
{
    /**
     * @param list<string> $users member user names
     * @param list<string> $subgroups member group names
     */
    public function __construct(
        public readonly string $name,
        public readonly array $users,
        public readonly array $subgroups = [],
    ) {
    }
}
