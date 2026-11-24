<?php /** @var list<array{file: string, path: string, size: int, time: int}> $backups @var string $folder @var int $keep */
$heading = t('Backups');
$sub = t('A copy is saved before every change to the authz, user, role and config files. The newest %0 per file are kept.', $keep);
include __DIR__ . '/../partials/header.php';
?>
<p class="mono -mt-3 mb-5 truncate text-xs text-slate-400"><?= icon('folder', 'mr-1 inline size-3.5') ?><?= e($folder) ?></p>
<section class="card">
  <?php if ($backups === []): ?>
    <?php $emptyIcon = 'archive'; $emptyTitle = tr('No backups yet'); $emptyText = tr('Backups appear after the first change.'); $emptyAction = null; include __DIR__ . '/../partials/empty.php'; ?>
  <?php else: ?>
    <ul class="list">
      <?php foreach ($backups as $b): $name = preg_replace('/\.\d{8}-\d{6}-[0-9a-f]+\.bak$/', '', $b['file']); ?>
        <li class="row text-sm">
          <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-500"><?= icon('file-text', 'size-4') ?></span>
          <span class="min-w-0 flex-1"><span class="block truncate font-medium text-slate-800"><?= e($name) ?></span><span class="mono block break-all text-xs text-slate-400"><?= e($b['file']) ?></span></span>
          <span class="hidden text-xs text-slate-500 sm:block"><?= e(human_bytes($b['size'])) ?></span>
          <time class="w-24 text-right text-xs text-slate-500" datetime="<?= e(date(DATE_ATOM, $b['time'])) ?>" title="<?= e(date('Y-m-d H:i:s', $b['time'])) ?>"><?= e(ago((new DateTimeImmutable())->setTimestamp($b['time']))) ?></time>
          <a href="<?= url('/backups/' . rawurlencode($b['file'])) ?>" hx-boost="false" class="btn-icon" title="<?= t('Download') ?>" aria-label="<?= t('Download') ?>"><?= icon('download') ?></a>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
