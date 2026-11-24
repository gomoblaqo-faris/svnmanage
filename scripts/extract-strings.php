<?php

declare(strict_types=1);

/*
 * Lists every translatable UI string (English source text).
 *   php scripts/extract-strings.php            -> JSON list on stdout
 *   php scripts/extract-strings.php --missing  -> per-locale count of untranslated strings
 */
require __DIR__ . '/../src/autoload.php';

const SKIP = '/^(Content-|Cross-|CF-|Forwarded$|Windows$|Permissions-|X-|HX-|Cache-|DTSTART|GET|POST|PUT|DELETE|SVN[A-Z]|Engine:|Users:|Groups:|Repositories:|Update:|Ldap|Ad$|GUI$|Common$|Subversion$|ACLManager|LC_|UTF-8|Y-m-d|D, d|ISO|DATE_|SAMEORIGIN|Lax|Strict|[A-Z][A-Za-z]+Type$|[A-Z][a-z]+[A-Z]\w*$)/';

function strings(string $root): array
{
    $files = [];
    foreach (['templates', 'src'] as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir", FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() === 'php' && !str_contains($f->getPathname(), '/src/Cli/')) {
                $files[] = $f->getPathname();
            }
        }
    }
    $out = [];
    foreach ($files as $file) {
        $recent = []; // the last two significant tokens, to spot t('…'), tr('…') and ->t('…')
        foreach (token_get_all((string) file_get_contents($file)) as $tok) {
            if (is_array($tok) && in_array($tok[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $isTranslated = count($recent) === 2 && $recent[1] === '(' && in_array($recent[0], ['t', 'tr'], true);
            $recent = [$recent[1] ?? null, is_array($tok) ? $tok[1] : $tok];
            if (!is_array($tok) || $tok[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $s = stripcslashes(substr($tok[1], 1, -1));
            if (str_starts_with($tok[1], "'")) {
                $s = str_replace(["\\'", '\\\\'], ["'", '\\'], substr($tok[1], 1, -1));
            }
            // Lowercase text is only taken when it is passed straight to a translation call.
            if (!$isTranslated && !preg_match('/^[A-Z(…]/u', $s) && !str_contains($s, '%0')) {
                continue;
            }
            if (preg_match(SKIP, $s) || preg_match('/[<>{}$=\\\\]|%s|^\W+$|https?:|\.(php|ini|svg|css|js)\b|^[A-Z]{1,5}$|^[A-Z_]+$|^[A-Za-z0-9]{24,}$|^(Ymd-His|Referrer-Policy|SVN Privat|SVN-\*|Repositories #)$/', $s) || strlen($s) < 2 || in_array($s, SvnManage\I18n\Translator::LOCALES, true)) {
                continue;
            }
            $out[$s] = true;
        }
    }
    $list = array_keys($out);
    sort($list, SORT_STRING | SORT_FLAG_CASE);

    return $list;
}

$root = dirname(__DIR__);
$list = strings($root);
if (in_array('--missing', $argv, true)) {
    foreach (array_keys(SvnManage\I18n\Translator::LOCALES) as $locale) {
        if ($locale === 'en_US') {
            continue;
        }
        $file = "$root/lang/$locale.php";
        $catalog = is_file($file) ? require $file : [];
        $missing = array_values(array_filter($list, static fn (string $s): bool => !isset($catalog[$s])));
        printf("%s: %d/%d missing\n", $locale, count($missing), count($list));
    }
    exit(0);
}
echo json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
