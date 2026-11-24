<?php

declare(strict_types=1);

namespace SvnManage\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SvnManage\Directory\LdapEscape;

/** Escaping must work without ext-ldap: option building and the settings tests run on servers that lack it. */
final class LdapEscapeTest extends TestCase
{
    private const SAMPLES = ['plain', 'SVN-dev', 'a*b', '(x)', 'back\\slash', "nul\0byte", 'cn=x,ou=y', 'a+b<c>d;e"f#g', "cr\rlf", 'ünï', ' lead', 'trail ', '  both  ', ' ', 'in side'];

    public function testFilterEscapesFilterSpecials(): void
    {
        self::assertSame('a\\2ab\\28c\\29d\\5ce\\00', LdapEscape::filter("a*b(c)d\\e\0"));
        self::assertSame('SVN-dev', LdapEscape::filter('SVN-dev'));
    }

    public function testDnEscapesDnSpecials(): void
    {
        self::assertSame('a\\2cb\\3dc\\2bd\\3ce\\3ef\\3bg\\22h\\23i\\5cj\\0dk', LdapEscape::dn("a,b=c+d<e>f;g\"h#i\\j\rk"));
        self::assertSame('example', LdapEscape::dn('example'));
    }

    public function testDnEscapesLeadingAndTrailingSpacesOnly(): void
    {
        self::assertSame('\\20a b\\20', LdapEscape::dn(' a b '));
        self::assertSame('\\20 a \\20', LdapEscape::dn('  a  '));
        self::assertSame(' a ', LdapEscape::filter(' a '), 'filters keep spaces');
    }

    public function testMatchesTheExtensionWhenItIsLoaded(): void
    {
        if (!function_exists('ldap_escape')) {
            self::markTestSkipped('ext-ldap not loaded');
        }
        foreach (self::SAMPLES as $s) {
            self::assertSame(ldap_escape($s, '', LDAP_ESCAPE_FILTER), LdapEscape::filter($s), $s);
            self::assertSame(ldap_escape($s, '', LDAP_ESCAPE_DN), LdapEscape::dn($s), $s);
        }
    }
}
