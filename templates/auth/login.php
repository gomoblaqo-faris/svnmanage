<?php /** @var string $next @var string $username @var bool $loggedOut */ ?>
<div class="w-full max-w-[25rem]">
  <div class="card p-6 shadow-lift sm:p-8">
    <div class="mb-7">
      <span class="mb-4 flex size-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-600"><?= icon('fingerprint', 'size-6') ?></span>
      <h1 class="text-2xl font-semibold tracking-tight text-slate-900"><?= t('Welcome back') ?></h1>
      <p class="mt-1 text-sm text-slate-500"><?= t('Sign in with your Subversion account.') ?></p>
    </div>
    <?php if ($loggedOut): ?>
      <div class="alert alert-success mb-5"><?= icon('circle-check', 'mt-0.5 size-4 shrink-0') ?><?= t('You have been logged out.') ?></div>
    <?php endif; ?>
    <form method="post" action="<?= url('/login') ?>" hx-boost="false" class="space-y-4">
      <?= csrf_field() ?>
      <input type="hidden" name="next" value="<?= e($next) ?>">
      <div>
        <label class="label" for="username"><?= t('Username') ?></label>
        <input class="input" id="username" name="username" value="<?= e($username) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required <?= $username === '' ? 'autofocus' : '' ?>>
      </div>
      <div>
        <label class="label" for="password"><?= t('Password') ?></label>
        <div class="relative">
          <input class="input pr-11" id="password" name="password" type="password" autocomplete="current-password" required <?= $username !== '' ? 'autofocus' : '' ?>>
          <button type="button" class="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-slate-400 hover:text-slate-700" data-reveal="password" aria-label="<?= t('Show password') ?>"><?= icon('eye') ?></button>
        </div>
      </div>
      <button class="btn btn-primary w-full"><?= icon('log-in') ?><?= t('Login') ?></button>
    </form>
  </div>
</div>
