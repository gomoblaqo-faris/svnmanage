<?php
/**
 * Setup step 2: security profile, what svnmanage sees, and the security settings.
 *
 * @var string $profile
 * @var list<array{0: string, 1: string, 2: list<string>}> $checks
 */
use SvnManage\Config\SecurityProfiles;
use SvnManage\Security\SetupChecks;

$checkStyle = [
    SetupChecks::OK => ['circle-check', 'text-emerald-600'],
    SetupChecks::WARN => ['triangle-alert', 'text-amber-600'],
    SetupChecks::INFO => ['info', 'text-brand-600'],
];
$warnings = count(array_filter($checks, static fn (array $c): bool => $c[0] === SetupChecks::WARN));
?>
<div class="mx-auto max-w-4xl space-y-6">
  <section aria-labelledby="profile-title">
    <h2 id="profile-title" class="mb-1 text-center font-semibold text-slate-900"><?= t('Where will svnmanage run?') ?></h2>
    <p class="mb-4 text-center text-sm text-slate-500"><?= t('Pick a starting point. It only fills in the form below; adjust anything before you continue.') ?></p>
    <div class="grid gap-3 sm:grid-cols-2">
      <?php foreach (SecurityProfiles::PROFILES as $id => [$title, $description]): $active = $id === $profile; ?>
        <a href="<?= url('/setup', ['profile' => $id]) ?>" class="radio-card <?= $active ? 'bg-brand-50/60 ring-2 ring-brand-500' : '' ?>" <?= $active ? 'aria-current="true"' : '' ?>>
          <span class="flex size-9 shrink-0 items-center justify-center rounded-lg <?= $active ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-600' ?>"><?= icon($id === 'public' ? 'globe' : 'network', 'size-4') ?></span>
          <span class="min-w-0">
            <span class="flex flex-wrap items-center gap-2 text-sm font-semibold text-slate-900"><?= t($title) ?>
              <?php if ($id === SecurityProfiles::DEFAULT): ?><span class="badge badge-brand"><?= t('recommended') ?></span><?php endif; ?>
            </span>
            <span class="mt-0.5 block text-[13px] leading-snug text-slate-500"><?= t($description) ?></span>
          </span>
        </a>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="card" aria-labelledby="sees-title">
    <div class="card-header">
      <div>
        <h2 id="sees-title" class="card-title"><span class="flex size-8 items-center justify-center rounded-lg bg-brand-50 text-brand-600"><?= icon('server') ?></span><?= t('What svnmanage sees') ?></h2>
        <p class="mt-1 text-[13px] text-slate-500"><?= $warnings === 0 ? t('Nothing to fix before you continue.') : t('%0 things to look at before this server faces the internet.', $warnings) ?></p>
      </div>
    </div>
    <ul class="divide-y divide-slate-100 border-t border-slate-100">
      <?php foreach ($checks as [$status, $message, $args]): [$ic, $tone] = $checkStyle[$status]; ?>
        <li class="flex items-start gap-3 px-5 py-3 text-sm text-slate-700">
          <span class="mt-0.5 shrink-0 <?= $tone ?>"><?= icon($ic, 'size-4') ?></span>
          <span class="min-w-0 break-words"><?= $args === [] ? t($message) : t($message, tr($args[0]), ...array_slice($args, 1)) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>

  <?php include __DIR__ . '/../settings/form.php'; ?>

  <form method="post" action="<?= url('/setup') ?>" class="text-center">
    <?= csrf_field() ?><input type="hidden" name="action" value="reconfigure">
    <button class="btn btn-ghost btn-sm"><?= icon('arrow-left', 'size-3.5') ?><?= t('Back to configuration') ?></button>
  </form>
</div>
