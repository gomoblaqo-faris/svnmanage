<div class="w-full max-w-[25rem]">
  <div class="card p-6 shadow-lift sm:p-8">
    <div class="mb-7">
      <span class="mb-4 flex size-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-600"><?= icon('shield-check', 'size-6') ?></span>
      <h1 class="text-2xl font-semibold tracking-tight text-slate-900"><?= t('Two-factor authentication') ?></h1>
      <p class="mt-1 text-sm text-slate-500"><?= t('Enter the 6-digit code from your authenticator app.') ?></p>
    </div>
    <form method="post" action="<?= url('/login/verify') ?>" hx-boost="false" class="space-y-4">
      <?= csrf_field() ?>
      <div>
        <label class="label" for="code"><?= t('Code') ?></label>
        <input class="input input-mono text-center text-lg tracking-[0.3em]" id="code" name="code" required autofocus
               autocomplete="one-time-code" inputmode="numeric" maxlength="64" autocapitalize="none" spellcheck="false" placeholder="123456">
        <p class="hint"><?= t('Lost your phone? Enter one of your recovery codes instead.') ?></p>
      </div>
      <button class="btn btn-primary w-full"><?= icon('log-in') ?><?= t('Verify') ?></button>
    </form>
    <form method="post" action="<?= url('/logout') ?>" hx-boost="false" class="mt-3 text-center">
      <?= csrf_field() ?>
      <button class="text-sm font-medium text-slate-500 hover:text-slate-800"><?= t('Cancel') ?></button>
    </form>
  </div>
</div>
