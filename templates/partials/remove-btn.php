<?php /** @var string $rmAction @var string $rmField @var string $rmValue @var string $rmLabel */ ?>
<form method="post" action="<?= url($rmAction) ?>" class="relative z-10 shrink-0">
  <?= csrf_field() ?>
  <input type="hidden" name="<?= e($rmField) ?>" value="<?= e($rmValue) ?>">
  <button class="btn-icon text-slate-400 hover:bg-rose-50 hover:text-rose-600" title="<?= e($rmLabel) ?>" aria-label="<?= e($rmLabel) ?>"><?= icon('x') ?></button>
</form>
