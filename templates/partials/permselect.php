<?php /** @var string|null $permName @var string|null $permDefault */
$permName ??= 'permission';
$permDefault ??= 'r';
$opts = [
    'rw' => ['Read-Write', 'Full access: checkout and commit.', 'pencil', 'text-emerald-600'],
    'r' => ['Read-only', 'Checkout and browse, no commits.', 'eye', 'text-sky-600'],
    'none' => ['No access', 'Explicitly deny (overrides inherited access).', 'circle-slash', 'text-rose-600'],
];
?>
<fieldset>
  <legend class="label"><?= t('Permission') ?></legend>
  <div class="grid gap-2 sm:grid-cols-3">
    <?php foreach ($opts as $value => [$label, $desc, $ic, $color]): ?>
      <label class="radio-card">
        <input type="radio" name="<?= e($permName) ?>" value="<?= $value ?>" class="sr-only"<?= $permDefault === $value ? ' checked' : '' ?>>
        <span class="<?= $color ?> mt-0.5"><?= icon($ic) ?></span>
        <span><span class="block text-sm font-semibold text-slate-900"><?= t($label) ?></span><span class="block text-xs text-slate-500"><?= t($desc) ?></span></span>
      </label>
    <?php endforeach; ?>
  </div>
</fieldset>
