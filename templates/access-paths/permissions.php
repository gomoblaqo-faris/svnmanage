<?php /** @var list<string> $paths @var list<string> $users @var list<string> $groups @var string $preselect */
$heading = t('Permissions');
$sub = t('Grant one permission to many users and groups on many access paths at once.');
include __DIR__ . '/../partials/header.php';
?>
<?php if ($paths === []): ?>
  <div class="card"><?php $emptyIcon = 'folder-tree'; $emptyTitle = tr('No access paths yet'); $emptyText = null; $emptyAction = '<a class="btn btn-secondary btn-sm" href="' . url('/access-paths/new') . '">' . icon('plus', 'size-3.5') . t('New access path') . '</a>'; include __DIR__ . '/../partials/empty.php'; ?></div>
<?php else: ?>
<form method="post" action="<?= url('/permissions') ?>" class="space-y-6">
  <?= csrf_field() ?>
  <div class="grid gap-6 lg:grid-cols-2">
    <section class="card">
      <div class="card-header"><h2 class="card-title"><span class="flex size-6 items-center justify-center rounded-full bg-brand-600 text-xs text-white">1</span><?= t('Who') ?></h2></div>
      <div class="card-body space-y-5">
        <?php $pickName = 'users[]'; $pickType = 'checkbox'; $pickLabel = tr('Users'); $pickItems = array_map(static fn ($u) => ['value' => $u, 'label' => $u, 'icon' => 'user'], $users); include __DIR__ . '/../partials/picker.php'; ?>
        <?php $pickName = 'groups[]'; $pickLabel = tr('Groups'); $pickItems = array_map(static fn ($g) => ['value' => $g, 'label' => '@' . $g, 'icon' => 'users'], $groups); include __DIR__ . '/../partials/picker.php'; ?>
      </div>
    </section>
    <section class="card">
      <div class="card-header"><h2 class="card-title"><span class="flex size-6 items-center justify-center rounded-full bg-brand-600 text-xs text-white">2</span><?= t('Where') ?></h2></div>
      <div class="card-body">
        <?php $pickName = 'paths[]'; $pickChecked = $preselect !== '' ? [$preselect] : []; $pickLabel = tr('Access-Paths'); $pickItems = array_map(static fn ($p) => ['value' => $p, 'label' => $p, 'icon' => 'folder'], $paths); include __DIR__ . '/../partials/picker.php'; ?>
      </div>
    </section>
  </div>
  <section class="card">
    <div class="card-header"><h2 class="card-title"><span class="flex size-6 items-center justify-center rounded-full bg-brand-600 text-xs text-white">3</span><?= t('What') ?></h2></div>
    <div class="card-body"><?php $permName = 'permission'; $permDefault = 'r'; include __DIR__ . '/../partials/permselect.php'; ?></div>
  </section>
  <div class="sticky bottom-3 flex justify-end rounded-2xl bg-white/90 p-3 shadow-lift ring-1 ring-slate-200 backdrop-blur">
    <button class="btn btn-primary"><?= icon('check') ?><?= t('Grant permissions') ?></button>
  </div>
</form>
<?php endif; ?>
