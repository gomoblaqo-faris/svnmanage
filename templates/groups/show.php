<?php /** @var string $name @var list<string> $users @var list<string> $subgroups @var list<string> $parents @var list<array{path: string, permission: SvnManage\Authz\Permission}> $permissions */
$base = '/groups/' . rawurlencode($name);
$heading = '<span class="flex items-center gap-3"><span class="flex size-11 items-center justify-center rounded-2xl bg-violet-50 text-violet-600">' . icon('users', 'size-5') . '</span>@' . e($name) . '</span>';
$crumbs = [tr('Groups') => url('/groups')];
include __DIR__ . '/../partials/header.php';
?>
<?php if ($parents !== []): ?>
  <p class="-mt-3 mb-6 flex flex-wrap items-center gap-1.5 text-sm text-slate-500"><?= t('Member of') ?>
    <?php foreach ($parents as $p): ?><a href="<?= url('/groups/' . rawurlencode($p)) ?>" class="chip bg-violet-50 text-violet-700 hover:bg-violet-100">@<?= e($p) ?></a><?php endforeach; ?>
  </p>
<?php endif; ?>
<div class="grid gap-6 lg:grid-cols-2 lg:items-start">
  <section class="card">
    <div class="card-header"><h2 class="card-title"><?= icon('user', 'size-4 text-brand-600') ?><?= t('Members') ?> <span class="badge badge-slate"><?= count($users) + count($subgroups) ?></span></h2></div>
    <?php if ($users === [] && $subgroups === []): ?>
      <p class="card-body text-sm text-slate-500"><?= t('This group has no members.') ?></p>
    <?php else: ?>
      <ul class="list max-h-[28rem] overflow-y-auto">
        <?php foreach ($subgroups as $g): ?>
          <li class="row text-sm">
            <span class="flex size-8 items-center justify-center rounded-lg bg-violet-50 text-violet-600"><?= icon('users', 'size-4') ?></span>
            <a href="<?= url('/groups/' . rawurlencode($g)) ?>" class="min-w-0 flex-1 truncate font-medium text-slate-800 hover:text-brand-700">@<?= e($g) ?></a>
            <?php if ($canUnassign): $rmAction = $base . '/members/remove'; $rmField = 'members[]'; $rmValue = '@' . $g; $rmLabel = tr('Remove member'); include __DIR__ . '/../partials/remove-btn.php'; endif; ?>
          </li>
        <?php endforeach; ?>
        <?php foreach ($users as $u): ?>
          <li class="row text-sm">
            <span class="avatar avatar-sm" style="--h: <?= avatar_hue(ltrim($u, '&')) ?>"><?= e(initials(ltrim($u, '&'))) ?></span>
            <?php if ($u === '*'): ?><span class="min-w-0 flex-1 font-medium text-slate-800"><?= t('Everyone') ?> (*)</span>
            <?php else: ?><a href="<?= url('/users/' . rawurlencode($u)) ?>" class="min-w-0 flex-1 truncate font-medium text-slate-800 hover:text-brand-700"><?= e($u) ?></a><?php endif; ?>
            <?php if ($canUnassign): $rmAction = $base . '/members/remove'; $rmField = 'members[]'; $rmValue = $u; $rmLabel = tr('Remove member'); include __DIR__ . '/../partials/remove-btn.php'; endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <?php if ($canAssign && ($userCandidates !== [] || $groupCandidates !== [])): ?>
      <details class="border-t border-slate-100">
        <summary class="cursor-pointer list-none px-5 py-3 text-sm font-medium text-brand-700 hover:bg-slate-50 [&::-webkit-details-marker]:hidden"><?= icon('user-plus', 'mr-1 inline size-4') ?><?= t('Add members') ?></summary>
        <form method="post" action="<?= url($base . '/members') ?>" class="space-y-4 px-5 pb-5">
          <?= csrf_field() ?>
          <?php if ($userCandidates !== []): $pickName = 'users[]'; $pickType = 'checkbox'; $pickLabel = tr('Users'); $pickItems = array_map(static fn ($u) => ['value' => $u, 'label' => $u, 'icon' => 'user'], $userCandidates); include __DIR__ . '/../partials/picker.php'; endif; ?>
          <?php if ($groupCandidates !== []): $pickName = 'groups[]'; $pickType = 'checkbox'; $pickLabel = tr('Sub-groups'); $pickItems = array_map(static fn ($g) => ['value' => $g, 'label' => '@' . $g, 'icon' => 'users'], $groupCandidates); include __DIR__ . '/../partials/picker.php'; endif; ?>
          <button class="btn btn-primary btn-sm"><?= icon('plus', 'size-3.5') ?><?= t('Add') ?></button>
        </form>
      </details>
    <?php endif; ?>
  </section>

  <div class="space-y-6">
    <section class="card">
      <div class="card-header"><h2 class="card-title"><?= icon('key-round', 'size-4 text-brand-600') ?><?= t('Permissions') ?> <span class="badge badge-slate"><?= count($permissions) ?></span></h2></div>
      <?php if ($permissions === []): ?>
        <p class="card-body text-sm text-slate-500"><?= t('No permissions.') ?></p>
      <?php else: ?>
        <ul class="list">
          <?php foreach ($permissions as $p): ?>
            <li class="row text-sm">
              <?= icon('folder', 'size-4 shrink-0 text-slate-400') ?>
              <a href="<?= url('/access-paths/view', ['path' => $p['path']]) ?>" class="mono min-w-0 flex-1 truncate hover:text-brand-700"><?= e($p['path']) ?></a>
              <?php $perm = $p['permission']; include __DIR__ . '/../partials/perm.php'; ?>
              <?php if ($canRevoke): $rmAction = $base . '/permissions/remove'; $rmField = 'paths[]'; $rmValue = $p['path']; $rmLabel = tr('Revoke'); include __DIR__ . '/../partials/remove-btn.php'; endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <?php if ($canRename): ?>
    <form method="post" action="<?= url($base . '/rename') ?>" class="card">
      <?= csrf_field() ?>
      <div class="card-body">
        <label class="label" for="newname"><?= t('Rename group') ?></label>
        <div class="flex gap-2">
          <input class="input input-mono" id="newname" name="newname" value="<?= e($name) ?>" required maxlength="128" autocapitalize="none" spellcheck="false">
          <button class="btn btn-secondary shrink-0"><?= icon('pencil') ?><?= t('Rename') ?></button>
        </div>
        <p class="hint"><?= t('Memberships and permissions are updated everywhere.') ?></p>
      </div>
    </form>
    <?php endif; ?>

    <?php if ($canDelete): ?>
    <form method="post" action="<?= url('/groups/delete') ?>" class="card border-rose-200" data-confirm="<?= t('Delete group %0? Its memberships and permissions are removed too.', '@' . $name) ?>" data-confirm-label="<?= t('Delete') ?>">
      <?= csrf_field() ?><input type="hidden" name="selected_groups[]" value="<?= e($name) ?>">
      <div class="card-body flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div><h2 class="font-semibold text-rose-700"><?= t('Danger zone') ?></h2><p class="text-sm text-slate-500"><?= t('Removes the group, its memberships and its permissions.') ?></p></div>
        <button class="btn btn-danger-soft"><?= icon('trash-2') ?><?= t('Delete group') ?></button>
      </div>
    </form>
    <?php endif; ?>
  </div>
</div>
