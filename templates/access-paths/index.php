<?php /** @var array<string, list<array{path: string, users: int, groups: int, managers: list<string>}>> $byRepo */
$heading = t('Access-Paths');
$sub = $v->auth->isPathScoped() ? t('The access paths you manage as project manager.') : t('Sections of the authorization file. Each grants users and groups access to a repository path.');
$actions = $canAdd ? '<a href="' . url('/access-paths/new') . '" class="btn btn-primary">' . icon('plus') . t('New access path') . '</a>' : '';
include __DIR__ . '/../partials/header.php';
$bulk = $canDelete || $canManagers;
?>
<form method="get" action="<?= url('/access-paths') ?>" class="mb-5" hx-get="<?= url('/access-paths') ?>" hx-trigger="input changed delay:300ms from:find input, search from:find input" hx-select="#path-list" hx-target="#path-list" hx-swap="outerHTML" hx-push-url="true">
  <div class="search max-w-md"><?= icon('search') ?><input type="search" name="q" value="<?= e($q) ?>" class="input" placeholder="<?= t('Search access paths…') ?>" data-hotkey-search aria-label="<?= t('Search') ?>"></div>
</form>

<form id="path-list" method="post" action="<?= url('/access-paths/delete') ?>" data-bulk class="space-y-5">
  <?= csrf_field() ?>
  <?php if ($byRepo === []): ?>
    <div class="card"><?php $emptyIcon = 'folder-tree'; $emptyTitle = $q !== '' ? tr('No matches.') : tr('No access paths yet'); $emptyText = $q !== '' ? null : tr('Create an access path to start granting permissions.'); $emptyAction = $canAdd && $q === '' ? '<a class="btn btn-secondary btn-sm" href="' . url('/access-paths/new') . '">' . icon('plus', 'size-3.5') . t('New access path') . '</a>' : null; include __DIR__ . '/../partials/empty.php'; ?></div>
  <?php else: ?>
    <?php if ($bulk): ?><label class="flex items-center gap-2 px-1 text-[13px] text-slate-500"><input type="checkbox" class="checkbox" data-bulk-all><?= t('Select all') ?> <span class="text-slate-400">(<?= (int) $total ?>)</span></label><?php endif; ?>
    <?php foreach ($byRepo as $repo => $paths): ?>
      <section class="card">
        <div class="card-header py-3">
          <h2 class="card-title text-[15px]"><?= icon($repo === '' ? 'globe' : 'database', 'size-4 text-brand-600') ?><?= $repo === '' ? t('All repositories') : e($repo) ?> <span class="badge badge-slate"><?= count($paths) ?></span></h2>
        </div>
        <ul class="list">
          <?php foreach ($paths as $p): ?>
            <li class="row row-link group relative">
              <?php if ($bulk): ?><input type="checkbox" class="checkbox relative z-10" name="selected_paths[]" value="<?= e($p['path']) ?>" data-bulk-item aria-label="<?= t('Select') ?> <?= e($p['path']) ?>"><?php endif; ?>
              <?= icon('folder', 'size-[18px] shrink-0 text-amber-500') ?>
              <a href="<?= url('/access-paths/view', ['path' => $p['path']]) ?>" class="mono min-w-0 flex-1 truncate text-slate-800 before:absolute before:inset-0 group-hover:text-brand-700"><?= e($p['path']) ?></a>
              <?php if ($p['managers'] !== []): ?>
                <span class="hidden -space-x-1.5 md:flex" title="<?= t('Project managers') ?>: <?= e(implode(', ', $p['managers'])) ?>">
                  <?php foreach (array_slice($p['managers'], 0, 3) as $m): ?><span class="avatar avatar-sm ring-2 ring-white" style="--h: <?= avatar_hue($m) ?>"><?= e(initials($m)) ?></span><?php endforeach; ?>
                </span>
              <?php endif; ?>
              <span class="flex items-center gap-3 text-xs text-slate-500">
                <span class="flex items-center gap-1" title="<?= t('Users') ?>"><?= icon('user', 'size-3.5') ?><?= $p['users'] ?></span>
                <span class="flex items-center gap-1" title="<?= t('Groups') ?>"><?= icon('users', 'size-3.5') ?><?= $p['groups'] ?></span>
              </span>
              <?= icon('chevron-right', 'size-4 text-slate-300') ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php endforeach; ?>
    <?php if ($bulk): ?>
      <div data-bulk-bar hidden class="sticky bottom-3 flex flex-wrap items-center gap-2 rounded-xl bg-slate-900 px-4 py-3 text-sm text-white shadow-lift">
        <span class="mr-auto"><span data-bulk-count>0</span> <?= t('selected') ?></span>
        <?php if ($canManagers && $users !== []): ?>
          <label class="sr-only" for="pm-user"><?= t('Project manager') ?></label>
          <select id="pm-user" name="users[]" class="input h-9 w-auto max-w-40 border-0 bg-white/10 py-1 text-sm text-white">
            <?php foreach ($users as $u): ?><option value="<?= e($u) ?>" class="text-slate-900"><?= e($u) ?></option><?php endforeach; ?>
          </select>
          <button class="btn btn-sm bg-white text-slate-900 hover:bg-slate-100" formaction="<?= url('/access-paths/managers') ?>" data-bulk-requires><?= icon('briefcase', 'size-3.5') ?><?= t('Make project manager') ?></button>
        <?php endif; ?>
        <?php if ($canDelete): ?>
          <button class="btn btn-danger btn-sm" data-bulk-requires data-confirm="<?= t('Delete the selected access paths and all their permissions?') ?>" data-confirm-label="<?= t('Delete') ?>"><?= icon('trash-2', 'size-3.5') ?><?= t('Delete') ?></button>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</form>
