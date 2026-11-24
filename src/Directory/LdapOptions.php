<?php

declare(strict_types=1);

namespace SvnManage\Directory;

use SvnManage\Config\Config;

/** Connection and schema settings for an LDAP / AD directory. */
final class LdapOptions
{
    /**
     * @param list<string> $userAttributes first one is the login / svn user name
     * @param list<string> $groupAttributes first one is the svn group name
     */
    public function __construct(
        public readonly string $host,
        public readonly int $protocolVersion,
        public readonly string $bindDn,
        public readonly string $bindPassword,
        public readonly bool $startTls,
        public readonly string $userBaseDn,
        public readonly string $userFilter,
        public readonly array $userAttributes,
        public readonly string $displayNameFormat,
        public readonly string $groupBaseDn,
        public readonly string $groupFilter,
        public readonly array $groupAttributes,
        public readonly string $memberAttribute,
        public readonly string $memberValueAttribute,
        public readonly bool $nestedGroups = false,
        public readonly bool $strictMode = false,
    ) {
    }

    public static function fromLdapConfig(Config $c): self
    {
        return new self(
            $c->string('Ldap', 'HostAddress'),
            $c->int('Ldap', 'ProtocolVersion', 3),
            $c->raw('Ldap', 'BindDN'),
            $c->raw('Ldap', 'BindPassword'),
            $c->bool('Ldap', 'StartTLS'),
            $c->raw('Users:ldap', 'BaseDN'),
            $c->string('Users:ldap', 'SearchFilter', '(objectClass=person)'),
            self::list($c->string('Users:ldap', 'Attributes', 'uid')),
            $c->raw('Users:ldap', 'DisplayNameFormat'),
            $c->raw('Groups:ldap', 'BaseDN'),
            $c->string('Groups:ldap', 'SearchFilter', '(objectClass=groupOfNames)'),
            self::list($c->string('Groups:ldap', 'Attributes', 'cn')),
            $c->string('Groups:ldap', 'GroupsToUserAttribute', 'member'),
            $c->string('Groups:ldap', 'GroupsToUserAttributeValue', 'distinguishedName'),
        );
    }

    /** Active Directory: hosts from the DC list, base DN from the domain, nested groups. */
    public static function fromAdConfig(Config $c): self
    {
        $domain = $c->string('Ad', 'DomainName');
        $baseDn = self::domainToDn($domain);
        $hosts = array_map(
            static fn (string $h): string => preg_match('#^ldaps?://#', $h) === 1 ? $h : 'ldap://' . $h,
            self::list($c->string('Ad', 'DomainController', $domain)),
        );
        $groupPattern = $c->string('Groups:ad', 'Filter', '*');
        $cnFilter = implode('*', array_map(static fn (string $p): string => LdapEscape::filter($p), explode('*', $groupPattern)));

        return new self(
            implode(' ', $hosts),
            3,
            $c->raw('Ad', 'BindUser'),
            $c->raw('Ad', 'BindPassword'),
            $c->bool('Ad', 'StartTLS'),
            $baseDn,
            '(&(objectCategory=person)(objectClass=user))',
            ['sAMAccountName', 'displayName'],
            $c->string('Ad', 'DisplayNameFormat', '%displayName'),
            $baseDn,
            '(&(objectClass=group)(cn=' . $cnFilter . '))',
            ['cn'],
            'member',
            'distinguishedName',
            true,
            $c->bool('Groups:ad', 'StrictMode'),
        );
    }

    public static function domainToDn(string $domain): string
    {
        $parts = array_filter(explode('.', trim($domain)), static fn (string $p): bool => $p !== '');

        return implode(',', array_map(static fn (string $p): string => 'DC=' . LdapEscape::dn($p), $parts));
    }

    /** @return list<string> */
    private static function list(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $s): bool => $s !== ''));
    }
}
