<?php

declare(strict_types=1);

namespace SvnManage\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SvnManage\Support\IniDocument;

final class IniDocumentTest extends TestCase
{
    private const SAMPLE = <<<INI
    # Global comment
    [groups]
    ; developers
    devs = alice, bob
    ops = carol

    [repo:/]
    @devs = rw
    * = r
    INI;

    public function testRoundTripIsLossless(): void
    {
        $doc = IniDocument::parse(self::SAMPLE);
        self::assertSame(self::SAMPLE . "\n", $doc->render());
    }

    public function testReadsSectionsAndEntries(): void
    {
        $doc = IniDocument::parse(self::SAMPLE);
        self::assertSame(['groups', 'repo:/'], $doc->sections());
        self::assertSame(['devs' => 'alice, bob', 'ops' => 'carol'], $doc->entries('groups'));
        self::assertSame('r', $doc->get('repo:/', '*'));
        self::assertNull($doc->get('repo:/', 'nobody'));
    }

    public function testWithIsImmutableAndUpdatesInPlace(): void
    {
        $doc = IniDocument::parse(self::SAMPLE);
        $next = $doc->with('groups', 'devs', 'alice');
        self::assertSame('alice, bob', $doc->get('groups', 'devs'));
        self::assertSame('alice', $next->get('groups', 'devs'));
        self::assertStringContainsString("; developers\ndevs = alice\nops = carol", $next->render());
    }

    public function testNewEntryGoesAfterLastEntryOfSection(): void
    {
        $next = IniDocument::parse(self::SAMPLE)->with('groups', 'qa', 'dave');
        self::assertStringContainsString("ops = carol\nqa = dave\n\n[repo:/]", $next->render());
    }

    public function testNewSectionAppended(): void
    {
        $next = IniDocument::parse(self::SAMPLE)->with('other:/trunk', 'eve', 'r');
        self::assertStringEndsWith("* = r\n\n[other:/trunk]\neve = r\n", $next->render());
    }

    public function testEmptyValueRendersWithoutTrailingSpace(): void
    {
        $next = IniDocument::parse(self::SAMPLE)->with('repo:/', 'mallory', '');
        self::assertStringContainsString("mallory =\n", $next->render());
    }

    public function testRemoveEntryAndSection(): void
    {
        $doc = IniDocument::parse(self::SAMPLE)->without('groups', 'ops')->withoutSection('repo:/');
        self::assertSame(['groups'], $doc->sections());
        self::assertFalse($doc->has('groups', 'ops'));
    }

    public function testContinuationLinesAreJoined(): void
    {
        $doc = IniDocument::parse("[groups]\nbig = a, b,\n  c, d\nx = y\n");
        self::assertSame('a, b, c, d', $doc->get('groups', 'big'));
        self::assertSame("[groups]\nbig = a, b,\n  c, d\nx = y\n", $doc->render());
    }

    public function testRenameSectionAndKey(): void
    {
        $doc = IniDocument::parse(self::SAMPLE)->withRenamedSection('repo:/', 'new:/')->withRenamedKey('groups', 'ops', 'operations');
        self::assertSame(['groups', 'new:/'], $doc->sections());
        self::assertSame('carol', $doc->get('groups', 'operations'));
        self::assertStringContainsString("devs = alice, bob\noperations = carol", $doc->render());
    }

    public function testCompactAssignStyle(): void
    {
        $doc = IniDocument::empty('=')->with('Common', 'FirstStart', '1');
        self::assertSame("[Common]\nFirstStart=1\n", $doc->render());
    }

    public function testDuplicateKeysCollapseOnWrite(): void
    {
        $doc = IniDocument::parse("[a]\nk = 1\nk = 2\n");
        self::assertSame('2', $doc->get('a', 'k'));
        self::assertSame("[a]\nk = 3\n", $doc->with('a', 'k', '3')->render());
    }

    public function testCrlfAndBomTolerated(): void
    {
        $doc = IniDocument::parse("\xEF\xBB\xBF[a]\r\nk = v\r\n");
        self::assertSame('v', $doc->get('a', 'k'));
    }
}
