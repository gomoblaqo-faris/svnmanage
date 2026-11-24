<?php /** @var ?SvnManage\Directory\SyncPlan $plan @var ?string $error */ ?>
<?php if ($error !== null): ?>
  <div class="alert alert-error"><?= icon('circle-x', 'mt-0.5 size-4 shrink-0') ?><span class="break-words"><?= t($error) ?></span></div>
<?php elseif ($plan !== null): ?>
  <section class="card animate-fade-in">
    <div class="card-header">
      <h2 class="card-title"><?= icon('list-checks', 'size-4 text-brand-600') ?><?= t('Preview') ?></h2>
      <span class="badge <?= $plan->changed ? 'badge-coral' : 'badge-rw' ?>"><?= $plan->changed ? t('Changes pending') : t('Up to date') ?></span>
    </div>
    <div class="grid grid-cols-2 gap-px bg-slate-100 sm:grid-cols-4">
      <?php foreach ([['Groups', $plan->groupCount], ['Memberships', $plan->memberCount], ['New groups', count($plan->addedGroups)], ['Removed', count($plan->removedGroups) + count($plan->removedUsers)]] as [$label, $n]): ?>
        <div class="bg-white p-4 text-center"><p class="text-xl font-semibold tabular-nums text-slate-900"><?= (int) $n ?></p><p class="text-xs text-slate-500"><?= t($label) ?></p></div>
      <?php endforeach; ?>
    </div>
    <?php if ($plan->messages !== []): ?>
      <ul class="list max-h-96 overflow-y-auto border-t border-slate-100">
        <?php foreach ($plan->messages as [$level, $text]): ?>
          <li class="row text-sm"><span class="<?= ['success' => 'text-emerald-600', 'info' => 'text-sky-600', 'warning' => 'text-amber-600'][$level] ?? 'text-slate-400' ?>"><?= icon(['success' => 'circle-plus', 'info' => 'circle-minus', 'warning' => 'triangle-alert'][$level] ?? 'info') ?></span><span class="min-w-0 flex-1 break-words"><?= t($text) ?></span></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
<?php endif; ?>
