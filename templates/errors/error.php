<?php /** @var int $status @var string $kind @var string $message */
$ic = match ($kind) {
    'forbidden' => 'lock',
    'not_found' => 'search',
    'inactive' => 'plug',
    'csrf' => 'clock',
    default => 'triangle-alert',
};
$heading = match ($kind) {
    'forbidden' => 'Access denied',
    'not_found' => 'Page not found',
    'inactive' => 'Module not active',
    'csrf' => 'Session expired',
    default => 'Something went wrong',
};
?>
<div class="mx-auto max-w-lg py-10 text-center sm:py-16">
  <span class="mx-auto flex size-16 items-center justify-center rounded-2xl <?= $status >= 500 ? 'bg-rose-50 text-rose-600' : 'bg-brand-50 text-brand-600' ?>"><?= icon($ic, 'size-8') ?></span>
  <p class="mt-6 font-mono text-sm font-semibold text-slate-400"><?= (int) $status ?></p>
  <h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-900"><?= t($heading) ?></h1>
  <p class="mt-2 break-words text-slate-600"><?= t($message) ?></p>
  <div class="mt-8 flex justify-center gap-2">
    <a href="<?= url('/') ?>" class="btn btn-primary"><?= icon('house') ?><?= t('Back to dashboard') ?></a>
  </div>
</div>
