<?php
/**
 * Searchable checkbox list (mobile friendly alternative to <select multiple>).
 *
 * @var string $pickName      input name (array)
 * @var list<array{value: string, label: string, hint?: string, icon?: string}> $pickItems
 * @var string $pickLabel
 * @var string|null $pickType "checkbox" (default) or "radio"
 */
$pickType ??= 'checkbox';
$pickChecked = array_flip($pickChecked ?? []);
$pid = 'p' . substr(md5($pickName . $pickLabel), 0, 6);
?>
<fieldset data-filter class="min-w-0">
  <legend class="label"><?= e($pickLabel) ?></legend>
  <?php if (count($pickItems) > 6): ?>
    <div class="search mb-2">
      <?= icon('search') ?>
      <input type="search" class="input" data-filter-input placeholder="<?= t('Filter…') ?>" aria-label="<?= t('Filter') ?> <?= e($pickLabel) ?>">
    </div>
  <?php endif; ?>
  <div class="max-h-64 overflow-y-auto rounded-xl ring-1 ring-slate-200">
    <?php if ($pickItems === []): ?>
      <p class="px-3.5 py-3 text-sm text-slate-500"><?= t('Nothing to choose from.') ?></p>
    <?php endif; ?>
    <?php foreach ($pickItems as $i => $item): ?>
      <label data-filter-item data-filter-text="<?= e($item['label'] . ' ' . ($item['hint'] ?? '')) ?>"
             class="flex cursor-pointer items-center gap-3 border-b border-slate-100 px-3.5 py-2.5 last:border-0 hover:bg-slate-50 has-[:checked]:bg-brand-50/60">
        <input type="<?= $pickType ?>" name="<?= e($pickName) ?>" value="<?= e($item['value']) ?>" class="checkbox" id="<?= $pid . $i ?>"<?= isset($pickChecked[$item['value']]) ? ' checked' : '' ?>>
        <?php if (!empty($item['icon'])): ?><span class="text-slate-400"><?= icon($item['icon']) ?></span><?php endif; ?>
        <span class="min-w-0 flex-1 truncate text-sm text-slate-800 <?= str_contains($item['label'], ':/') || str_starts_with($item['label'], '/') ? 'mono' : '' ?>"><?= e($item['label']) ?></span>
        <?php if (!empty($item['hint'])): ?><span class="truncate text-xs text-slate-400"><?= e($item['hint']) ?></span><?php endif; ?>
      </label>
    <?php endforeach; ?>
    <p data-filter-empty hidden class="px-3.5 py-3 text-sm text-slate-500"><?= t('No matches.') ?></p>
  </div>
</fieldset>
<?php unset($pickChecked); ?>
