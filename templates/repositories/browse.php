<?php
/** @var SvnManage\Svn\Repository $repo @var string $path @var list<SvnManage\Svn\BrowseEntry> $entries @var array<string,int> $accessPaths */
$base = '/repositories/' . $repo->parentId . '/' . rawurlencode($repo->name);
$link = static fn (string $tpl, string $rel): string => str_replace(['%1', '%2'], [rawurlencode($repo->name), implode('/', array_map('rawurlencode', explode('/', $rel)))], $tpl);
$parts = $path === '' ? [] : explode('/', $path);
$currentAp = $repo->name . ':/' . $path;
$heading = e($repo->name);
$crumbs = [tr('Repositories') => url('/repositories'), $repo->name => url($base)];
$actions = '';
if ($apacheLink !== '') { $actions .= '<a href="' . e($link($apacheLink, $path)) . '" target="_blank" rel="noopener" hx-boost="false" class="btn btn-secondary">' . icon('external-link') . t('Open in Apache') . '</a>'; }
if ($customLink !== '') { $actions .= '<a href="' . e($link($customLink, $path)) . '" target="_blank" rel="noopener" hx-boost="false" class="btn btn-secondary">' . icon('external-link') . t('Repository browser') . '</a>'; }
include __DIR__ . '/../partials/header.php';
?>
<section class="card">
  <div class="card-header flex-wrap">
    <nav class="flex min-w-0 flex-wrap items-center gap-1 font-mono text-[13px]" aria-label="<?= t('Path') ?>">
      <a href="<?= url($base . '/browse') ?>" class="tap-y inline-block rounded px-1 font-semibold text-brand-700 hover:bg-brand-50"><?= e($repo->name) ?>:/</a>
      <?php $acc = ''; foreach ($parts as $i => $part): $acc .= ($acc === '' ? '' : '/') . $part; ?>
        <?php if ($i < count($parts) - 1): ?>
          <a href="<?= url($base . '/browse', ['path' => $acc]) ?>" class="tap-y inline-block rounded px-1 text-slate-600 hover:bg-slate-100"><?= e($part) ?></a><span class="text-slate-300">/</span>
        <?php else: ?>
          <span class="px-1 text-slate-900"><?= e($part) ?></span>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
    <div class="flex items-center gap-2">
      <?php if (isset($accessPaths[$currentAp]) || ($path === '' && isset($accessPaths[$repo->name . ':/']))): ?>
        <a href="<?= url('/access-paths/view', ['path' => $currentAp]) ?>" class="badge badge-brand tap-area"><?= icon('key-round', 'size-3') ?><?= t('Access path') ?></a>
      <?php elseif ($canAddPath): ?>
        <a href="<?= url('/access-paths/new', ['path' => $currentAp]) ?>" class="btn btn-sm btn-ghost"><?= icon('plus', 'size-3.5') ?><?= t('Create access path') ?></a>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($error !== null): ?>
    <div class="alert alert-error m-4"><?= icon('circle-x', 'mt-0.5 size-4 shrink-0') ?><span class="break-words"><?= t($error) ?></span></div>
  <?php elseif ($entries === []): ?>
    <?php $emptyIcon = 'folder-open'; $emptyTitle = tr('This folder is empty'); $emptyText = null; $emptyAction = null; include __DIR__ . '/../partials/empty.php'; ?>
  <?php else: ?>
    <ul class="list">
      <?php if ($path !== ''): ?>
        <li><a href="<?= url($base . '/browse', ['path' => implode('/', array_slice($parts, 0, -1))]) ?>" class="row row-link text-sm text-slate-500"><?= icon('corner-left-up', 'size-4') ?>..</a></li>
      <?php endif; ?>
      <?php foreach ($entries as $entry): $rel = ($path === '' ? '' : $path . '/') . $entry->name; $ap = $repo->name . ':/' . $rel; ?>
        <li class="row row-link relative text-sm">
          <span class="<?= $entry->isDir ? 'text-amber-500' : 'text-slate-400' ?>"><?= icon($entry->isDir ? 'folder' : 'file', 'size-[18px]') ?></span>
          <?php if ($entry->isDir || $canRead): ?>
          <a class="min-w-0 flex-1 truncate font-medium text-slate-800 before:absolute before:inset-0 hover:text-brand-700 <?= $entry->isDir ? '' : 'font-mono font-normal' ?>"
             href="<?= $entry->isDir ? url($base . '/browse', ['path' => $rel]) : url($base . '/file', ['path' => $rel]) ?>"><?= e($entry->name) ?><?= $entry->isDir ? '/' : '' ?></a>
          <?php else: ?>
          <span class="min-w-0 flex-1 truncate font-mono text-slate-700"><?= e($entry->name) ?></span>
          <?php endif; ?>
          <?php if (isset($accessPaths[$ap])): ?><a href="<?= url('/access-paths/view', ['path' => $ap]) ?>" class="badge badge-brand tap-area z-10" title="<?= t('Access path') ?>"><?= icon('key-round', 'size-3') ?></a><?php endif; ?>
          <span class="hidden w-20 text-right text-xs text-slate-400 sm:block"><?= $entry->isDir ? '' : e(human_bytes($entry->size)) ?></span>
          <span class="hidden w-24 truncate text-xs text-slate-500 md:block"><?= e($entry->author) ?></span>
          <span class="hidden w-14 text-right font-mono text-xs text-slate-400 sm:block">r<?= (int) $entry->revision ?></span>
          <time class="w-20 shrink-0 text-right text-xs text-slate-400" datetime="<?= e($entry->date?->format(DATE_ATOM) ?? '') ?>"><?= e(ago($entry->date)) ?></time>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
