<?php

declare(strict_types=1);

namespace SvnManage\Acl;

/**
 * Static role/permission model, identical to iF.SVNAdmin's default ACL.
 * Modules and actions keep the original identifiers.
 */
final class Acl
{
    public const MOD_BASIC = 'basics';
    public const MOD_REPO = 'repositories';
    public const MOD_USER = 'users';
    public const MOD_GROUP = 'groups';
    public const MOD_ACCESSPATH = 'accesspaths';
    public const MOD_ROLE = 'roles';
    public const MOD_SETTINGS = 'settings';
    public const MOD_UPDATE = 'updates';
    public const MOD_PROJECTMANAGER = 'projectmanagers';

    public const VIEW = 'view';
    public const ADD = 'add';
    public const DELETE = 'delete';
    public const ASSIGN = 'assign';
    public const UNASSIGN = 'unassign';
    public const LOGIN = 'login';
    public const CHANGEPASS = 'changepass';
    public const CHANGEPASS_OTHER = 'changepassother';
    public const SYNCHRONIZE = 'synchronize';
    public const CHANGE = 'change';
    public const DUMP = 'dump';
    public const ASSIGN_ADMIN = 'assignadmin';
    public const UNASSIGN_ADMIN = 'unassignadmin';

    public const ADMINISTRATOR = 'Administrator';
    public const PROJECT_MANAGER = 'Project-Manager';

    /** @var array<string, array{parents: list<string>, description: string, rules: array<string, list<string>>|string}> */
    private const ROLES = [
        'User' => [
            'parents' => [],
            'description' => 'Can login and change the own password.',
            'rules' => [self::MOD_BASIC => [self::LOGIN], self::MOD_USER => [self::CHANGEPASS]],
        ],
        'User-Group-Manager' => [
            'parents' => ['User'],
            'description' => 'Create/Delete users and groups and manages memberships and change passwords of users.',
            'rules' => [
                self::MOD_USER => [self::VIEW, self::ADD, self::DELETE, self::CHANGEPASS_OTHER],
                self::MOD_GROUP => [self::VIEW, self::ADD, self::DELETE, self::ASSIGN, self::UNASSIGN],
            ],
        ],
        'Access-Path-Manager' => [
            'parents' => ['User'],
            'description' => 'Create/Delete Access-Paths and manages user and group permissions.',
            'rules' => [
                self::MOD_USER => [self::VIEW],
                self::MOD_GROUP => [self::VIEW],
                self::MOD_ACCESSPATH => [self::VIEW, self::ADD, self::DELETE, self::ASSIGN, self::UNASSIGN],
                self::MOD_REPO => [self::VIEW],
            ],
        ],
        'Repository-Creator' => [
            'parents' => ['User'],
            'description' => 'Can create new repositories, but NOT delete.',
            'rules' => [self::MOD_REPO => [self::VIEW, self::ADD]],
        ],
        'Repository-Manager' => [
            'parents' => ['User', 'Repository-Creator'],
            'description' => 'Create/Delete repositories.',
            'rules' => [self::MOD_REPO => [self::DELETE]],
        ],
        'Role-Manager' => [
            'parents' => ['User'],
            'description' => 'Assign and unassign web application roles to users.',
            'rules' => [
                self::MOD_USER => [self::VIEW],
                self::MOD_ROLE => [self::VIEW, self::ASSIGN, self::UNASSIGN],
            ],
        ],
        'Update-Manager' => [
            'parents' => ['User'],
            'description' => 'Can synchronize the user data from provider with the SVNAuthFile.',
            'rules' => [self::MOD_UPDATE => [self::SYNCHRONIZE]],
        ],
        self::ADMINISTRATOR => [
            'parents' => [],
            'description' => 'Web application administrator.',
            'rules' => '*',
        ],
    ];

    /** Implicit role of users assigned as manager of at least one access path. */
    private const PROJECT_MANAGER_RULES = [
        self::MOD_USER => [self::VIEW],
        self::MOD_GROUP => [self::VIEW],
        self::MOD_ACCESSPATH => [self::VIEW, self::ADD, self::DELETE, self::ASSIGN, self::UNASSIGN],
        self::MOD_REPO => [self::VIEW],
        self::MOD_PROJECTMANAGER => [self::VIEW],
    ];

    /** @return list<string> */
    public static function roleNames(): array
    {
        return array_keys(self::ROLES);
    }

    public static function exists(string $role): bool
    {
        return isset(self::ROLES[$role]);
    }

    public static function description(string $role): string
    {
        return $role === self::PROJECT_MANAGER
            ? 'Can be assigned to Access-Paths as project manager.'
            : (self::ROLES[$role]['description'] ?? '');
    }

    /** @return list<string> */
    public static function parents(string $role): array
    {
        return self::ROLES[$role]['parents'] ?? [];
    }

    /** @return array<string, list<string>>|string */
    public static function rules(string $role): array|string
    {
        return $role === self::PROJECT_MANAGER ? self::PROJECT_MANAGER_RULES : (self::ROLES[$role]['rules'] ?? []);
    }

    /** @param list<string> $roles */
    public static function allows(array $roles, string $module, string $action, bool $isProjectManager = false): bool
    {
        foreach ($roles as $role) {
            if (self::roleAllows($role, $module, $action, [])) {
                return true;
            }
        }

        return $isProjectManager && in_array($action, self::PROJECT_MANAGER_RULES[$module] ?? [], true);
    }

    /**
     * Flattened, human-readable capability list of a role (incl. inherited).
     *
     * @return array<string, list<string>>|string module => actions, or "*" for everything
     */
    public static function capabilities(string $role): array|string
    {
        $rules = self::rules($role);
        if ($rules === '*') {
            return '*';
        }
        foreach (self::parents($role) as $parent) {
            $inherited = self::capabilities($parent);
            if ($inherited === '*') {
                return '*';
            }
            foreach ($inherited as $module => $actions) {
                $rules[$module] = array_values(array_unique([...($rules[$module] ?? []), ...$actions]));
            }
        }
        ksort($rules);

        return $rules;
    }

    /** @param array<string, true> $seen */
    private static function roleAllows(string $role, string $module, string $action, array $seen): bool
    {
        if (!isset(self::ROLES[$role]) || isset($seen[$role])) {
            return false;
        }
        $rules = self::ROLES[$role]['rules'];
        if ($rules === '*' || in_array($action, $rules[$module] ?? [], true)) {
            return true;
        }
        $seen[$role] = true;
        foreach (self::ROLES[$role]['parents'] as $parent) {
            if (self::roleAllows($parent, $module, $action, $seen)) {
                return true;
            }
        }

        return false;
    }
}
