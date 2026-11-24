<?php

declare(strict_types=1);

namespace SvnManage\Authz;

use InvalidArgumentException;
use SvnManage\Support\IniDocument;

/**
 * Immutable model of a Subversion path-based authorization (authz) file.
 *
 * Sections: [groups], [aliases] and one section per access path
 * ("repo:/path" or "/path"). Rule keys are user names, "@group",
 * "&alias", "*", "$authenticated", "$anonymous", optionally "~"-negated.
 */
final class AuthzFile
{
    public const GROUPS = 'groups';
    public const ALIASES = 'aliases';
    public const EVERYONE = '*';

    private function __construct(private readonly IniDocument $doc)
    {
    }

    public static function parse(string $text): self
    {
        return new self(IniDocument::parse($text, ' = ', '#;'));
    }

    public static function empty(): self
    {
        return new self(IniDocument::empty(' = ')->withSection(self::GROUPS));
    }

    public function render(): string
    {
        return $this->doc->render();
    }

    // ---------------------------------------------------------------- groups

    /** @return list<string> */
    public function groupNames(): array
    {
        return $this->doc->keys(self::GROUPS);
    }

    public function hasGroup(string $group): bool
    {
        return $this->doc->has(self::GROUPS, $group);
    }

    /** @return list<string> raw member tokens (users, @groups, &aliases) */
    public function groupMembers(string $group): array
    {
        $value = $this->doc->get(self::GROUPS, $group);
        if ($value === null || trim($value) === '') {
            return [];
        }
        $tokens = array_map('trim', explode(',', $value));

        return array_values(array_unique(array_filter($tokens, static fn (string $t): bool => $t !== '')));
    }

    /** @return list<string> */
    public function groupUsers(string $group): array
    {
        return array_values(array_filter(
            $this->groupMembers($group),
            static fn (string $t): bool => !str_starts_with($t, '@'),
        ));
    }

    /** @return list<string> */
    public function groupSubgroups(string $group): array
    {
        $out = [];
        foreach ($this->groupMembers($group) as $token) {
            if (str_starts_with($token, '@')) {
                $out[] = substr($token, 1);
            }
        }

        return $out;
    }

    /** @return list<string> groups that directly contain $token */
    public function groupsContaining(string $token): array
    {
        return array_values(array_filter(
            $this->groupNames(),
            fn (string $g): bool => in_array($token, $this->groupMembers($g), true),
        ));
    }

    /** @return list<string> direct groups of a user (incl. via alias) */
    public function groupsOfUser(string $user): array
    {
        $tokens = [$user];
        foreach ($this->aliases() as $alias => $value) {
            if ($value === $user) {
                $tokens[] = '&' . $alias;
            }
        }
        $groups = [];
        foreach ($tokens as $token) {
            $groups = [...$groups, ...$this->groupsContaining($token)];
        }

        return array_values(array_unique($groups));
    }

    /**
     * Transitive group membership: direct groups plus every group that
     * (recursively) contains one of them.
     *
     * @return array<string, ?string> group => parent group it was inherited through (null = direct)
     */
    public function effectiveGroupsOfUser(string $user): array
    {
        $result = [];
        $queue = [];
        foreach ($this->groupsOfUser($user) as $g) {
            $result[$g] = null;
            $queue[] = $g;
        }
        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($this->groupsContaining('@' . $current) as $parent) {
                if (!array_key_exists($parent, $result)) {
                    $result[$parent] = $current;
                    $queue[] = $parent;
                }
            }
        }

        return $result;
    }

    public function withGroup(string $group): self
    {
        $group = Names::group($group);
        if ($this->hasGroup($group)) {
            throw new InvalidArgumentException(sprintf('The group "%s" already exists.', $group));
        }

        return new self($this->doc->with(self::GROUPS, $group, ''));
    }

    /** Deletes a group and every reference to it (memberships and rules). */
    public function withoutGroup(string $group): self
    {
        $next = new self($this->doc->without(self::GROUPS, $group));

        return $next->withoutTokenEverywhere('@' . $group);
    }

    public function withRenamedGroup(string $from, string $to): self
    {
        $to = Names::group($to);
        if (!$this->hasGroup($from)) {
            throw new InvalidArgumentException(sprintf('The group "%s" does not exist.', $from));
        }
        if ($this->hasGroup($to)) {
            throw new InvalidArgumentException(sprintf('The group "%s" already exists.', $to));
        }
        $doc = $this->doc->withRenamedKey(self::GROUPS, $from, $to);
        $next = new self($doc);

        return $next->withRenamedTokenEverywhere('@' . $from, '@' . $to);
    }

    public function withGroupMember(string $group, string $token): self
    {
        Names::token($token);
        if (str_starts_with($token, '~') || in_array($token, Names::SPECIAL_TOKENS, true) && $token !== '*') {
            throw new InvalidArgumentException(sprintf('"%s" can not be a group member.', $token));
        }
        if (!$this->hasGroup($group)) {
            throw new InvalidArgumentException(sprintf('The group "%s" does not exist.', $group));
        }
        if (str_starts_with($token, '@')) {
            $sub = substr($token, 1);
            if ($sub === $group) {
                throw new InvalidArgumentException(sprintf('Can not add group %s as member of itself.', $group));
            }
            if (!$this->hasGroup($sub)) {
                throw new InvalidArgumentException(sprintf('The group "%s" does not exist.', $sub));
            }
            if ($this->groupReaches($sub, $group)) {
                throw new InvalidArgumentException(sprintf('Adding %s to %s would create a membership cycle.', $sub, $group));
            }
        }
        $members = $this->groupMembers($group);
        if (in_array($token, $members, true)) {
            return $this;
        }

        return $this->withMembers($group, [...$members, $token]);
    }

    public function withoutGroupMember(string $group, string $token): self
    {
        if (!$this->hasGroup($group)) {
            return $this;
        }
        $members = array_values(array_filter($this->groupMembers($group), static fn (string $t): bool => $t !== $token));

        return $this->withMembers($group, $members);
    }

    /** @param list<string> $members */
    public function withMembers(string $group, array $members): self
    {
        $groups = array_values(array_filter($members, static fn (string $t): bool => str_starts_with($t, '@')));
        $others = array_values(array_filter($members, static fn (string $t): bool => !str_starts_with($t, '@')));

        return new self($this->doc->with(self::GROUPS, $group, implode(', ', [...$groups, ...$others])));
    }

    /** Removes a user from all groups and all rules. */
    public function withoutUser(string $user): self
    {
        return $this->withoutTokenEverywhere($user);
    }

    /**
     * Replaces the whole [groups] section with $groups (name => member tokens)
     * without touching any access-path rule. Used by directory synchronisation.
     *
     * @param array<string, list<string>> $groups
     */
    public function withGroupDefinitions(array $groups): self
    {
        $doc = $this->doc->withSection(self::GROUPS);
        foreach ($doc->keys(self::GROUPS) as $existing) {
            if (!array_key_exists($existing, $groups)) {
                $doc = $doc->without(self::GROUPS, $existing);
            }
        }
        $next = new self($doc);
        foreach ($groups as $name => $members) {
            $next = $next->withMembers((string) $name, $members);
        }

        return $next;
    }

    /** Removes every rule (incl. "~"-negated) for $token, keeping group definitions. */
    public function withoutRulesFor(string $token): self
    {
        $doc = $this->doc;
        foreach ($this->accessPaths() as $path) {
            $doc = $doc->without($path, $token)->without($path, '~' . $token);
        }

        return new self($doc);
    }

    /** @return list<string> plain user names that appear in any rule */
    public function usersInRules(): array
    {
        $users = [];
        foreach ($this->accessPaths() as $path) {
            foreach (array_keys($this->rules($path)) as $token) {
                $token = ltrim((string) $token, '~');
                if ($token !== '' && !in_array($token[0], ['@', '&', '$', '*'], true)) {
                    $users[$token] = true;
                }
            }
        }

        return array_keys($users);
    }

    /** @return list<string> group names referenced in rules */
    public function groupsInRules(): array
    {
        $groups = [];
        foreach ($this->accessPaths() as $path) {
            foreach (array_keys($this->rules($path)) as $token) {
                $token = ltrim((string) $token, '~');
                if (str_starts_with($token, '@')) {
                    $groups[substr($token, 1)] = true;
                }
            }
        }

        return array_keys($groups);
    }

    // --------------------------------------------------------------- aliases

    /** @return array<string, string> */
    public function aliases(): array
    {
        return $this->doc->entries(self::ALIASES);
    }

    // ---------------------------------------------------------- access paths

    /** @return list<string> */
    public function accessPaths(): array
    {
        return array_values(array_filter(
            $this->doc->sections(),
            static fn (string $s): bool => $s !== self::GROUPS && $s !== self::ALIASES && $s !== '',
        ));
    }

    public function hasAccessPath(string $path): bool
    {
        return in_array($path, $this->accessPaths(), true);
    }

    public function withAccessPath(string $path): self
    {
        $path = Names::accessPath($path);
        if ($this->hasAccessPath($path)) {
            throw new InvalidArgumentException(sprintf('The access path "%s" already exists.', $path));
        }

        return new self($this->doc->withSection($path));
    }

    public function withoutAccessPath(string $path): self
    {
        return new self($this->doc->withoutSection($path));
    }

    /** @return list<string> access paths belonging to repository $repo */
    public function accessPathsOfRepository(string $repo): array
    {
        return array_values(array_filter(
            $this->accessPaths(),
            static fn (string $p): bool => str_starts_with($p, $repo . ':/'),
        ));
    }

    /** @return array<string, string> raw rule token => raw permission */
    public function rules(string $path): array
    {
        return $this->doc->entries($path);
    }

    /** @return array<string, Permission> users (incl. "*" and $tokens) with their permission */
    public function userRules(string $path): array
    {
        $out = [];
        foreach ($this->rules($path) as $token => $perm) {
            if (!str_starts_with($token, '@') && !str_starts_with($token, '~@')) {
                $out[$token] = Permission::fromAuthz($perm);
            }
        }

        return $out;
    }

    /** @return array<string, Permission> */
    public function groupRules(string $path): array
    {
        $out = [];
        foreach ($this->rules($path) as $token => $perm) {
            if (str_starts_with($token, '@')) {
                $out[substr($token, 1)] = Permission::fromAuthz($perm);
            }
        }

        return $out;
    }

    public function withRule(string $path, string $token, Permission $permission): self
    {
        if (!$this->hasAccessPath($path)) {
            throw new InvalidArgumentException(sprintf('The access path "%s" does not exist.', $path));
        }
        Names::token($token);
        $group = ltrim($token, '~');
        if (str_starts_with($group, '@') && !$this->hasGroup(substr($group, 1))) {
            throw new InvalidArgumentException(sprintf('The group "%s" does not exist.', substr($group, 1)));
        }

        return new self($this->doc->with($path, $token, $permission->value));
    }

    public function withoutRule(string $path, string $token): self
    {
        return new self($this->doc->without($path, $token));
    }

    /**
     * Every permission that applies to $user, with where it came from.
     *
     * @return list<array{path: string, permission: Permission, via: string}>
     *         via: "" (direct), "@group", "*" or "$authenticated"
     */
    public function effectivePermissions(string $user): array
    {
        $groups = array_keys($this->effectiveGroupsOfUser($user));
        $out = [];
        foreach ($this->accessPaths() as $path) {
            foreach ($this->rules($path) as $token => $perm) {
                $via = match (true) {
                    $token === $user => '',
                    $token === self::EVERYONE, $token === '$authenticated' => $token,
                    str_starts_with($token, '@') && in_array(substr($token, 1), $groups, true) => $token,
                    default => null,
                };
                if ($via !== null) {
                    $out[] = ['path' => $path, 'permission' => Permission::fromAuthz($perm), 'via' => $via];
                }
            }
        }

        return $out;
    }

    /** @return list<array{path: string, permission: Permission}> direct rules of a group */
    public function permissionsOfGroup(string $group): array
    {
        $out = [];
        foreach ($this->accessPaths() as $path) {
            $rules = $this->rules($path);
            if (array_key_exists('@' . $group, $rules)) {
                $out[] = ['path' => $path, 'permission' => Permission::fromAuthz($rules['@' . $group])];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------- internals

    /** True when making @$member a member of $group would create a cycle. */
    public function wouldCycle(string $group, string $member): bool
    {
        return $member === $group || $this->groupReaches($member, $group);
    }

    private function groupReaches(string $from, string $target): bool
    {
        $seen = [];
        $stack = [$from];
        while ($stack !== []) {
            $g = array_pop($stack);
            if ($g === $target) {
                return true;
            }
            if (isset($seen[$g])) {
                continue;
            }
            $seen[$g] = true;
            foreach ($this->groupSubgroups($g) as $sub) {
                $stack[] = $sub;
            }
        }

        return false;
    }

    private function withoutTokenEverywhere(string $token): self
    {
        $next = $this;
        foreach ($next->groupNames() as $g) {
            if (in_array($token, $next->groupMembers($g), true)) {
                $next = $next->withoutGroupMember($g, $token);
            }
        }
        $doc = $next->doc;
        foreach ($next->accessPaths() as $path) {
            $doc = $doc->without($path, $token)->without($path, '~' . $token);
        }

        return new self($doc);
    }

    private function withRenamedTokenEverywhere(string $from, string $to): self
    {
        $next = $this;
        foreach ($next->groupNames() as $g) {
            $members = $next->groupMembers($g);
            if (in_array($from, $members, true)) {
                $next = $next->withMembers($g, array_map(static fn (string $t): string => $t === $from ? $to : $t, $members));
            }
        }
        $doc = $next->doc;
        foreach ($next->accessPaths() as $path) {
            $doc = $doc->withRenamedKey($path, $from, $to)->withRenamedKey($path, '~' . $from, '~' . $to);
        }

        return new self($doc);
    }
}
