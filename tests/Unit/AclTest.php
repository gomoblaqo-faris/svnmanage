<?php

declare(strict_types=1);

namespace SvnManage\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SvnManage\Acl\Acl;
use SvnManage\Acl\Authorizer;
use SvnManage\Acl\RoleAssignments;

final class AclTest extends TestCase
{
    public function testRoleInheritance(): void
    {
        self::assertTrue(Acl::allows(['Repository-Manager'], Acl::MOD_REPO, Acl::ADD), 'inherited from Repository-Creator');
        self::assertTrue(Acl::allows(['Repository-Manager'], Acl::MOD_BASIC, Acl::LOGIN), 'inherited from User');
        self::assertFalse(Acl::allows(['Repository-Creator'], Acl::MOD_REPO, Acl::DELETE));
        self::assertTrue(Acl::allows([Acl::ADMINISTRATOR], Acl::MOD_ROLE, Acl::ASSIGN_ADMIN));
        self::assertFalse(Acl::allows(['Role-Manager'], Acl::MOD_ROLE, Acl::ASSIGN_ADMIN));
        self::assertFalse(Acl::allows([], Acl::MOD_BASIC, Acl::LOGIN));
        self::assertTrue(Acl::allows([], Acl::MOD_ACCESSPATH, Acl::ASSIGN, true), 'project manager');
        self::assertFalse(Acl::allows([], Acl::MOD_BASIC, Acl::LOGIN, true), 'PM does not imply login, same as original');
    }

    public function testCapabilitiesFlatten(): void
    {
        $caps = Acl::capabilities('Repository-Manager');
        self::assertIsArray($caps);
        self::assertEqualsCanonicalizing([Acl::DELETE, Acl::VIEW, Acl::ADD], $caps[Acl::MOD_REPO]);
        self::assertSame('*', Acl::capabilities(Acl::ADMINISTRATOR));
    }

    public function testAssignmentsFileCompatibleFormat(): void
    {
        $text = "\n[admin]\nAdministrator=\n\n[pm:AccessPaths]\n0=repo:/a\n1=repo:/b\n";
        $ra = RoleAssignments::parse($text);
        self::assertSame(['Administrator'], $ra->rolesOf('admin'));
        self::assertTrue($ra->hasAdmin());
        self::assertSame(['repo:/a', 'repo:/b'], $ra->managedPaths('pm'));
        self::assertSame(['pm'], $ra->managersOf('repo:/b'));
        self::assertSame([], $ra->rolesOf('pm:AccessPaths'));

        $next = $ra->withoutManagedPath('pm', 'repo:/a')->withRole('pm', 'User');
        self::assertSame(['repo:/b'], $next->managedPaths('pm'));
        self::assertStringContainsString("[pm:AccessPaths]\n0=repo:/b", $next->render());
        self::assertSame(['User'], $next->rolesOf('pm'));
        self::assertSame([], $next->withoutUser('pm')->managedPaths('pm'));
        self::assertSame([], $next->withoutRole('pm', 'User')->rolesOf('pm'));
        self::assertSame([], $ra->withoutPathEverywhere('repo:/a')->withoutPathEverywhere('repo:/b')->allManagers());
    }

    public function testRejectsUnknownRoleAndRootManager(): void
    {
        $ra = RoleAssignments::parse('');
        try {
            $ra->withRole('x', 'Wizard');
            self::fail('expected exception');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        $this->expectException(InvalidArgumentException::class);
        $ra->withManagedPath('x', '/');
    }

    public function testAuthorizerScopesProjectManagers(): void
    {
        $pm = new Authorizer(true, 'pm', ['User'], ['repo:/a']);
        self::assertTrue($pm->isPathScoped());
        self::assertTrue($pm->canManagePath('repo:/a/trunk'));
        self::assertFalse($pm->canManagePath('repo:/ab'));
        self::assertSame(['repo:/a'], $pm->filterPaths(['repo:/a', 'repo:/ab', 'other:/']));

        $admin = new Authorizer(true, 'root', [Acl::ADMINISTRATOR], ['repo:/a']);
        self::assertFalse($admin->isPathScoped(), 'real role wins over PM scope');
        self::assertTrue((new Authorizer(false, null, [], []))->can(Acl::MOD_SETTINGS, Acl::CHANGE), 'auth off => all allowed');
        self::assertFalse(Authorizer::anonymous(true)->can(Acl::MOD_BASIC, Acl::LOGIN));
    }

    public function testScopedManagerRepositoryVisibility(): void
    {
        $pm = new \SvnManage\Acl\Authorizer(true, 'pm', [], ['alpha:/trunk']);
        self::assertTrue($pm->canSeeRepository('alpha'));
        self::assertFalse($pm->canSeeRepository('beta'));
        self::assertTrue($pm->canBrowse('alpha', ''), 'ancestor of a managed path is navigable');
        self::assertTrue($pm->canBrowse('alpha', 'trunk/src'));
        self::assertFalse($pm->canBrowse('alpha', 'branches'));
        self::assertFalse($pm->canBrowse('alpha', 'trunkx'));
        self::assertFalse($pm->canBrowse('beta', ''));

        $global = new \SvnManage\Acl\Authorizer(true, 'g', [], ['/docs']);
        self::assertTrue($global->canSeeRepository('anything'), 'a repository-less path applies to every repository');
        self::assertTrue($global->canBrowse('anything', 'docs/x'));
        self::assertFalse($global->canBrowse('anything', 'src'));

        $admin = new \SvnManage\Acl\Authorizer(true, 'a', ['Administrator'], []);
        self::assertTrue($admin->canSeeRepository('beta'));
        self::assertTrue($admin->canBrowse('beta', 'any/where'));
    }
}
