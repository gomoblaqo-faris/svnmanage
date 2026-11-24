<?php
/** @var int $step @var string $dataDir */
$steps = [1 => 'Connect Subversion', 2 => 'Secure the site', 3 => 'Choose an administrator'];
?>
<div class="w-full max-w-4xl">
  <div class="mb-6 text-center sm:mb-8">
    <p class="eyebrow mb-2"><?= t('Welcome to svnmanage') ?></p>
    <h1 class="text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl"><?= t('Let’s get your Subversion server set up') ?></h1>
    <ol class="mx-auto mt-6 flex max-w-3xl flex-wrap items-center justify-center gap-x-3 gap-y-2 text-sm sm:flex-nowrap">
      <?php foreach ($steps as $n => $label): $done = $n < $step; $cur = $n === $step; ?>
        <li class="flex items-center gap-2 <?= $cur ? 'font-semibold text-slate-900' : 'text-slate-500' ?>">
          <span class="flex size-7 items-center justify-center rounded-full text-xs font-semibold <?= $done ? 'bg-emerald-500 text-white' : ($cur ? 'bg-brand-600 text-white shadow-md shadow-brand-600/30' : 'bg-white text-slate-500 ring-1 ring-slate-200') ?>"><?= $done ? icon('check', 'size-3.5') : $n ?></span>
          <?= t($label) ?>
        </li>
        <?php if ($n < count($steps)): ?><li class="hidden h-px w-6 bg-slate-300 sm:block" aria-hidden="true"></li><?php endif; ?>
      <?php endforeach; ?>
    </ol>
  </div>

  <?php if ($step === 1): ?>
    <div class="card mb-6 overflow-hidden">
      <div class="flex flex-col gap-4 bg-gradient-to-br from-brand-50 via-white to-coral-50 p-5 sm:flex-row sm:items-center sm:p-6">
        <span class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-white text-brand-600 shadow-soft"><?= icon('wand-sparkles', 'size-6') ?></span>
        <div class="min-w-0 flex-1">
          <h2 class="font-semibold text-slate-900"><?= t('Starting from scratch?') ?></h2>
          <p class="mt-0.5 text-sm text-slate-600"><?= t('Create an authz file, a user file and a repository folder inside %0 and use them.', $dataDir) ?></p>
        </div>
        <form method="post" action="<?= url('/setup') ?>">
          <?= csrf_field() ?><input type="hidden" name="action" value="defaults">
          <button class="btn btn-primary w-full sm:w-auto"><?= icon('sparkles') ?><?= t('Use local defaults') ?></button>
        </form>
      </div>
    </div>
    <p class="mb-4 text-center text-sm text-slate-500"><?= t('…or point svnmanage at your existing files. Use “Test” to check each path.') ?></p>
    <?php include __DIR__ . '/../settings/form.php'; ?>
  <?php elseif ($step === 2): ?>
    <?php include __DIR__ . '/security.php'; ?>
  <?php else: ?>
    <div class="mx-auto max-w-xl space-y-5">
      <?php if ($userError !== null): ?>
        <div class="alert alert-error"><?= icon('circle-x', 'mt-0.5 size-4 shrink-0') ?><span><?= t($userError) ?></span></div>
      <?php endif; ?>

      <?php if ($users !== []): ?>
        <form method="post" action="<?= url('/setup/admin') ?>" class="card" hx-boost="false">
          <?= csrf_field() ?><input type="hidden" name="mode" value="existing">
          <div class="card-header"><h2 class="card-title"><?= icon('crown', 'size-4 text-coral-500') ?><?= t('Choose an existing user') ?></h2></div>
          <div class="card-body space-y-4">
            <?php
            $pickName = 'username';
            $pickType = 'radio';
            $pickLabel = tr('Users');
            $pickItems = array_map(static fn ($u) => ['value' => $u->name, 'label' => $u->name, 'hint' => (string) $u->displayName, 'icon' => 'user'], $users);
            include __DIR__ . '/../partials/picker.php';
            ?>
            <button class="btn btn-primary w-full"><?= icon('shield-check') ?><?= t('Make administrator') ?></button>
          </div>
        </form>
      <?php endif; ?>

      <?php if ($canCreate): ?>
        <form method="post" action="<?= url('/setup/admin') ?>" class="card" hx-boost="false">
          <?= csrf_field() ?><input type="hidden" name="mode" value="create">
          <div class="card-header"><h2 class="card-title"><?= icon('user-plus', 'size-4 text-brand-600') ?><?= $users === [] ? t('Create the administrator account') : t('…or create a new administrator') ?></h2></div>
          <div class="card-body grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
              <label class="label" for="su-name"><?= t('Username') ?></label>
              <input class="input" id="su-name" name="username" value="admin" required autocapitalize="none" spellcheck="false" autocomplete="username">
            </div>
            <div>
              <label class="label" for="su-pw"><?= t('Password') ?></label>
              <input class="input" id="su-pw" name="password" type="password" minlength="<?= (int) $minLength ?>" required autocomplete="new-password">
            </div>
            <div>
              <label class="label" for="su-pw2"><?= t('Retype password') ?></label>
              <input class="input" id="su-pw2" name="password2" type="password" minlength="<?= (int) $minLength ?>" required autocomplete="new-password">
            </div>
            <button class="btn btn-primary sm:col-span-2"><?= icon('crown') ?><?= t('Create administrator') ?></button>
          </div>
        </form>
      <?php endif; ?>

      <?php if ($users === [] && !$canCreate): ?>
        <div class="alert alert-warning"><?= icon('triangle-alert', 'mt-0.5 size-4 shrink-0') ?><span><?= t('No users found and no user edit provider is configured. Check the configuration.') ?></span></div>
      <?php endif; ?>

      <div class="flex flex-wrap justify-center gap-2">
        <form method="post" action="<?= url('/setup') ?>">
          <?= csrf_field() ?><input type="hidden" name="action" value="reconfigure">
          <button class="btn btn-ghost btn-sm"><?= icon('arrow-left', 'size-3.5') ?><?= t('Back to configuration') ?></button>
        </form>
        <form method="post" action="<?= url('/setup') ?>">
          <?= csrf_field() ?><input type="hidden" name="action" value="resecure">
          <button class="btn btn-ghost btn-sm"><?= icon('shield-check', 'size-3.5') ?><?= t('Back to security settings') ?></button>
        </form>
      </div>
    </div>
  <?php endif; ?>
</div>
