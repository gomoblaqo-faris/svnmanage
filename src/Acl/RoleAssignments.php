<?php

declare(strict_types=1);

namespace SvnManage\Acl;

use InvalidArgumentException;
use SvnManage\Authz\Names;
use SvnManage\Support\IniDocument;

/**
 * Immutable model of userroleassignments.ini (iF.SVNAdmin format):
 *
 *   [alice]            ; roles of alice
 *   Administrator=
 *   [bob:AccessPaths]  ; access paths bob manages as project manager
 *   0=repo:/trunk
 */
final class RoleAssignments
{
    private const PATH_SUFFIX = ':AccessPaths';

    private function __construct(private readonly IniDocument $doc)
    {
    }

    public static function parse(string $text): self
    {
        return new self(IniDocument::parse($text, '=', '#;'));
    }

    public function render(): string
    {
        return $this->doc->render();
    }

    /** @return list<string> */
    public function rolesOf(string $user): array
    {
        if (str_ends_with($user, self::PATH_SUFFIX)) {
            return [];
        }

        return array_values(array_filter($this->doc->keys($user), Acl::exists(...)));
    }

    public function hasRole(string $user, string $role): bool
    {
        return in_array($role, $this->rolesOf($user), true);
    }

    /** @return list<string> */
    public function usersWithRole(string $role): array
    {
        $users = [];
        foreach ($this->doc->sections() as $section) {
            if (!str_ends_with($section, self::PATH_SUFFIX) && $this->doc->has($section, $role)) {
                $users[] = $section;
            }
        }

        return $users;
    }

    public function hasAdmin(): bool
    {
        return $this->usersWithRole(Acl::ADMINISTRATOR) !== [];
    }

    public function withRole(string $user, string $role): self
    {
        if (!Acl::exists($role)) {
            throw new InvalidArgumentException(sprintf('Unknown role "%s".', $role));
        }
        if ($user === '' || $user === '*') {
            throw new InvalidArgumentException('Roles can not be assigned to this user.');
        }
        Names::user($user);

        return new self($this->doc->with($user, $role, ''));
    }

    public function withoutRole(string $user, string $role): self
    {
        $doc = $this->doc->without($user, $role);
        if ($doc->hasSection($user) && $doc->keys($user) === []) {
            $doc = $doc->withoutSection($user);
        }

        return new self($doc);
    }

    /** Removes all roles and project-manager assignments of a user. */
    public function withoutUser(string $user): self
    {
        return new self($this->doc->withoutSection($user)->withoutSection($user . self::PATH_SUFFIX));
    }

    // ---------------------------------------------------- project managers

    /** @return list<string> */
    public function managedPaths(string $user): array
    {
        return array_values(array_unique(array_values($this->doc->entries($user . self::PATH_SUFFIX))));
    }

    public function isProjectManager(string $user): bool
    {
        return $this->managedPaths($user) !== [];
    }

    /** @return list<string> */
    public function managersOf(string $path): array
    {
        $users = [];
        foreach ($this->doc->sections() as $section) {
            if (str_ends_with($section, self::PATH_SUFFIX) && in_array($path, $this->doc->entries($section), true)) {
                $users[] = substr($section, 0, -strlen(self::PATH_SUFFIX));
            }
        }

        return $users;
    }

    /** @return array<string, list<string>> user => managed paths */
    public function allManagers(): array
    {
        $out = [];
        foreach ($this->doc->sections() as $section) {
            if (str_ends_with($section, self::PATH_SUFFIX)) {
                $out[substr($section, 0, -strlen(self::PATH_SUFFIX))] = array_values($this->doc->entries($section));
            }
        }

        return $out;
    }

    public function withManagedPath(string $user, string $path): self
    {
        if ($path === '/') {
            throw new InvalidArgumentException('The root path "/" can not have project managers.');
        }
        if ($user === '' || $user === '*') {
            throw new InvalidArgumentException('This user can not be a project manager.');
        }
        Names::user($user);
        $paths = $this->managedPaths($user);
        if (in_array($path, $paths, true)) {
            return $this;
        }

        return $this->withPaths($user, [...$paths, Names::accessPath($path)]);
    }

    public function withoutManagedPath(string $user, string $path): self
    {
        return $this->withPaths($user, array_values(array_filter(
            $this->managedPaths($user),
            static fn (string $p): bool => $p !== $path,
        )));
    }

    public function withoutPathEverywhere(string $path): self
    {
        $next = $this;
        foreach ($this->managersOf($path) as $user) {
            $next = $next->withoutManagedPath($user, $path);
        }

        return $next;
    }

    /** @param list<string> $paths */
    private function withPaths(string $user, array $paths): self
    {
        $section = $user . self::PATH_SUFFIX;
        $doc = $this->doc->withoutSection($section);
        foreach (array_values($paths) as $i => $path) {
            $doc = $doc->with($section, (string) $i, $path);
        }

        return new self($doc);
    }
}
