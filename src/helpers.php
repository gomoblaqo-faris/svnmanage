<?php

declare(strict_types=1);

use SvnManage\View\View;

if (!function_exists('e')) {
    /** HTML-escape a value for text and attribute contexts. */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('t')) {
    /** Translate (and escape) a UI string. Placeholders: %0, %1 … */
    function t(string $text, string|int ...$args): string
    {
        return e(View::current()->tr->t($text, array_values($args)));
    }
}

if (!function_exists('tr')) {
    /** Translate without escaping (for use inside other escaped contexts). */
    function tr(string $text, string|int ...$args): string
    {
        return View::current()->tr->t($text, array_values($args));
    }
}

if (!function_exists('url')) {
    /** @param array<string, string|int|null> $query */
    function url(string $path = '/', array $query = []): string
    {
        return e(View::current()->url($path, $query));
    }
}

if (!function_exists('seg')) {
    /** Encode a value as one URL path segment. */
    function seg(string $value): string
    {
        return rawurlencode($value);
    }
}

if (!function_exists('icon')) {
    function icon(string $name, string $class = 'size-4'): string
    {
        return '<svg class="' . e($class) . '" aria-hidden="true" focusable="false"><use href="'
            . e(View::current()->asset('icons.svg')) . '#' . e($name) . '"></use></svg>';
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(View::current()->csrf()) . '">';
    }
}

if (!function_exists('human_bytes')) {
    function human_bytes(?int $bytes): string
    {
        if ($bytes === null) {
            return '—';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return ($i === 0 ? (string) $bytes : number_format($value, 1)) . ' ' . $units[$i];
    }
}

if (!function_exists('ago')) {
    function ago(?DateTimeInterface $date): string
    {
        if ($date === null) {
            return '—';
        }
        $diff = time() - $date->getTimestamp();
        $tr = View::current()->tr;

        return match (true) {
            $diff < 60 => $tr->t('just now'),
            $diff < 3600 => $tr->t('%0 min ago', [intdiv($diff, 60)]),
            $diff < 86400 => $tr->t('%0 h ago', [intdiv($diff, 3600)]),
            $diff < 86400 * 30 => $tr->t('%0 d ago', [intdiv($diff, 86400)]),
            default => $date->format('Y-m-d'),
        };
    }
}

if (!function_exists('initials')) {
    function initials(string $name): string
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', trim($name)) ?: [];
        $letters = '';
        foreach (array_slice(array_filter($parts), 0, 2) as $p) {
            $letters .= mb_strtoupper(mb_substr($p, 0, 1));
        }

        return $letters === '' ? '?' : $letters;
    }
}

if (!function_exists('avatar_hue')) {
    function avatar_hue(string $name): int
    {
        return (int) (hexdec(substr(md5($name), 0, 4)) % 360);
    }
}
