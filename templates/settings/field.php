<?php
/** @var array<string, mixed> $field @var SvnManage\Config\Config $config */
use SvnManage\Config\SettingsSchema;

$name = SettingsSchema::name($field);
$id = 'f-' . preg_replace('/[^A-Za-z0-9]/', '-', $name);
$value = $config->raw($field['section'], $field['key']);
$hint = $field['hint'] ?? null;
$test = $field['test'] ?? null;
?>
<?php if ($field['type'] === 'bool'): ?>
  <label class="flex cursor-pointer items-start gap-3 rounded-xl p-3 ring-1 ring-slate-200 hover:bg-slate-50 has-[:checked]:bg-brand-50/50 has-[:checked]:ring-brand-300 sm:col-span-2">
    <input type="hidden" name="<?= e($name) ?>" value="0">
    <input type="checkbox" class="checkbox mt-0.5" name="<?= e($name) ?>" value="1" <?= $config->bool($field['section'], $field['key']) ? 'checked' : '' ?>>
    <span><span class="block text-sm font-medium text-slate-800"><?= t($field['label']) ?></span>
      <?php if ($hint): ?><span class="block text-xs text-slate-500"><?= t($hint) ?></span><?php endif; ?></span>
  </label>
<?php else: ?>
  <div class="min-w-0">
    <label class="label" for="<?= e($id) ?>"><?= t($field['label']) ?> <span class="mono hidden text-[11px] font-normal text-slate-400 lg:inline" title="<?= t('Configuration file key') ?>"><?= e($field['key']) ?></span></label>
    <div class="flex gap-2">
      <?php if ($field['type'] === 'select'): ?>
        <select class="input" id="<?= e($id) ?>" name="<?= e($name) ?>">
          <?php foreach ($field['options'] as $optValue => $optLabel): ?>
            <option value="<?= e((string) $optValue) ?>"<?= (string) $optValue === $value ? ' selected' : '' ?>><?= e($optLabel) ?></option>
          <?php endforeach; ?>
        </select>
      <?php elseif ($field['type'] === 'password'): ?>
        <input class="input" type="password" id="<?= e($id) ?>" name="<?= e($name) ?>" autocomplete="new-password" placeholder="<?= $value !== '' ? '•••••••• ' . t('(stored)') : '' ?>">
      <?php else: ?>
        <input class="input <?= in_array($field['type'], ['path', 'networks'], true) || str_contains($field['key'], 'DN') || str_contains($field['key'], 'Filter') ? 'input-mono' : '' ?>"
               type="<?= $field['type'] === 'number' ? 'number' : 'text' ?>" <?= $field['type'] === 'number' ? 'min="0" inputmode="numeric"' : '' ?>
               id="<?= e($id) ?>" name="<?= e($name) ?>" value="<?= e($value) ?>" placeholder="<?= e($field['placeholder'] ?? '') ?>" spellcheck="false" autocapitalize="none">
      <?php endif; ?>
      <?php if ($test): ?>
        <button type="button" class="btn btn-secondary shrink-0 px-3" title="<?= t('Test') ?>"
                hx-post="<?= url('/settings/test') ?>" hx-include="closest form" hx-vals='<?= e(json_encode(['test' => $test])) ?>'
                hx-target="#test-<?= e($test) ?>" hx-swap="innerHTML" hx-indicator="#test-<?= e($test) ?>-spin">
          <?= icon('flask-conical') ?><span class="hidden sm:inline"><?= t('Test') ?></span>
          <span id="test-<?= e($test) ?>-spin" class="htmx-indicator"><?= icon('refresh-cw', 'size-3.5 animate-spin') ?></span>
        </button>
      <?php endif; ?>
    </div>
    <?php if ($hint): ?><p class="hint"><?= t($hint) ?></p><?php endif; ?>
    <?php if ($test): ?><div id="test-<?= e($test) ?>" aria-live="polite"></div><?php endif; ?>
  </div>
<?php endif; ?>
