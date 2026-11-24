<?php

declare(strict_types=1);

namespace SvnManage\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SvnManage\I18n\Translator;

final class TranslationCatalogTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function locales(): iterable
    {
        foreach (array_keys(Translator::LOCALES) as $locale) {
            if ($locale !== 'en_US') {
                yield $locale => [$locale];
            }
        }
    }

    #[DataProvider('locales')]
    public function testCatalogIsCompleteAndKeepsPlaceholders(string $locale): void
    {
        $root = dirname(__DIR__, 2);
        $catalog = require $root . '/lang/' . $locale . '.php';
        self::assertIsArray($catalog);
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/extract-strings.php'), $out, $code);
        self::assertSame(0, $code);
        $strings = json_decode(implode("\n", $out), true, flags: JSON_THROW_ON_ERROR);

        $missing = array_values(array_filter($strings, static fn (string $s): bool => !isset($catalog[$s])));
        self::assertSame([], $missing, "$locale is missing translations");

        foreach ($catalog as $source => $translation) {
            preg_match_all('/%\d/', (string) $source, $a);
            preg_match_all('/%\d/', (string) $translation, $b);
            $expected = $a[0];
            $actual = $b[0];
            sort($expected);
            sort($actual);
            self::assertSame($expected, $actual, "$locale placeholder mismatch: $source");
            self::assertNotSame('', trim((string) $translation), "$locale empty translation: $source");
        }
    }

    public function testTranslatorSubstitutesArguments(): void
    {
        $tr = Translator::load(dirname(__DIR__, 2) . '/lang', 'de_DE');
        self::assertStringContainsString('alice', $tr->t('The user %0 has been deleted.', ['alice']));
        self::assertNotSame('The user alice has been deleted.', $tr->t('The user %0 has been deleted.', ['alice']));
        self::assertSame('Unknown text %0', Translator::load(dirname(__DIR__, 2) . '/lang', 'de_DE')->t('Unknown text %0'));
    }
}
