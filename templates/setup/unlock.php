<?php /** Shown to anonymous visitors: no server paths here. */ ?>
<div class="w-full max-w-md">
  <div class="card p-6 shadow-lift sm:p-8">
    <span class="mb-4 flex size-12 items-center justify-center rounded-2xl bg-coral-50 text-coral-600"><?= icon('key-round', 'size-6') ?></span>
    <h1 class="text-2xl font-semibold tracking-tight text-slate-900"><?= t('Welcome to svnmanage') ?></h1>
    <p class="mt-1.5 text-sm text-slate-600"><?= t('To prove you administer this server, enter the setup key. Print it on the server with:') ?></p>
    <div class="mt-3 flex items-center gap-1 rounded-xl bg-slate-50 p-2 ring-1 ring-slate-200">
      <code class="mono min-w-0 flex-1 break-all px-1 text-slate-700">bin/svnmanage setup-key</code>
      <button type="button" class="btn-icon shrink-0" data-copy="bin/svnmanage setup-key" aria-label="<?= t('Copy') ?>"><?= icon('copy') ?></button>
    </div>
    <p class="hint"><?= t('It is also stored in the file setup.key in the data folder.') ?></p>
    <form method="post" action="<?= url('/setup/unlock') ?>" hx-boost="false" class="mt-6 space-y-4">
      <?= csrf_field() ?>
      <div>
        <label class="label" for="setup-key"><?= t('Setup key') ?></label>
        <input class="input input-mono" id="setup-key" name="key" required autofocus autocomplete="off" autocapitalize="none" spellcheck="false">
      </div>
      <button class="btn btn-primary w-full"><?= icon('lock-open') ?><?= t('Continue') ?></button>
    </form>
  </div>
</div>
