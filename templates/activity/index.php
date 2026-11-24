<?php /** @var list<array{ts: string, user: string, ip: string, action: string, target: string, context: array}> $entries */
$heading = t('Activity log');
$sub = t('Every change made through svnmanage, newest first.');
include __DIR__ . '/../partials/header.php';
$tone = static fn (string $a): string => match (true) {
    str_contains($a, 'delete') || str_contains($a, 'failed') || str_contains($a, 'revoke') || str_contains($a, 'remove') || str_contains($a, 'unassign') => 'bg-rose-50 text-rose-700',
    str_contains($a, 'create') || str_contains($a, 'grant') || str_contains($a, 'add') || str_contains($a, 'assign') => 'bg-emerald-50 text-emerald-700',
    str_starts_with($a, 'log') => 'bg-slate-100 text-slate-600',
    default => 'bg-brand-50 text-brand-700',
};
?>
<form method="get" action="<?= url('/activity') ?>" class="mb-5" hx-get="<?= url('/activity') ?>" hx-trigger="input changed delay:300ms from:find input, search from:find input" hx-select="#activity-list" hx-target="#activity-list" hx-swap="outerHTML" hx-push-url="true">
  <div class="search max-w-md"><?= icon('search') ?><input type="search" name="q" value="<?= e($q) ?>" class="input" placeholder="<?= t('Filter by user, action or target…') ?>" data-hotkey-search aria-label="<?= t('Search') ?>"></div>
</form>
<section id="activity-list" class="card">
  <?php if ($entries === []): ?>
    <?php $emptyIcon = 'history'; $emptyTitle = $q !== '' ? tr('No matches.') : tr('No activity yet'); $emptyText = null; $emptyAction = null; include __DIR__ . '/../partials/empty.php'; ?>
  <?php else: ?>
    <ul class="list">
      <?php foreach ($entries as $a): $ctx = array_filter($a['context'], static fn ($v) => $v !== null && $v !== ''); ?>
        <li class="flex gap-3 px-4 py-3 sm:px-5">
          <span class="avatar avatar-sm mt-0.5" style="--h: <?= avatar_hue($a['user']) ?>"><?= e(initials($a['user'])) ?></span>
          <div class="min-w-0 flex-1">
            <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
              <span class="font-medium text-slate-900"><?= e($a['user']) ?></span>
              <span class="rounded-md px-1.5 py-0.5 font-mono text-[11px] <?= $tone($a['action']) ?>"><?= e($a['action']) ?></span>
              <span class="mono min-w-0 break-all text-slate-700"><?= e($a['target']) ?></span>
            </p>
            <?php if ($ctx !== []): ?><p class="mt-1 flex flex-wrap gap-1"><?php foreach ($ctx as $k => $val): ?><span class="chip bg-slate-100 py-0 text-[11px] text-slate-500"><?= e($k) ?>: <?= e(is_bool($val) ? ($val ? 'yes' : 'no') : (is_scalar($val) ? (string) $val : json_encode($val))) ?></span><?php endforeach; ?></p><?php endif; ?>
          </div>
          <div class="shrink-0 text-right text-xs text-slate-400">
            <time datetime="<?= e($a['ts']) ?>" title="<?= e($a['ts']) ?>"><?= e(ago(new DateTimeImmutable($a['ts']))) ?></time>
            <p class="mono hidden sm:block"><?= e($a['ip']) ?></p>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
