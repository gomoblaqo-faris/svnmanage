<?php /** @var string $name @var bool $isSelf @var int $minLength */
$heading = t('Change password');
$sub = $isSelf ? t('Update the password you use for Subversion and this site.') : t('Set a new password for %0.', $name);
$crumbs = $isSelf ? [] : [tr('Users') => url('/users'), $name => url('/users/' . rawurlencode($name))];
include __DIR__ . '/../partials/header.php';
$action = $isSelf ? '/users/' . rawurlencode($name) . '/password' : '/users/' . rawurlencode($name) . '/password';
?>
<form method="post" action="<?= url($action) ?>" class="card max-w-xl" hx-boost="false">
  <?= csrf_field() ?>
  <input type="text" name="username" value="<?= e($name) ?>" autocomplete="username" class="sr-only" tabindex="-1" aria-hidden="true" readonly>
  <div class="card-body space-y-5">
    <?php if ($isSelf && $v->services->authEnabled()): ?>
      <div>
        <label class="label" for="current"><?= t('Current password') ?></label>
        <input class="input" id="current" name="current" type="password" required autocomplete="current-password" autofocus>
      </div>
    <?php endif; ?>
    <div class="grid gap-5 sm:grid-cols-2">
      <div>
        <label class="label" for="pw1"><?= t('New password') ?></label>
        <div class="relative">
          <input class="input pr-11" id="pw1" name="password" type="password" minlength="<?= $minLength ?>" required autocomplete="new-password" <?= $isSelf ? '' : 'autofocus' ?>>
          <button type="button" class="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-slate-400 hover:text-slate-700" data-reveal="pw1" aria-label="<?= t('Show password') ?>"><?= icon('eye') ?></button>
        </div>
      </div>
      <div>
        <label class="label" for="pw2"><?= t('Retype password') ?></label>
        <input class="input" id="pw2" name="password2" type="password" minlength="<?= $minLength ?>" required autocomplete="new-password">
      </div>
    </div>
    <div class="flex flex-wrap items-center justify-between gap-2">
      <p class="hint mt-0"><?= t('At least %0 characters.', $minLength) ?></p>
      <button type="button" class="btn btn-sm btn-ghost" data-generate="pw1,pw2"><?= icon('wand-sparkles', 'size-3.5') ?><?= t('Generate password') ?></button>
    </div>
  </div>
  <div class="flex justify-end gap-2 border-t border-slate-100 px-5 py-4">
    <a href="<?= url($isSelf ? '/' : '/users/' . rawurlencode($name)) ?>" class="btn btn-secondary"><?= t('Cancel') ?></a>
    <button class="btn btn-primary"><?= icon('key-round') ?><?= t('Change password') ?></button>
  </div>
</form>
