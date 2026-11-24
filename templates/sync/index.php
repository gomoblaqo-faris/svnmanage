<?php /** @var ?SvnManage\Directory\SyncPlan $plan */
$heading = t('Synchronize');
$sub = t('Copies users and groups from %0 into the Subversion authorization file.', $kind);
include __DIR__ . '/../partials/header.php';
?>
<div class="grid gap-6 lg:grid-cols-3 lg:items-start">
  <div class="space-y-6 lg:col-span-2">
    <section class="card">
      <div class="card-body flex flex-col gap-4 sm:flex-row sm:items-center">
        <span class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-brand-50 text-brand-600"><?= icon('refresh-cw', 'size-6') ?></span>
        <div class="min-w-0 flex-1">
          <h2 class="font-semibold text-slate-900"><?= t('Preview first, then apply') ?></h2>
          <p class="text-sm text-slate-500"><?= t('The preview shows every change without writing anything.') ?></p>
        </div>
        <div class="flex gap-2">
          <a href="<?= url('/sync', ['preview' => '1', 'fresh' => '1']) ?>" class="btn btn-secondary" hx-get="<?= url('/sync', ['preview' => '1', 'fresh' => '1']) ?>" hx-target="#sync-preview" hx-swap="innerHTML" hx-push-url="false" hx-indicator="#sync-spin"><span id="sync-spin" class="htmx-indicator"><?= icon('refresh-cw', 'size-4 animate-spin') ?></span><?= icon('eye') ?><?= t('Preview') ?></a>
          <form method="post" action="<?= url('/sync') ?>" data-confirm="<?= t('Apply the synchronisation to the authorization file now?') ?>" data-confirm-tone="primary" data-confirm-label="<?= t('Synchronize') ?>">
            <?= csrf_field() ?><button class="btn btn-primary"><?= icon('refresh-cw') ?><?= t('Synchronize now') ?></button>
          </form>
        </div>
      </div>
    </section>
    <div id="sync-preview" aria-live="polite">
      <?php if ($plan !== null || $error !== null) { include __DIR__ . '/preview.php'; } ?>
    </div>
  </div>
  <aside class="card h-fit">
    <dl class="card-body space-y-3 text-sm">
      <div class="flex justify-between gap-3"><dt class="text-slate-500"><?= t('Directory') ?></dt><dd class="font-medium"><?= e($kind) ?></dd></div>
      <div class="flex justify-between gap-3"><dt class="text-slate-500"><?= t('Groups') ?></dt><dd class="font-medium"><?= in_array($groupSource, ['ldap', 'ad'], true) ? t('synchronised') : t('kept (authz file)') ?></dd></div>
      <div class="flex justify-between gap-3"><dt class="text-slate-500"><?= t('Remove deleted users') ?></dt><dd><?= $autoUsers ? t('Yes') : t('No') ?></dd></div>
      <div class="flex justify-between gap-3"><dt class="text-slate-500"><?= t('Remove deleted groups') ?></dt><dd><?= $autoGroups ? t('Yes') : t('No') ?></dd></div>
      <?php if ($cachedAt !== null): ?><div class="flex justify-between gap-3"><dt class="text-slate-500"><?= t('Cache updated') ?></dt><dd><?= e(ago(new DateTimeImmutable($cachedAt))) ?></dd></div><?php endif; ?>
      <p class="border-t border-slate-100 pt-3 text-xs text-slate-500"><?= t('Tip: schedule %0 with cron to keep the authz file current.', '<code class="mono">bin/svnmanage update</code>') ?></p>
    </dl>
  </aside>
</div>
