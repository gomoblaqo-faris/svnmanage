<?php

declare(strict_types=1);

namespace SvnManage\Directory;

use SvnManage\Authz\AuthzFile;
use SvnManage\Authz\Names;

/**
 * Mirrors directory groups into the authz file (iF.SVNAdmin "Update").
 * Pure: plan() computes the new authz plus a report; the caller persists it.
 */
final class DirectorySync
{
    public function __construct(
        private readonly bool $autoRemoveUsers = true,
        private readonly bool $autoRemoveGroups = true,
    ) {
    }

    /**
     * @param list<DirectoryGroup>|null $groups null = groups are not directory managed (keep them)
     * @param list<string> $knownUsers user names that exist in the directory
     */
    public function plan(AuthzFile $authz, ?array $groups, array $knownUsers): SyncPlan
    {
        if ($groups === null) {
            return $this->cleanUsers($authz, $authz, [], [], [], $knownUsers, count($authz->groupNames()), 0);
        }
        $messages = [];
        $definitions = [];
        $validNames = [];
        foreach ($groups as $group) {
            try {
                $validNames[Names::group($group->name)] = true;
            } catch (\InvalidArgumentException $e) {
                $messages[] = ['warning', sprintf('Skipped group "%s": %s', $group->name, $e->getMessage())];
            }
        }
        foreach ($groups as $group) {
            if (!isset($validNames[$group->name])) {
                continue;
            }
            $subs = array_map(
                static fn (string $s): string => '@' . $s,
                array_values(array_filter($group->subgroups, static fn (string $s): bool => isset($validNames[$s]) && $s !== $group->name)),
            );
            $definitions[$group->name] = [...$subs, ...$group->users];
        }

        $old = $authz;
        $next = $authz->withGroupDefinitions($definitions);
        $added = array_values(array_diff(array_keys($definitions), $old->groupNames()));
        $removedGroups = array_values(array_diff($old->groupNames(), array_keys($definitions)));
        foreach ($added as $g) {
            $messages[] = ['success', sprintf('Added group %s.', $g)];
        }

        $orphanGroups = array_values(array_unique([...$removedGroups, ...array_diff($next->groupsInRules(), array_keys($definitions))]));
        foreach ($orphanGroups as $g) {
            if ($this->autoRemoveGroups) {
                $next = $next->withoutRulesFor('@' . $g);
                $messages[] = ['info', sprintf('The group %s has been removed from the directory. Removed all assigned permissions.', $g)];
            } else {
                $messages[] = ['warning', sprintf('The group %s no longer exists in the directory (permissions kept).', $g)];
            }
        }

        $memberCount = array_sum(array_map('count', $definitions));

        return $this->cleanUsers($old, $next, $messages, $added, $orphanGroups, $knownUsers, count($definitions), $memberCount);
    }

    /**
     * @param list<array{0: string, 1: string}> $messages
     * @param list<string> $added
     * @param list<string> $orphanGroups
     * @param list<string> $knownUsers
     */
    private function cleanUsers(AuthzFile $old, AuthzFile $next, array $messages, array $added, array $orphanGroups, array $knownUsers, int $groupCount, int $memberCount): SyncPlan
    {
        $known = array_fill_keys(array_map('strtolower', $knownUsers), true);
        $orphanUsers = array_values(array_filter($next->usersInRules(), static fn (string $u): bool => !isset($known[strtolower($u)])));
        foreach ($orphanUsers as $u) {
            if ($this->autoRemoveUsers) {
                $next = $next->withoutRulesFor($u);
                $messages[] = ['info', sprintf("The user %s doesn't exist anymore. Removed direct access-path permissions.", $u)];
            } else {
                $messages[] = ['warning', sprintf("The user %s doesn't exist in the directory (permissions kept).", $u)];
            }
        }

        return new SyncPlan($next, $messages, $groupCount, $memberCount, $added, $orphanGroups, $orphanUsers, $next->render() !== $old->render());
    }
}
