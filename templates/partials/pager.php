<?php /** @var int $page @var int $pages @var string $pagerPath @var array $pagerQuery */
if ($pages <= 1) { return; }
$link = static fn (int $p): string => url($pagerPath, array_filter([...$pagerQuery, 'page' => $p > 1 ? $p : null], static fn ($x) => $x !== null && $x !== ''));
?>
<nav class="flex items-center justify-between gap-3 border-t border-slate-100 px-4 py-3 text-sm sm:px-5" aria-label="<?= t('Pagination') ?>">
  <a href="<?= $link(max(1, $page - 1)) ?>" class="btn btn-sm btn-secondary <?= $page <= 1 ? 'pointer-events-none opacity-40' : '' ?>"><?= icon('chevron-left', 'size-3.5') ?><?= t('Previous') ?></a>
  <span class="text-slate-500"><?= t('Page %0 of %1', $page, $pages) ?></span>
  <a href="<?= $link(min($pages, $page + 1)) ?>" class="btn btn-sm btn-secondary <?= $page >= $pages ? 'pointer-events-none opacity-40' : '' ?>"><?= t('Next') ?><?= icon('chevron-right', 'size-3.5') ?></a>
</nav>
