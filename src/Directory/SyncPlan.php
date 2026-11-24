<?php

declare(strict_types=1);

namespace SvnManage\Directory;

use SvnManage\Authz\AuthzFile;

final class SyncPlan
{
    /**
     * @param list<array{0: string, 1: string}> $messages [level, text]
     * @param list<string> $addedGroups
     * @param list<string> $removedGroups
     * @param list<string> $removedUsers
     */
    public function __construct(
        public readonly AuthzFile $authz,
        public readonly array $messages,
        public readonly int $groupCount,
        public readonly int $memberCount,
        public readonly array $addedGroups,
        public readonly array $removedGroups,
        public readonly array $removedUsers,
        public readonly bool $changed,
    ) {
    }
}
