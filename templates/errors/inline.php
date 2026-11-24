<?php /** @var string $message */ ?>
<div class="toast" data-toast="error" role="alert">
  <span class="mt-0.5 text-rose-600"><?= icon('circle-x', 'size-5') ?></span>
  <p class="min-w-0 flex-1 break-words text-slate-700"><?= t($message) ?></p>
  <button type="button" class="-m-1 rounded-lg p-1 text-slate-400 hover:bg-slate-100" data-toast-close aria-label="<?= t('Close') ?>"><?= icon('x') ?></button>
</div>
