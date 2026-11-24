<?php

declare(strict_types=1);

namespace SvnManage\Directory;

use LDAP\Connection;
use RuntimeException;
use SvnManage\Users\User;

/**
 * LDAP / Active Directory backed users and groups via ext-ldap.
 * All user-supplied values are escaped with LdapEscape.
 */
final class LdapDirectory implements Directory
{
    private const AD_IN_CHAIN = '1.2.840.113556.1.4.1941';

    private ?Connection $connection = null;
    /** @var list<array<string, mixed>>|null */
    private ?array $userEntries = null;

    public function __construct(private readonly LdapOptions $o)
    {
    }

    public static function available(): bool
    {
        return extension_loaded('ldap');
    }

    /** Connects and binds with the service account; returns a status message. */
    public function test(): string
    {
        $this->connection();

        return 'Connected and bound as ' . ($this->o->bindDn !== '' ? $this->o->bindDn : 'anonymous') . '.';
    }

    /** @return list<User> */
    public function users(): array
    {
        $nameAttr = strtolower($this->o->userAttributes[0] ?? 'uid');
        $allowed = $this->o->strictMode ? $this->strictUserNames() : null;
        $users = [];
        foreach ($this->userEntries() as $entry) {
            $name = $entry[$nameAttr][0] ?? null;
            if ($name === null || ($allowed !== null && !isset($allowed[strtolower($name)]))) {
                continue;
            }
            $users[] = new User($name, self::formatDisplayName($this->o->displayNameFormat, $entry));
        }
        usort($users, static fn (User $a, User $b): int => strnatcasecmp($a->name, $b->name));

        return $users;
    }

    /** @return list<DirectoryGroup> */
    public function groups(): array
    {
        $groupAttr = strtolower($this->o->groupAttributes[0] ?? 'cn');
        $memberAttr = strtolower($this->o->memberAttribute);
        $entries = $this->search($this->o->groupBaseDn, $this->o->groupFilter, [...$this->o->groupAttributes, $this->o->memberAttribute]);

        $userByMemberValue = $this->userIndex();
        $groupByDn = [];
        foreach ($entries as $entry) {
            if (isset($entry[$groupAttr][0])) {
                $groupByDn[strtolower($entry['dn'])] = $entry[$groupAttr][0];
            }
        }

        $groups = [];
        foreach ($entries as $entry) {
            $name = $entry[$groupAttr][0] ?? null;
            if ($name === null) {
                continue;
            }
            $users = [];
            $subgroups = [];
            foreach ($entry[$memberAttr] ?? [] as $value) {
                $key = strtolower($value);
                if (isset($groupByDn[$key])) {
                    $subgroups[] = $groupByDn[$key];
                } elseif (isset($userByMemberValue[$key])) {
                    $users[] = $userByMemberValue[$key];
                }
            }
            if ($this->o->nestedGroups) {
                $users = [...$users, ...$this->nestedUsers($entry['dn'])];
            }
            $groups[] = new DirectoryGroup($name, array_values(array_unique($users)), array_values(array_unique($subgroups)));
        }
        usort($groups, static fn (DirectoryGroup $a, DirectoryGroup $b): int => strnatcasecmp($a->name, $b->name));

        return $groups;
    }

    public function authenticate(string $username, string $password): bool
    {
        if ($username === '' || $password === '') {
            return false;
        }
        $nameAttr = $this->o->userAttributes[0] ?? 'uid';
        $filter = '(&(' . $nameAttr . '=' . LdapEscape::filter($username) . ')' . $this->o->userFilter . ')';
        $found = $this->search($this->o->userBaseDn, $filter, [$nameAttr], 2);
        if (count($found) !== 1) {
            return false;
        }
        $conn = $this->connect();

        return @ldap_bind($conn, $found[0]['dn'], $password) === true;
    }

    /** "%givenName %sn (%uid)" => "Ada Lovelace (ada)" */
    public static function formatDisplayName(string $format, array $entry): ?string
    {
        if (trim($format) === '') {
            return null;
        }
        $result = preg_replace_callback('/%([A-Za-z0-9_-]+)/', static function (array $m) use ($entry): string {
            return (string) ($entry[strtolower($m[1])][0] ?? '');
        }, $format);
        $result = trim((string) preg_replace('/\s+/', ' ', (string) $result));

        return $result === '' || $result === '()' ? null : $result;
    }

    /**
     * Normalises ldap_get_entries() output to: dn + lower-case attribute => list<string>.
     *
     * @param array<int|string, mixed> $raw
     * @return list<array<string, mixed>>
     */
    public static function normalize(array $raw): array
    {
        $out = [];
        $count = (int) ($raw['count'] ?? 0);
        for ($i = 0; $i < $count; $i++) {
            $entry = ['dn' => (string) ($raw[$i]['dn'] ?? '')];
            foreach ($raw[$i] as $key => $value) {
                if (!is_string($key) || $key === 'dn' || $key === 'count' || !is_array($value)) {
                    continue;
                }
                unset($value['count']);
                $entry[strtolower($key)] = array_values(array_map('strval', $value));
            }
            $out[] = $entry;
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function userEntries(): array
    {
        if ($this->userEntries === null) {
            $attrs = array_values(array_unique([...$this->o->userAttributes, $this->o->memberValueAttribute, ...$this->displayAttributes()]));
            $this->userEntries = $this->search($this->o->userBaseDn, $this->o->userFilter, $attrs);
        }

        return $this->userEntries;
    }

    /** @return array<string, string> lower-case member value (dn or attribute) => user name */
    private function userIndex(): array
    {
        $nameAttr = strtolower($this->o->userAttributes[0] ?? 'uid');
        $valueAttr = strtolower($this->o->memberValueAttribute);
        $index = [];
        foreach ($this->userEntries() as $entry) {
            $name = $entry[$nameAttr][0] ?? null;
            if ($name === null) {
                continue;
            }
            $value = in_array($valueAttr, ['dn', 'distinguishedname'], true) ? $entry['dn'] : ($entry[$valueAttr][0] ?? null);
            if ($value !== null) {
                $index[strtolower($value)] = $name;
            }
        }

        return $index;
    }

    /** @return list<string> users that are transitive members of $groupDn (AD only) */
    private function nestedUsers(string $groupDn): array
    {
        $nameAttr = $this->o->userAttributes[0] ?? 'sAMAccountName';
        $filter = '(&' . $this->o->userFilter . '(memberOf:' . self::AD_IN_CHAIN . ':=' . LdapEscape::filter($groupDn) . '))';
        $names = [];
        foreach ($this->search($this->o->userBaseDn, $filter, [$nameAttr]) as $entry) {
            if (isset($entry[strtolower($nameAttr)][0])) {
                $names[] = $entry[strtolower($nameAttr)][0];
            }
        }

        return $names;
    }

    /** @return array<string, true> */
    private function strictUserNames(): array
    {
        $allowed = [];
        foreach ($this->groups() as $group) {
            foreach ($group->users as $user) {
                $allowed[strtolower($user)] = true;
            }
        }

        return $allowed;
    }

    /** @return list<string> */
    private function displayAttributes(): array
    {
        preg_match_all('/%([A-Za-z0-9_-]+)/', $this->o->displayNameFormat, $m);

        return $m[1];
    }

    /**
     * @param list<string> $attributes
     * @return list<array<string, mixed>>
     */
    private function search(string $base, string $filter, array $attributes, int $sizeLimit = 0): array
    {
        $result = @ldap_search($this->connection(), $base, $filter, array_values(array_unique($attributes)), 0, $sizeLimit);
        if ($result === false) {
            throw new RuntimeException('LDAP search failed: ' . ldap_error($this->connection()) . ' (base: ' . $base . ', filter: ' . $filter . ')');
        }
        $entries = ldap_get_entries($this->connection(), $result);

        return $entries === false ? [] : self::normalize($entries);
    }

    private function connection(): Connection
    {
        if ($this->connection === null) {
            $conn = $this->connect();
            if (@ldap_bind($conn, $this->o->bindDn !== '' ? $this->o->bindDn : null, $this->o->bindPassword !== '' ? $this->o->bindPassword : null) !== true) {
                throw new RuntimeException('LDAP bind failed: ' . ldap_error($conn));
            }
            $this->connection = $conn;
        }

        return $this->connection;
    }

    private function connect(): Connection
    {
        if (!self::available()) {
            throw new RuntimeException('PHP LDAP extension is not available.');
        }
        if (trim($this->o->host) === '') {
            throw new RuntimeException('No LDAP host configured.');
        }
        $conn = @ldap_connect($this->o->host);
        if ($conn === false) {
            throw new RuntimeException('Invalid LDAP host address: ' . $this->o->host);
        }
        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, $this->o->protocolVersion > 0 ? $this->o->protocolVersion : 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 10);
        if ($this->o->startTls && !@ldap_start_tls($conn)) {
            throw new RuntimeException('LDAP StartTLS failed: ' . ldap_error($conn));
        }

        return $conn;
    }
}
