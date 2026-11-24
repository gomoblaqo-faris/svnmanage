<?php

declare(strict_types=1);

namespace SvnManage\Directory;

/**
 * ldap_escape() without ext-ldap: the same output as PHP's LDAP_ESCAPE_FILTER and
 * LDAP_ESCAPE_DN (compared byte for byte in LdapEscapeTest when the extension is loaded),
 * so options can be built, and the settings tests can report "extension missing", on
 * servers that do not load the extension.
 */
final class LdapEscape
{
    private const FILTER_CHARS = "\\*()\0";
    private const DN_CHARS = "\\,=+<>;\"#\r";

    public static function filter(string $value): string
    {
        return self::escape($value, self::FILTER_CHARS);
    }

    /** Also escapes a leading and a trailing space, which RFC 4514 requires (as PHP does). */
    public static function dn(string $value): string
    {
        $out = self::escape($value, self::DN_CHARS);
        if (str_starts_with($out, ' ')) {
            $out = '\\20' . substr($out, 1);
        }
        if (str_ends_with($out, ' ')) {
            $out = substr($out, 0, -1) . '\\20';
        }

        return $out;
    }

    private static function escape(string $value, string $chars): string
    {
        return (string) preg_replace_callback(
            '/[' . preg_quote($chars, '/') . ']/',
            static fn (array $m): string => sprintf('\\%02x', ord($m[0])),
            $value,
        );
    }
}
