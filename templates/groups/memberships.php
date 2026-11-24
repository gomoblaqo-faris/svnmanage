<?php /** @var list<string> $users @var list<string> $groups @var bool $canUnassign */
$heading = t('Memberships');
$sub = t('Add many users and groups to many groups in one step.');
$crumbs = [tr('Groups') => url('/groups')];
include __DIR__ . '/../partials/header.php';
?>
<?php if ($groups === []): ?>
  <div class="card"><?php $emptyIcon = 'users'; $emptyTitle = tr('No groups yet'); $emptyText = null; $emptyAction = '<a class="btn btn-secondary btn-sm" href="' . url('/groups/new') . '">' . icon('plus', 'size-3.5') . t('New group') . '</a>'; include __DIR__ . '/../partials/empty.php'; ?></div>
<?php else: ?>
<form method="post" action="<?= url('/groups/memberships') ?>" class="space-y-6">
  <?= csrf_field() ?>
  <div class="grid gap-6 lg:grid-cols-[1fr_auto_1fr] lg:items-start">
    <section class="card">
      <div class="card-header"><h2 class="card-title"><span class="flex size-6 items-center justify-center rounded-full bg-brand-600 text-xs text-white">1</span><?= t('Choose members') ?></h2></div>
      <div class="card-body space-y-5">
        <?php $pickName = 'users[]'; $pickType = 'checkbox'; $pickLabel = tr('Users'); $pickItems = array_map(static fn ($u) => ['value' => $u, 'label' => $u, 'icon' => 'user'], $users); include __DIR__ . '/../partials/picker.php'; ?>
        <?php $pickName = 'groups[]'; $pickLabel = tr('Groups'); $pickItems = array_map(static fn ($g) => ['value' => $g, 'label' => '@' . $g, 'icon' => 'users'], $groups); include __DIR__ . '/../partials/picker.php'; ?>
      </div>
    </section>
    <div class="flex justify-center text-slate-300 lg:pt-24"><?= icon('arrow-right', 'size-6 rotate-90 lg:rotate-0') ?></div>
    <section class="card">
      <div class="card-header"><h2 class="card-title"><span class="flex size-6 items-center justify-center rounded-full bg-brand-600 text-xs text-white">2</span><?= t('Choose target groups') ?></h2></div>
      <div class="card-body">
        <?php $pickName = 'targets[]'; $pickLabel = tr('Groups'); $pickItems = array_map(static fn ($g) => ['value' => $g, 'label' => '@' . $g, 'icon' => 'users'], $groups); include __DIR__ . '/../partials/picker.php'; ?>
      </div>
    </section>
  </div>
  <div class="sticky bottom-3 flex justify-end gap-2 rounded-2xl bg-white/90 p-2 shadow-lift ring-1 ring-slate-200 backdrop-blur sm:p-3">
    <?php if ($canUnassign): ?><button class="btn btn-secondary flex-1 sm:flex-none" name="mode" value="remove"><?= icon('user-minus') ?><span class="sm:hidden"><?= t('Remove') ?></span><span class="hidden sm:inline"><?= t('Remove memberships') ?></span></button><?php endif; ?>
    <button class="btn btn-primary flex-1 sm:flex-none" name="mode" value="add"><?= icon('user-plus') ?><span class="sm:hidden"><?= t('Add') ?></span><span class="hidden sm:inline"><?= t('Add memberships') ?></span></button>
  </div>
</form>
<?php endif; ?>
