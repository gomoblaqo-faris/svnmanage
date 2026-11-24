<?php /** @var string $old @var list<string> $users @var list<string> $groups @var bool $canAssign */
$heading = t('New group');
$crumbs = [tr('Groups') => url('/groups')];
include __DIR__ . '/../partials/header.php';
?>
<form method="post" action="<?= url('/groups') ?>" class="space-y-6">
  <?= csrf_field() ?>
  <section class="card">
    <div class="card-body">
      <label class="label" for="groupname"><?= t('Group name') ?></label>
      <div class="flex max-w-md items-center rounded-xl ring-1 ring-slate-200 focus-within:ring-2 focus-within:ring-brand-500">
        <span class="pl-3.5 font-mono text-slate-400">@</span>
        <input class="input border-0 pl-1 ring-0 focus:ring-0" id="groupname" name="groupname" value="<?= e($old) ?>" required autofocus maxlength="128" autocapitalize="none" spellcheck="false">
      </div>
    </div>
  </section>
  <?php if ($canAssign && ($users !== [] || $groups !== [])): ?>
  <section class="card">
    <div class="card-header"><h2 class="card-title"><?= icon('user-plus', 'size-4 text-brand-600') ?><?= t('Initial members') ?></h2><span class="text-xs text-slate-400"><?= t('optional') ?></span></div>
    <div class="card-body grid gap-6 md:grid-cols-2">
      <?php $pickName = 'users[]'; $pickType = 'checkbox'; $pickLabel = tr('Users'); $pickItems = array_map(static fn ($u) => ['value' => $u, 'label' => $u, 'icon' => 'user'], $users); include __DIR__ . '/../partials/picker.php'; ?>
      <?php $pickName = 'groups[]'; $pickLabel = tr('Sub-groups'); $pickItems = array_map(static fn ($g) => ['value' => $g, 'label' => '@' . $g, 'icon' => 'users'], $groups); include __DIR__ . '/../partials/picker.php'; ?>
    </div>
  </section>
  <?php endif; ?>
  <div class="flex gap-2">
    <button class="btn btn-primary"><?= icon('plus') ?><?= t('Create group') ?></button>
    <a href="<?= url('/groups') ?>" class="btn btn-secondary"><?= t('Cancel') ?></a>
  </div>
</form>
