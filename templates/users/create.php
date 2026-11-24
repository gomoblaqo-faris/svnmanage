<?php /** @var int $minLength @var string $old @var list<string> $roles @var list<string> $groups */
$heading = t('New user');
$sub = t('Adds an account to the Subversion user file.');
$crumbs = [tr('Users') => url('/users')];
include __DIR__ . '/../partials/header.php';
?>
<form method="post" action="<?= url('/users') ?>" class="grid gap-6 lg:grid-cols-3" autocomplete="off">
  <?= csrf_field() ?>
  <div class="space-y-6 lg:col-span-2">
    <section class="card">
      <div class="card-header"><h2 class="card-title"><?= icon('user', 'size-4 text-brand-600') ?><?= t('Account') ?></h2></div>
      <div class="card-body grid gap-5 sm:grid-cols-2">
        <div class="sm:col-span-2">
          <label class="label" for="username"><?= t('Username') ?></label>
          <input class="input" id="username" name="username" value="<?= e($old) ?>" required autofocus maxlength="128" autocapitalize="none" spellcheck="false" autocomplete="off">
        </div>
        <div>
          <label class="label" for="pw1"><?= t('Password') ?></label>
          <div class="relative">
            <input class="input pr-11" id="pw1" name="password" type="password" minlength="<?= $minLength ?>" required autocomplete="new-password">
            <button type="button" class="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-slate-400 hover:text-slate-700" data-reveal="pw1" aria-label="<?= t('Show password') ?>"><?= icon('eye') ?></button>
          </div>
        </div>
        <div>
          <label class="label" for="pw2"><?= t('Retype password') ?></label>
          <input class="input" id="pw2" name="password2" type="password" minlength="<?= $minLength ?>" required autocomplete="new-password">
        </div>
        <div class="flex flex-wrap items-center justify-between gap-2 sm:col-span-2">
          <p class="hint mt-0"><?= t('At least %0 characters.', $minLength) ?></p>
          <button type="button" class="btn btn-sm btn-ghost" data-generate="pw1,pw2"><?= icon('wand-sparkles', 'size-3.5') ?><?= t('Generate password') ?></button>
        </div>
      </div>
    </section>
    <?php if ($groups !== []): ?>
      <section class="card"><div class="card-body">
        <?php $pickName = 'groups[]'; $pickType = 'checkbox'; $pickLabel = tr('Groups'); $pickItems = array_map(static fn ($g) => ['value' => $g, 'label' => '@' . $g, 'icon' => 'users'], $groups); include __DIR__ . '/../partials/picker.php'; ?>
      </div></section>
    <?php endif; ?>
  </div>
  <aside class="space-y-6">
    <?php if ($roles !== []): ?>
      <section class="card"><div class="card-body">
        <?php $pickName = 'roles[]'; $pickType = 'checkbox'; $pickLabel = tr('Roles'); $pickItems = array_map(static fn ($r) => ['value' => $r, 'label' => tr($r), 'icon' => 'shield-check'], $roles); include __DIR__ . '/../partials/picker.php'; ?>
      </div></section>
    <?php endif; ?>
    <div class="flex gap-2 lg:flex-col">
      <button class="btn btn-primary flex-1"><?= icon('user-plus') ?><?= t('Create user') ?></button>
      <a href="<?= url('/users') ?>" class="btn btn-secondary flex-1"><?= t('Cancel') ?></a>
    </div>
  </aside>
</form>
