<?php /** @var list<array{name: string, users: int, subgroups: int, paths: int}> $groups */
$heading = t('Groups');
$sub = $source === 'svnauthfile' ? t('Groups defined in the Subversion authorization file.') : t('Groups synchronised from %0 into the authorization file.', strtoupper($source));
$actions = $canAdd ? '<a href="' . url('/groups/new') . '" class="btn btn-primary">' . icon('plus') . t('New group') . '</a>' : '';
include __DIR__ . '/../partials/header.php';
?>
<form method="get" action="<?= url('/groups') ?>" class="mb-5" hx-get="<?= url('/groups') ?>" hx-trigger="input changed delay:300ms from:find input, search from:find input" hx-select="#group-list" hx-target="#group-list" hx-swap="outerHTML" hx-push-url="true">
  <div class="search max-w-md"><?= icon('search') ?><input type="search" name="q" value="<?= e($q) ?>" class="input" placeholder="<?= t('Search groups…') ?>" data-hotkey-search aria-label="<?= t('Search') ?>"></div>
</form>
<form id="group-list" method="post" action="<?= url('/groups/delete') ?>" class="card" data-bulk data-confirm="<?= t('Delete the selected groups? Their memberships and permissions are removed too.') ?>" data-confirm-label="<?= t('Delete') ?>">
  <?= csrf_field() ?>
  <?php if ($groups === []): ?>
    <?php $emptyIcon = 'users'; $emptyTitle = $q !== '' ? tr('No matches.') : tr('No groups yet'); $emptyText = $q !== '' ? null : tr('Groups let you grant permissions to many users at once.'); $emptyAction = $canAdd && $q === '' ? '<a class="btn btn-secondary btn-sm" href="' . url('/groups/new') . '">' . icon('plus', 'size-3.5') . t('New group') . '</a>' : null; include __DIR__ . '/../partials/empty.php'; ?>
  <?php else: ?>
    <?php if ($canDelete): ?><div class="card-header py-3"><label class="flex items-center gap-2 text-[13px] text-slate-500"><input type="checkbox" class="checkbox" data-bulk-all><?= t('Select all') ?></label></div><?php endif; ?>
    <ul class="list">
      <?php foreach ($groups as $g): ?>
        <li class="row row-link group relative">
          <?php if ($canDelete): ?><input type="checkbox" class="checkbox relative z-10" name="selected_groups[]" value="<?= e($g['name']) ?>" data-bulk-item aria-label="<?= t('Select') ?> @<?= e($g['name']) ?>"><?php endif; ?>
          <span class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-violet-50 text-violet-600"><?= icon('users') ?></span>
          <a href="<?= url('/groups/' . rawurlencode($g['name'])) ?>" class="min-w-0 flex-1 truncate font-medium text-slate-900 before:absolute before:inset-0 group-hover:text-brand-700">@<?= e($g['name']) ?></a>
          <span class="flex items-center gap-3 text-xs text-slate-500">
            <span class="flex items-center gap-1" title="<?= t('Users') ?>"><?= icon('user', 'size-3.5') ?><?= $g['users'] ?></span>
            <?php if ($g['subgroups'] > 0): ?><span class="hidden items-center gap-1 sm:flex" title="<?= t('Sub-groups') ?>"><?= icon('network', 'size-3.5') ?><?= $g['subgroups'] ?></span><?php endif; ?>
            <span class="hidden items-center gap-1 sm:flex" title="<?= t('Access-Paths') ?>"><?= icon('folder-tree', 'size-3.5') ?><?= $g['paths'] ?></span>
          </span>
          <?= icon('chevron-right', 'size-4 text-slate-300') ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if ($canDelete): ?>
      <div data-bulk-bar hidden class="sticky bottom-3 m-3 flex items-center gap-3 rounded-xl bg-slate-900 px-4 py-3 text-sm text-white shadow-lift">
        <span class="mr-auto"><span data-bulk-count>0</span> <?= t('selected') ?></span>
        <button class="btn btn-danger btn-sm" data-bulk-requires><?= icon('trash-2', 'size-3.5') ?><?= t('Delete') ?></button>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</form>
