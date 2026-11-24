<?php

declare(strict_types=1);

namespace SvnManage\Svn;

final class RepositoryInfo
{
    public function __construct(
        public readonly int $youngest,
        public readonly string $uuid,
        public readonly ?\DateTimeImmutable $lastChanged,
        public readonly string $lastAuthor,
        public readonly string $fsType,
        public readonly ?int $sizeBytes,
    ) {
    }
}
