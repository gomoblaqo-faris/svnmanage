<?php

declare(strict_types=1);

namespace SvnManage\Svn;

final class Repository
{
    public function __construct(
        public readonly string $name,
        public readonly int $parentId,
        public readonly string $path,
    ) {
    }
}
