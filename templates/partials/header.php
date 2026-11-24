<?php
/**
 * @var string $heading
 * @var string|null $sub
 * @var array<string, string>|null $crumbs label => url
 * @var string|null $actions raw HTML
 * @var string|null $eyebrow
 */
$crumbs ??= [];
?>
<div class="mb-6 flex flex-col gap-4 sm:mb-8 sm:flex-row sm:items-end sm:justify-between">
  <div class="min-w-0">
    <?php if ($crumbs !== []): ?>
      <nav class="mb-2 flex flex-wrap items-center gap-1 text-[13px] text-slate-500" aria-label="<?= t('Breadcrumb') ?>">
        <?php foreach ($crumbs as $label => $href): ?>
          <a href="<?= e($href) ?>" class="-my-2 inline-flex min-h-10 items-center rounded px-0.5 hover:text-brand-700 lg:my-0 lg:min-h-0"><?= e($label) ?></a>
          <?= icon('chevron-right', 'size-3.5 text-slate-300') ?>
        <?php endforeach; ?>
      </nav>
    <?php elseif (!empty($eyebrow)): ?>
      <p class="eyebrow mb-1.5"><?= e($eyebrow) ?></p>
    <?php endif; ?>
    <h1 class="page-title break-words"><?= $heading ?></h1>
    <?php if (!empty($sub)): ?><p class="mt-1.5 max-w-2xl text-[15px] text-slate-500"><?= $sub ?></p><?php endif; ?>
  </div>
  <?php if (!empty($actions)): ?>
    <div class="flex shrink-0 flex-wrap gap-2"><?= $actions ?></div>
  <?php endif; ?>
</div>
