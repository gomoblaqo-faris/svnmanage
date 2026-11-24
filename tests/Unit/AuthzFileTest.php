<?php

declare(strict_types=1);

namespace SvnManage\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SvnManage\Authz\AuthzFile;
use SvnManage\Authz\Names;
use SvnManage\Authz\Permission;

final class AuthzFileTest extends TestCase
{
    private const SAMPLE = <<<INI
    [aliases]
    joe = joseph

    [groups]
    devs = alice, bob, &joe
    leads = @devs, carol
    all = @leads

    # Root of the project
    [project:/]
    @devs = rw
    * = r

    [project:/secret]
    @devs =
    carol = rw
    ~@leads = r
    INI;

    private function authz(): AuthzFile
    {
        return AuthzFile::parse(self::SAMPLE);
    }

    public function testGroupsAndMembers(): void
    {
        $a = $this->authz();
        self::assertSame(['devs', 'leads', 'all'], $a->groupNames());
        self::assertSame(['alice', 'bob', '&joe'], $a->groupUsers('devs'));
        self::assertSame(['devs'], $a->groupSubgroups('leads'));
        self::assertSame(['devs'], $a->groupsOfUser('alice'));
        self::assertSame(['devs'], $a->groupsOfUser('joseph'), 'alias resolves to its user');
    }

    public function testEffectiveGroupsAreTransitive(): void
    {
        self::assertSame(['devs' => null, 'leads' => 'devs', 'all' => 'leads'], $this->authz()->effectiveGroupsOfUser('alice'));
    }

    public function testEffectivePermissionsListsDirectGroupAndEveryone(): void
    {
        $perms = $this->authz()->effectivePermissions('carol');
        $flat = array_map(static fn (array $p): string => $p['path'] . '|' . $p['permission']->value . '|' . $p['via'], $perms);
        self::assertSame(['project:/|r|*', 'project:/secret|rw|'], $flat);

        $alice = array_map(static fn (array $p): string => $p['path'] . '|' . $p['via'], $this->authz()->effectivePermissions('alice'));
        self::assertSame(['project:/|@devs', 'project:/|*', 'project:/secret|@devs'], $alice);
    }

    public function testCreateAndDeleteGroupCascades(): void
    {
        $a = $this->authz()->withoutGroup('devs');
        self::assertFalse($a->hasGroup('devs'));
        self::assertSame(['carol'], $a->groupMembers('leads'));
        self::assertArrayNotHasKey('@devs', $a->rules('project:/'));
        self::assertArrayNotHasKey('@devs', $a->rules('project:/secret'));
        self::assertStringContainsString('# Root of the project', $a->render());
    }

    public function testDuplicateGroupRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->authz()->withGroup('devs');
    }

    public function testInvalidGroupNameRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->authz()->withGroup('bad name');
    }

    public function testMembershipCycleRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->authz()->withGroupMember('devs', '@all');
    }

    public function testAddRemoveMembersKeepsGroupsFirst(): void
    {
        $a = AuthzFile::empty()->withGroup('a')->withGroup('b')
            ->withGroupMember('a', 'zed')->withGroupMember('a', '@b')->withGroupMember('a', 'zed');
        self::assertSame(['@b', 'zed'], $a->groupMembers('a'));
        self::assertSame([], $a->withoutGroupMember('a', 'zed')->withoutGroupMember('a', '@b')->groupMembers('a'));
    }

    public function testRenameGroupUpdatesReferences(): void
    {
        $a = $this->authz()->withRenamedGroup('leads', 'chiefs');
        self::assertTrue($a->hasGroup('chiefs'));
        self::assertSame(['@chiefs'], $a->groupMembers('all'));
        self::assertArrayHasKey('~@chiefs', $a->rules('project:/secret'));
    }

    public function testAccessPathsAndRules(): void
    {
        $a = $this->authz()->withAccessPath('other:/trunk/')->withRule('other:/trunk', '@devs', Permission::Read)
            ->withRule('other:/trunk', 'zoe', Permission::ReadWrite);
        self::assertSame(['project:/', 'project:/secret', 'other:/trunk'], $a->accessPaths());
        self::assertSame(['zoe' => Permission::ReadWrite], $a->userRules('other:/trunk'));
        self::assertSame(['devs' => Permission::Read], $a->groupRules('other:/trunk'));
        self::assertSame(['project:/', 'project:/secret'], $a->accessPathsOfRepository('project'));
        self::assertSame([], $a->withoutAccessPath('other:/trunk')->accessPathsOfRepository('other'));
    }

    public function testRuleForUnknownGroupRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->authz()->withRule('project:/', '@ghosts', Permission::Read);
    }

    public function testWithoutUserRemovesEverywhere(): void
    {
        $a = $this->authz()->withoutUser('carol');
        self::assertSame(['@devs'], $a->groupMembers('leads'));
        self::assertArrayNotHasKey('carol', $a->rules('project:/secret'));
    }

    public function testPermissionsOfGroup(): void
    {
        $perms = $this->authz()->permissionsOfGroup('devs');
        self::assertSame('project:/', $perms[0]['path']);
        self::assertSame(Permission::None, $perms[1]['permission']);
    }

    public function testNamesValidation(): void
    {
        self::assertSame('repo:/a/b', Names::accessPath(' repo:/a//b/ '));
        self::assertSame('repo:/', Names::accessPath('repo:/'));
        self::assertSame('/', Names::accessPath('/'));
        self::assertSame('repo', Names::repositoryOfPath('repo:/x'));
        self::assertSame('x/y', Names::relativeOfPath('repo:/x/y'));
        self::assertTrue(Names::isWithin('repo:/a/b', 'repo:/a'));
        self::assertFalse(Names::isWithin('repo:/ab', 'repo:/a'));
        self::assertTrue(Names::isWithin('repo:/ab', 'repo:/'));
        foreach (['repo:/../x', 'no-colon', 'repo:/[x]', "r:/a\nb"] as $bad) {
            try {
                Names::accessPath($bad);
                self::fail('Expected rejection of ' . $bad);
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        Names::user('*');
    }

    public function testPermissionParsing(): void
    {
        self::assertSame(Permission::ReadWrite, Permission::fromAuthz(' rw'));
        self::assertSame(Permission::None, Permission::fromInput('none'));
        self::assertSame(Permission::Read, Permission::fromInput('Read'));
    }

    public function testWouldCycleDetectsAncestors(): void
    {
        $authz = AuthzFile::parse("[groups]\nall = @developers\ndevelopers = alice\nother = bob\n");
        self::assertTrue($authz->wouldCycle('developers', 'all'), 'all already contains developers');
        self::assertTrue($authz->wouldCycle('developers', 'developers'));
        self::assertFalse($authz->wouldCycle('developers', 'other'));
        self::assertFalse($authz->wouldCycle('all', 'other'));
    }
}
