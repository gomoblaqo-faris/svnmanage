<?php

declare(strict_types=1);

namespace SvnManage\I18n;

/**
 * gettext-style translator: English source strings are the keys.
 * Placeholders use iF.SVNAdmin's %0, %1 … convention.
 */
final class Translator
{
    public const LOCALES = [
        'en_US' => 'English',
        'de_DE' => 'Deutsch',
        'es_ES' => 'Español',
        'ja_JP' => '日本語',
        'pt_BR' => 'Português (Brasil)',
        'uk_UA' => 'Українська',
        'zh_CN' => '中文',
    ];

    /** @param array<string, string> $catalog */
    public function __construct(
        public readonly string $locale,
        private readonly array $catalog = [],
    ) {
    }

    public static function load(string $langDir, string $locale): self
    {
        $locale = isset(self::LOCALES[$locale]) ? $locale : 'en_US';
        $file = $langDir . '/' . $locale . '.php';
        $catalog = $locale !== 'en_US' && is_file($file) ? require $file : [];

        return new self($locale, is_array($catalog) ? $catalog : []);
    }

    /** @param list<string|int> $args */
    public function t(string $text, array $args = []): string
    {
        $map = [];
        foreach (array_values($args) as $i => $arg) {
            $map['%' . $i] = (string) $arg;
        }

        // One pass: an argument that itself contains "%1" is not substituted again.
        return strtr($this->catalog[$text] ?? $text, $map);
    }

    public function htmlLang(): string
    {
        return str_replace('_', '-', $this->locale);
    }

    public static function negotiate(string $acceptLanguage): string
    {
        foreach (explode(',', $acceptLanguage) as $part) {
            $tag = strtolower(trim(explode(';', $part)[0]));
            foreach (array_keys(self::LOCALES) as $locale) {
                if ($tag === strtolower(str_replace('_', '-', $locale)) || $tag === substr(strtolower($locale), 0, 2)) {
                    return $locale;
                }
            }
        }

        return 'en_US';
    }
}
