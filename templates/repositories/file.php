<?php
/** @var SvnManage\Svn\Repository $repo @var string $path @var string $content @var bool $binary @var bool $truncated */
$base = '/repositories/' . $repo->parentId . '/' . rawurlencode($repo->name);
$dir = str_contains($path, '/') ? substr($path, 0, (int) strrpos($path, '/')) : '';
$heading = '<span class="font-mono">' . e(basename($path)) . '</span>';
$crumbs = [tr('Repositories') => url('/repositories'), $repo->name => url($base), $repo->name . ':/' . $dir => url($base . '/browse', ['path' => $dir])];
$actions = '<a href="' . url($base . '/file', ['path' => $path, 'raw' => '1']) . '" hx-boost="false" class="btn btn-secondary">' . icon('download') . t('Download') . '</a>';
include __DIR__ . '/../partials/header.php';
$lines = $binary ? [] : explode("\n", rtrim($content, "\n"));
?>
<section class="card overflow-hidden">
  <?php if ($binary): ?>
    <?php $emptyIcon = 'file'; $emptyTitle = tr('Binary file'); $emptyText = tr('This file cannot be previewed. Download it instead.'); $emptyAction = null; include __DIR__ . '/../partials/empty.php'; ?>
  <?php else: ?>
    <?php if ($truncated): ?><div class="alert alert-info m-3"><?= icon('info', 'mt-0.5 size-4 shrink-0') ?><?= t('Only the first 256 KB are shown.') ?></div><?php endif; ?>
    <div class="overflow-x-auto">
      <table class="w-full font-mono text-[12.5px] leading-5">
        <tbody>
          <?php foreach ($lines as $n => $line): ?>
            <tr class="hover:bg-amber-50/60"><td class="w-12 select-none border-r border-slate-100 bg-slate-50/70 px-3 text-right align-top text-slate-400"><?= $n + 1 ?></td><td class="whitespace-pre px-4 text-slate-800"><?= e($line) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
