<?php
/**
 * @var bool $enabled @var bool $required @var bool $policyOff @var list<string> $codes
 * @var int $recoveryLeft @var ?int $enabledAt @var ?string $secret @var ?string $uri @var ?string $qr
 */
$heading = t('Two-factor authentication');
$sub = t('A code from an authenticator app on your phone is needed in addition to your password.');
include __DIR__ . '/../partials/header.php';
?>
<div class="grid max-w-4xl gap-6">
<?php if ($codes !== []): ?>
  <section class="card border-amber-200">
    <div class="card-header"><h2 class="card-title"><?= icon('key-round', 'size-4 text-amber-600') ?><?= t('Your recovery codes') ?></h2></div>
    <div class="card-body space-y-4">
      <p class="text-sm text-slate-600"><?= t('Each code works once, if you lose your phone. They are shown only now: save or print them.') ?></p>
      <ol class="grid grid-cols-2 gap-2 sm:grid-cols-5">
        <?php foreach ($codes as $code): ?><li class="mono rounded-lg bg-slate-50 px-2 py-1.5 text-center text-sm text-slate-800 ring-1 ring-slate-200"><?= e($code) ?></li><?php endforeach; ?>
      </ol>
      <button type="button" class="btn btn-secondary" data-copy="<?= e(implode("\n", $codes)) ?>"><?= icon('copy') ?><?= t('Copy all') ?></button>
    </div>
  </section>
<?php endif; ?>

<?php if ($enabled): ?>
  <section class="card">
    <div class="card-body flex flex-col gap-4 sm:flex-row sm:items-center">
      <span class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600"><?= icon('shield-check', 'size-6') ?></span>
      <div class="min-w-0 flex-1">
        <h2 class="font-semibold text-slate-900"><?= t('Two-factor authentication is on') ?></h2>
        <p class="text-sm text-slate-500">
          <?= $enabledAt !== null ? t('Since %0.', e(date('Y-m-d', $enabledAt))) : '' ?>
          <?= t('%0 recovery codes left.', $recoveryLeft) ?>
        </p>
      </div>
    </div>
  </section>

  <form method="post" action="<?= url('/account/two-factor/recovery') ?>" class="card" hx-boost="false">
    <?= csrf_field() ?>
    <div class="card-header"><h2 class="card-title"><?= icon('refresh-cw', 'size-4 text-brand-600') ?><?= t('New recovery codes') ?></h2></div>
    <div class="card-body grid gap-4 sm:grid-cols-[1fr_auto] sm:items-end">
      <div>
        <label class="label" for="regen-code"><?= t('Current code from your app') ?></label>
        <input class="input input-mono" id="regen-code" name="code" required autocomplete="one-time-code" inputmode="numeric" maxlength="12">
      </div>
      <button class="btn btn-secondary"><?= icon('refresh-cw') ?><?= t('Create new codes') ?></button>
    </div>
  </form>

  <?php if (!$required): ?>
  <form method="post" action="<?= url('/account/two-factor/disable') ?>" class="card border-rose-200" hx-boost="false"
        data-confirm="<?= t('Turn off two-factor authentication? Your account will be protected by your password only.') ?>" data-confirm-label="<?= t('Turn off') ?>">
    <?= csrf_field() ?>
    <div class="card-header"><h2 class="card-title text-rose-700"><?= icon('shield-off', 'size-4') ?><?= t('Turn off two-factor authentication') ?></h2></div>
    <div class="card-body grid gap-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
      <div>
        <label class="label" for="off-pass"><?= t('Current password') ?></label>
        <input class="input" id="off-pass" name="current" type="password" required autocomplete="current-password">
      </div>
      <div>
        <label class="label" for="off-code"><?= t('Current code from your app') ?></label>
        <input class="input input-mono" id="off-code" name="code" required autocomplete="one-time-code" inputmode="numeric" maxlength="12">
      </div>
      <button class="btn btn-danger-soft"><?= icon('shield-off') ?><?= t('Turn off') ?></button>
    </div>
  </form>
  <?php else: ?>
  <p class="hint"><?= t('Your administrator requires two-factor authentication for your account, so it cannot be turned off.') ?></p>
  <?php endif; ?>

<?php elseif ($policyOff): ?>
  <div class="alert alert-info"><?= icon('info', 'mt-0.5 size-4 shrink-0') ?><?= t('Two-factor authentication is turned off in the settings.') ?></div>

<?php else: ?>
  <?php if ($required): ?>
    <div class="alert alert-info"><?= icon('info', 'mt-0.5 size-4 shrink-0') ?><?= t('Your administrator requires two-factor authentication. Set it up to continue.') ?></div>
  <?php endif; ?>
  <section class="card">
    <div class="card-header"><h2 class="card-title"><?= icon('smartphone', 'size-4 text-brand-600') ?><?= t('Set up your authenticator app') ?></h2></div>
    <div class="card-body grid gap-6 md:grid-cols-[auto_1fr] md:items-start">
      <div class="mx-auto w-52 shrink-0 overflow-hidden rounded-2xl bg-white p-2 ring-1 ring-slate-200 md:mx-0">
        <?= $qr /* generated SVG: fixed markup, text is escaped by QrCode::svg() */ ?>
      </div>
      <div class="space-y-5">
        <ol class="list-decimal space-y-1.5 pl-5 text-sm text-slate-600">
          <li><?= t('Open an authenticator app (1Password, Bitwarden, Google Authenticator, Microsoft Authenticator, …).') ?></li>
          <li><?= t('Scan the QR code, or enter the key below by hand.') ?></li>
          <li><?= t('Type the 6-digit code the app shows.') ?></li>
        </ol>
        <div>
          <span class="label"><?= t('Setup key') ?></span>
          <div class="flex items-center gap-1 rounded-xl bg-slate-50 p-2 ring-1 ring-slate-200">
            <code class="mono min-w-0 flex-1 break-words px-1 text-slate-800"><?= e(trim(chunk_split((string) $secret, 4, ' '))) ?></code>
            <button type="button" class="btn-icon shrink-0" data-copy="<?= e((string) $secret) ?>" aria-label="<?= t('Copy') ?>"><?= icon('copy') ?></button>
          </div>
        </div>
        <form method="post" action="<?= url('/account/two-factor') ?>" hx-boost="false" class="grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
          <?= csrf_field() ?>
          <div>
            <label class="label" for="enable-pass"><?= t('Current password') ?></label>
            <input class="input" id="enable-pass" name="current" type="password" required autocomplete="current-password">
          </div>
          <div>
            <label class="label" for="enable-code"><?= t('Code from your app') ?></label>
            <input class="input input-mono text-lg tracking-[0.3em]" id="enable-code" name="code" required autocomplete="one-time-code" inputmode="numeric" maxlength="12" placeholder="123456">
          </div>
          <button class="btn btn-primary"><?= icon('shield-check') ?><?= t('Turn on') ?></button>
        </form>
      </div>
    </div>
  </section>
<?php endif; ?>
</div>
