<?php /** @var SvnManage\View\View $v */
$styles = [
    'success' => ['circle-check', 'text-emerald-600'],
    'error' => ['circle-x', 'text-rose-600'],
    'warning' => ['triangle-alert', 'text-amber-500'],
    'info' => ['info', 'text-brand-600'],
];
foreach ($v->flashMessages() as [$level, $message]):
    [$ic, $color] = $styles[$level] ?? $styles['info']; ?>
  <div class="toast" data-toast="<?= e($level) ?>" role="<?= $level === 'error' ? 'alert' : 'status' ?>">
    <span class="mt-0.5 <?= $color ?>"><?= icon($ic, 'size-5') ?></span>
    <p class="min-w-0 flex-1 break-words text-slate-700"><?= e($v->tr->t($message)) ?></p>
    <button type="button" class="-m-1 rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700" data-toast-close aria-label="<?= t('Close') ?>"><?= icon('x') ?></button>
  </div>
<?php endforeach; ?>
