<?php

declare(strict_types=1);

namespace SvnManage\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SvnManage\Acl\RoleAssignments;
use SvnManage\Authz\AuthzFile;
use SvnManage\Authz\Names;
use SvnManage\Authz\Permission;
use SvnManage\Support\IniDocument;

/** Regression tests for INI/authz injection (security review finding #1). */
final class InjectionTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function unsafeEntries(): iterable
    {
        yield 'newline in key' => ['s', "x]\n[bob", 'v'];
        yield 'newline in value' => ['s', 'k', "v\n[evil]\nk = v"];
        yield 'carriage return in key' => ['s', "a\rb", 'v'];
        yield 'nul in value' => ['s', 'k', "v\0"];
        yield 'bracket in section' => ['a]b', 'k', 'v'];
        yield 'newline in section' => ["a\n[b", 'k', 'v'];
        yield 'assign in key' => ['s', 'evil = rw', 'r'];
        yield 'key looks like section' => ['s', '[evil]', 'r'];
        yield 'key looks like comment' => ['s', '#x', 'r'];
        yield 'empty key' => ['s', '', 'r'];
    }

    #[DataProvider('unsafeEntries')]
    public function testIniDocumentRejectsStructureBreakingInput(string $section, string $key, string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        IniDocument::parse("[s]\na = b\n", ' = ')->with($section, $key, $value);
    }

    public function testIniDocumentRejectsUnsafeRenames(): void
    {
        $doc = IniDocument::parse("[s]\na = b\n", ' = ');
        foreach ([fn () => $doc->withRenamedSection('s', "x]\n[y"), fn () => $doc->withRenamedKey('s', 'a', "a\nb"), fn () => $doc->withSection("x\ny")] as $op) {
            try {
                $op();
                self::fail('expected rejection');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function badTokens(): iterable
    {
        yield 'section break' => ["evil = rw\n[repo:/secret]\nevil"];
        yield 'space' => ['bad name'];
        yield 'equals' => ['a=b'];
        yield 'bad group' => ['@x]y'];
        yield 'double inversion' => ['~~alice'];
        yield 'unknown special' => ['$everyone'];
        yield 'trailing newline' => ["alice\n"];
    }

    #[DataProvider('badTokens')]
    public function testTokenValidatorRejects(string $token): void
    {
        $this->expectException(InvalidArgumentException::class);
        Names::token($token);
    }

    public function testTokenValidatorAccepts(): void
    {
        foreach (['alice', 'a.b@corp', '@devs', '&alias', '*', '$authenticated', '$anonymous', '~@devs', '~alice'] as $token) {
            self::assertSame($token, Names::token($token));
        }
    }

    public function testAuthzRejectsInjectedRuleAndMemberTokens(): void
    {
        $authz = AuthzFile::parse("[groups]\ng1 = a\n\n[repo:/mine]\n");
        foreach ([
            fn () => $authz->withRule('repo:/mine', "evil = rw\n[/]\n* ", Permission::ReadWrite),
            fn () => $authz->withGroupMember('g1', "x\n[repo:/]\n* = rw"),
            fn () => $authz->withGroup("g\n[x]"),
        ] as $op) {
            try {
                $op();
                self::fail('expected rejection');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame(['repo:/mine'], $authz->accessPaths());
    }

    public function testRoleAssignmentsRejectForgedUserSections(): void
    {
        $roles = RoleAssignments::parse('');
        foreach ([
            fn () => $roles->withRole("x]\n[bob]\nAdministrator=\n[y", 'User'),
            fn () => $roles->withRole('bad name', 'User'),
            fn () => $roles->withManagedPath("x\n[bob]", 'repo:/a'),
        ] as $op) {
            try {
                $op();
                self::fail('expected rejection');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame([], $roles->usersWithRole('Administrator'));
    }
}
