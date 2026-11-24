<?php /** @var SvnManage\View\View $v */ ?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#f6f8fc">
<meta name="htmx-config" content='{"includeIndicatorStyles":false,"allowEval":false,"refreshOnHistoryMiss":true,"historyCacheSize":0}'>
<title><?= e($title) ?> · svnmanage</title>
<link rel="icon" href="<?= e($v->asset('favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e($v->asset('app.css')) ?>">
<script src="<?= e($v->asset('htmx.min.js')) ?>" defer></script>
<script src="<?= e($v->asset('app.js')) ?>" defer></script>
