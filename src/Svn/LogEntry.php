<?php

declare(strict_types=1);

namespace SvnManage\Svn;

final class LogEntry
{
    /** @param list<array{action: string, path: string}> $paths */
    public function __construct(
        public readonly int $revision,
        public readonly string $author,
        public readonly ?\DateTimeImmutable $date,
        public readonly string $message,
        public readonly array $paths = [],
    ) {
    }
}
