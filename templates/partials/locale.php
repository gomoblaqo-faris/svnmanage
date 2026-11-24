<?php /** @var SvnManage\View\View $v */ use SvnManage\I18n\Translator; ?>
<form method="post" action="<?= url('/locale') ?>" hx-boost="false" class="mt-1 flex items-center gap-2 px-3 py-1.5 text-[13px] text-slate-500">
  <?= csrf_field() ?>
  <input type="hidden" name="_back" value="<?= e($v->request->path) ?>">
  <?= icon('languages', 'size-4 shrink-0') ?>
  <label class="sr-only" for="locale-select"><?= t('Language') ?></label>
  <select id="locale-select" name="locale" data-autosubmit class="min-w-0 flex-1 cursor-pointer rounded-lg border-0 bg-transparent py-1 pr-6 text-[13px] text-slate-600 hover:text-slate-900 focus:ring-2 focus:ring-brand-500">
    <?php foreach (Translator::LOCALES as $code => $name): ?>
      <option value="<?= e($code) ?>"<?= $v->tr->locale === $code ? ' selected' : '' ?>><?= e($name) ?></option>
    <?php endforeach; ?>
  </select>
  <noscript><button class="btn btn-sm btn-secondary"><?= t('Apply') ?></button></noscript>
</form>
