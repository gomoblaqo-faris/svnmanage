<?php

declare(strict_types=1);

namespace SvnManage\Svn;

final class RepositoryParent
{
    public function __construct(
        public readonly int $id,
        public readonly string $path,
        public readonly string $description,
    ) {
    }
}
