<?php
/** @var list<array{parent: SvnManage\Svn\RepositoryParent, repos: list<SvnManage\Svn\Repository>, error: ?string}> $locations */
$total = array_sum(array_map(static fn ($l) => count($l['repos']), $locations));
$heading = t('Repositories');
$sub = t('All Subversion repositories found in the configured parent folders.');
$actions = $canAdd ? '<a href="' . url('/repositories/new') . '" class="btn btn-primary">' . icon('plus') . t('New repository') . '</a>' : '';
include __DIR__ . '/../partials/header.php';
?>
<form method="get" action="<?= url('/repositories') ?>" class="mb-5" hx-get="<?= url('/repositories') ?>" hx-trigger="input changed delay:300ms from:find input, search from:find input" hx-select="#repo-list" hx-target="#repo-list" hx-swap="outerHTML" hx-push-url="true">
  <div class="search max-w-md">
    <?= icon('search') ?>
    <input type="search" name="q" value="<?= e($q) ?>" class="input" placeholder="<?= t('Search repositories…') ?>" data-hotkey-search aria-label="<?= t('Search') ?>">
    <span class="kbd pointer-events-none absolute right-3 top-1/2 hidden -translate-y-1/2 sm:inline">/</span>
  </div>
</form>

<div id="repo-list" class="space-y-6">
<?php foreach ($locations as $loc): $parent = $loc['parent']; ?>
  <form method="post" action="<?= url('/repositories/delete') ?>" class="card" data-bulk
        data-confirm="<?= t('Delete the selected repositories? This cannot be undone.') ?>" data-confirm-label="<?= t('Delete') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="pi" value="<?= (int) $parent->id ?>">
    <div class="card-header flex-nowrap">
      <div class="min-w-0 flex-1">
        <h2 class="card-title"><?= icon('hard-drive', 'size-4 text-brand-600') ?><?= e($parent->description) ?> <span class="badge badge-slate"><?= count($loc['repos']) ?></span></h2>
        <p class="mono mt-0.5 truncate text-xs text-slate-400"><?= e($parent->path) ?></p>
      </div>
      <?php if ($canDelete && $loc['repos'] !== []): ?>
        <label class="flex shrink-0 items-center gap-2 p-2 text-[13px] text-slate-500" title="<?= t('Select all') ?>"><input type="checkbox" class="checkbox" data-bulk-all aria-label="<?= t('Select all') ?>"><span class="hidden sm:inline"><?= t('Select all') ?></span></label>
      <?php endif; ?>
    </div>
    <?php if ($loc['error'] !== null): ?>
      <div class="alert alert-error m-4"><?= icon('circle-x', 'mt-0.5 size-4 shrink-0') ?><span class="break-words"><?= t($loc['error']) ?></span></div>
    <?php elseif ($loc['repos'] === []): ?>
      <?php $emptyIcon = 'database'; $emptyTitle = $q !== '' ? tr('No matches.') : tr('No repositories yet'); $emptyText = $q !== '' ? null : tr('Create the first repository in this location.'); $emptyAction = $canAdd && $q === '' ? '<a class="btn btn-secondary btn-sm" href="' . url('/repositories/new', ['pi' => $parent->id]) . '">' . icon('plus', 'size-3.5') . t('New repository') . '</a>' : null; include __DIR__ . '/../partials/empty.php'; ?>
    <?php else: ?>
      <ul class="list">
        <?php foreach ($loc['repos'] as $repo): $href = url('/repositories/' . $repo->parentId . '/' . rawurlencode($repo->name)); ?>
          <li class="row row-link group relative">
            <?php if ($canDelete): ?><input type="checkbox" class="checkbox" name="selected_repos[]" value="<?= e($repo->name) ?>" data-bulk-item aria-label="<?= t('Select') ?> <?= e($repo->name) ?>"><?php endif; ?>
            <span class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600"><?= icon('database') ?></span>
            <a href="<?= $href ?>" class="min-w-0 flex-1">
              <span class="block truncate font-medium text-slate-900 group-hover:text-brand-700"><?= e($repo->name) ?></span>
              <span class="mono block truncate text-xs text-slate-400"><?= e($repo->path) ?></span>
            </a>
            <div class="relative z-10 flex shrink-0 items-center gap-1">
              <a href="<?= url('/repositories/' . $repo->parentId . '/' . rawurlencode($repo->name) . '/browse') ?>" class="btn-icon" title="<?= t('Browse') ?>" aria-label="<?= t('Browse') ?>"><?= icon('folder-open') ?></a>
              <?php if ($canDump): ?><button type="submit" form="dump-<?= (int) $repo->parentId ?>-<?= e($repo->name) ?>" class="btn-icon hidden sm:inline-flex" title="<?= t('Download dump') ?>" aria-label="<?= t('Download dump') ?>"><?= icon('download') ?></button><?php endif; ?>
              <?= icon('chevron-right', 'size-4 text-slate-300') ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php if ($canDelete): ?>
        <div data-bulk-bar hidden class="sticky bottom-3 m-3 flex flex-wrap items-center gap-3 rounded-xl bg-slate-900 px-4 py-3 text-sm text-white shadow-lift">
          <span><span data-bulk-count>0</span> <?= t('selected') ?></span>
          <?php if ($canDeletePaths): ?>
            <label class="flex items-center gap-2 text-slate-300"><input type="checkbox" class="checkbox" name="delete_ap" value="1" checked><?= t('Also delete access paths') ?></label>
          <?php endif; ?>
          <button class="btn btn-danger btn-sm ml-auto" data-bulk-requires><?= icon('trash-2', 'size-3.5') ?><?= t('Delete') ?></button>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </form>
  <?php if ($canDump): foreach ($loc['repos'] as $repo): ?>
  <form id="dump-<?= (int) $repo->parentId ?>-<?= e($repo->name) ?>" method="post" action="<?= url('/repositories/' . $repo->parentId . '/' . rawurlencode($repo->name) . '/dump') ?>" hx-boost="false" hidden><?= csrf_field() ?></form>
  <?php endforeach; endif; ?>
<?php endforeach; ?>
<?php if (count($locations) > 1): ?><p class="text-center text-xs text-slate-400"><?= t('%0 repositories in %1 locations', $total, count($locations)) ?></p><?php endif; ?>
</div>
