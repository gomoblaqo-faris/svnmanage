<?php

declare(strict_types=1);

/*
 * Builds public/assets/icons.svg from lucide-static (ISC license) with only
 * the icons svnmanage uses. Run: php scripts/build-icons.php
 */
$icons = [
    'layout-dashboard', 'database', 'users', 'user', 'user-plus', 'user-minus', 'user-cog', 'users-round',
    'shield', 'shield-check', 'key-round', 'folder-tree', 'route', 'lock', 'lock-open', 'refresh-cw', 'settings',
    'history', 'archive', 'log-out', 'menu', 'x', 'plus', 'trash-2', 'search', 'chevron-right', 'chevron-left',
    'chevron-down', 'folder', 'folder-open', 'file', 'file-text', 'download', 'upload', 'check', 'circle-check',
    'triangle-alert', 'info', 'circle-x', 'eye', 'eye-off', 'copy', 'external-link', 'git-branch',
    'git-commit-horizontal', 'server', 'globe', 'languages', 'pencil', 'wand-sparkles', 'arrow-left', 'ellipsis',
    'link', 'crown', 'layers', 'folder-plus', 'hard-drive', 'clock', 'list-checks', 'badge-check', 'house',
    'circle-help', 'sparkles', 'plug', 'flask-conical', 'save', 'arrow-right', 'log-in', 'fingerprint', 'boxes',
    'group', 'network', 'circle-slash', 'minus', 'square-check', 'rotate-ccw', 'terminal', 'package', 'tag',
    'briefcase', 'circle-minus', 'circle-plus', 'code', 'corner-left-up', 'folders', 'infinity', 'square-dashed', 'unlink', 'shield-off', 'smartphone', 'shield-alert',
];
$dir = __DIR__ . '/../node_modules/lucide-static/icons';
$out = '<svg xmlns="http://www.w3.org/2000/svg" style="display:none">' . "\n";
$out .= "<!-- Lucide icons (ISC License) https://lucide.dev -->\n";
foreach ($icons as $name) {
    $file = "$dir/$name.svg";
    if (!is_file($file)) {
        fwrite(STDERR, "missing icon: $name\n");
        exit(1);
    }
    $svg = (string) file_get_contents($file);
    $svg = (string) preg_replace('/<!--.*?-->/s', '', $svg);
    if (!preg_match('/<svg[^>]*>(.*)<\/svg>/s', $svg, $m)) {
        fwrite(STDERR, "cannot parse: $name\n");
        exit(1);
    }
    $inner = trim((string) preg_replace('/\s+/', ' ', $m[1]));
    $out .= '<symbol id="' . $name . '" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
        . $inner . "</g></symbol>\n";
}
$out .= "</svg>\n";
file_put_contents(__DIR__ . '/../public/assets/icons.svg', $out);
echo count($icons) . " icons written\n";
