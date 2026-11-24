<?php

declare(strict_types=1);

namespace SvnManage\Svn;

final class BrowseEntry
{
    public function __construct(
        public readonly string $name,
        public readonly bool $isDir,
        public readonly ?int $size,
        public readonly int $revision,
        public readonly string $author,
        public readonly ?\DateTimeImmutable $date,
    ) {
    }
}
