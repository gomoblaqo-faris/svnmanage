<?php

declare(strict_types=1);

namespace SvnManage\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SvnManage\Authz\AuthzFile;
use SvnManage\Config\Config;
use SvnManage\Directory\CachedDirectory;
use SvnManage\Directory\Directory;
use SvnManage\Directory\DirectoryGroup;
use SvnManage\Directory\DirectorySync;
use SvnManage\Directory\LdapDirectory;
use SvnManage\Directory\LdapOptions;
use SvnManage\Users\User;

final class DirectoryTest extends TestCase
{
    public function testSyncReplacesGroupsAndCleansOrphans(): void
    {
        $authz = AuthzFile::parse("[groups]\nold = x\nkeep = a\n\n[repo:/]\n@old = rw\n@keep = r\nghost = rw\nalice = r\n* = r\n");
        $plan = (new DirectorySync(true, true))->plan(
            $authz,
            [new DirectoryGroup('keep', ['alice', 'bob']), new DirectoryGroup('new', ['bob'], ['keep']), new DirectoryGroup('bad name', ['x'])],
            ['alice', 'bob'],
        );
        $a = $plan->authz;
        self::assertSame(['keep', 'new'], $a->groupNames());
        self::assertSame(['alice', 'bob'], $a->groupMembers('keep'));
        self::assertSame(['@keep', 'bob'], $a->groupMembers('new'));
        self::assertSame(['@keep' => 'r', 'alice' => 'r', '*' => 'r'], $a->rules('repo:/'));
        self::assertSame(['new'], $plan->addedGroups);
        self::assertSame(['old'], $plan->removedGroups);
        self::assertSame(['ghost'], $plan->removedUsers);
        self::assertTrue($plan->changed);
        self::assertSame('warning', $plan->messages[0][0], 'invalid group name reported');
    }

    public function testSyncKeepsPermissionsWhenAutoRemoveDisabled(): void
    {
        $authz = AuthzFile::parse("[groups]\nold = x\n\n[repo:/]\n@old = rw\nghost = rw\n");
        $plan = (new DirectorySync(false, false))->plan($authz, [], []);
        self::assertSame(['@old' => 'rw', 'ghost' => 'rw'], $plan->authz->rules('repo:/'));
        self::assertSame([], $plan->authz->groupNames());
    }

    public function testUserOnlySyncKeepsAuthzGroups(): void
    {
        $authz = AuthzFile::parse("[groups]\nlocal = alice, ghost\n\n[repo:/]\n@local = rw\nghost = r\nalice = r\n");
        $plan = (new DirectorySync(true, true))->plan($authz, null, ['alice']);
        self::assertSame(['local'], $plan->authz->groupNames(), 'groups are not directory managed');
        self::assertSame(['@local' => 'rw', 'alice' => 'r'], $plan->authz->rules('repo:/'));
        self::assertSame(['ghost'], $plan->removedUsers);
        self::assertSame([], $plan->addedGroups);
        self::assertTrue($plan->changed);
    }

    public function testDisplayNameFormatAndNormalize(): void
    {
        $entries = LdapDirectory::normalize([
            'count' => 1,
            0 => ['dn' => 'uid=ada,dc=x', 'uid' => ['count' => 1, 0 => 'ada'], 'givenName' => ['count' => 1, 0 => 'Ada'], 'count' => 2],
        ]);
        self::assertSame('ada', $entries[0]['uid'][0]);
        self::assertSame('Ada (ada)', LdapDirectory::formatDisplayName('%givenName %sn (%uid)', $entries[0]));
        self::assertNull(LdapDirectory::formatDisplayName('', $entries[0]));
    }

    public function testAdOptionsDerivedFromDomain(): void
    {
        $cfg = Config::fromString("[Ad]\nDomainName=corp.example.com\nDomainController=dc1.corp.example.com, ldaps://dc2\nBindUser=svc@corp\n[Groups:ad]\nFilter=SVN-*\nStrictMode=true\n", '/app');
        $o = LdapOptions::fromAdConfig($cfg);
        self::assertSame('DC=corp,DC=example,DC=com', $o->userBaseDn);
        self::assertSame('ldap://dc1.corp.example.com ldaps://dc2', $o->host);
        self::assertSame('(&(objectClass=group)(cn=SVN-*))', $o->groupFilter);
        self::assertTrue($o->nestedGroups);
        self::assertTrue($o->strictMode);
    }

    public function testCachedDirectoryServesSnapshot(): void
    {
        $inner = new class () implements Directory {
            public int $calls = 0;

            public function users(): array
            {
                $this->calls++;

                return [new User('ann', 'Ann')];
            }

            public function groups(): array
            {
                return [new DirectoryGroup('g', ['ann'])];
            }

            public function authenticate(string $username, string $password): bool
            {
                return $password === 'ok';
            }
        };
        $file = sys_get_temp_dir() . '/svnmanage-cache-' . bin2hex(random_bytes(3)) . '.json';
        $cached = new CachedDirectory($inner, $file);
        $cached->refresh();
        $inner->calls = 0;
        self::assertSame('Ann', $cached->users()[0]->displayName);
        self::assertSame(['ann'], $cached->groups()[0]->users);
        self::assertSame(0, $inner->calls);
        self::assertTrue($cached->authenticate('ann', 'ok'));
        self::assertNotNull($cached->updatedAt());
        unlink($file);
    }
}
