<?php
/** @var string $path @var string $repo @var array<string, SvnManage\Authz\Permission> $userRules @var array<string, SvnManage\Authz\Permission> $groupRules @var array<string,string> $otherRules @var list<string> $managers */
$heading = '<span class="font-mono">' . e($path) . '</span>';
$crumbs = [tr('Access-Paths') => url('/access-paths')];
$actions = $canGrant ? '<a href="#grant" hx-boost="false" class="btn btn-primary">' . icon('key-round') . t('Grant access') . '</a>' : '';
include __DIR__ . '/../partials/header.php';
$count = count($userRules) + count($groupRules) + count($otherRules);
$special = ['*' => 'Everyone', '$authenticated' => 'Authenticated users', '$anonymous' => 'Anonymous users'];
?>
<div class="grid gap-6 lg:grid-cols-3 lg:items-start">
  <div class="space-y-6 lg:col-span-2">
    <section class="card">
      <div class="card-header"><h2 class="card-title"><?= icon('shield', 'size-4 text-brand-600') ?><?= t('Permissions') ?> <span class="badge badge-slate"><?= $count ?></span></h2></div>
      <?php if ($count === 0): ?>
        <?php $emptyIcon = 'lock'; $emptyTitle = tr('No permissions yet'); $emptyText = tr('Nobody has explicit access to this path.'); $emptyAction = null; include __DIR__ . '/../partials/empty.php'; ?>
      <?php else: ?>
        <ul class="list">
          <?php foreach ($groupRules as $g => $perm): ?>
            <li class="row text-sm">
              <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-violet-50 text-violet-600"><?= icon('users', 'size-4') ?></span>
              <a href="<?= url('/groups/' . rawurlencode($g)) ?>" class="min-w-0 flex-1 truncate font-medium text-slate-800 hover:text-brand-700">@<?= e($g) ?></a>
              <?php include __DIR__ . '/../partials/perm.php'; ?>
              <?php if ($canRevoke): $rmAction = '/access-paths/revoke'; $rmField = 'members[]'; $rmValue = '@' . $g; $rmLabel = tr('Revoke'); ?>
                <form method="post" action="<?= url($rmAction) ?>" class="shrink-0"><?= csrf_field() ?><input type="hidden" name="path" value="<?= e($path) ?>"><input type="hidden" name="members[]" value="<?= e($rmValue) ?>"><button class="btn-icon text-slate-400 hover:bg-rose-50 hover:text-rose-600" title="<?= e($rmLabel) ?>" aria-label="<?= e($rmLabel) ?>"><?= icon('x') ?></button></form>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
          <?php foreach ($userRules as $u => $perm): $isSpecial = isset($special[$u]); ?>
            <li class="row text-sm">
              <?php if ($isSpecial): ?><span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-amber-50 text-amber-600"><?= icon('globe', 'size-4') ?></span><span class="min-w-0 flex-1 font-medium text-slate-800"><?= t($special[$u]) ?> <span class="mono text-xs text-slate-400"><?= e($u) ?></span></span>
              <?php else: ?><span class="avatar avatar-sm" style="--h: <?= avatar_hue($u) ?>"><?= e(initials(ltrim($u, '&'))) ?></span><a href="<?= url('/users/' . rawurlencode($u)) ?>" class="min-w-0 flex-1 truncate font-medium text-slate-800 hover:text-brand-700"><?= e($u) ?></a><?php endif; ?>
              <?php include __DIR__ . '/../partials/perm.php'; ?>
              <?php if ($canRevoke): ?>
                <form method="post" action="<?= url('/access-paths/revoke') ?>" class="shrink-0"><?= csrf_field() ?><input type="hidden" name="path" value="<?= e($path) ?>"><input type="hidden" name="members[]" value="<?= e($u) ?>"><button class="btn-icon text-slate-400 hover:bg-rose-50 hover:text-rose-600" title="<?= t('Revoke') ?>" aria-label="<?= t('Revoke') ?>"><?= icon('x') ?></button></form>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
          <?php foreach ($otherRules as $token => $raw): ?>
            <li class="row text-sm"><span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-500"><?= icon('code', 'size-4') ?></span><span class="mono min-w-0 flex-1 truncate text-slate-700"><?= e($token) ?> = <?= e($raw) ?></span>
              <?php if ($canRevoke): ?><form method="post" action="<?= url('/access-paths/revoke') ?>" class="shrink-0"><?= csrf_field() ?><input type="hidden" name="path" value="<?= e($path) ?>"><input type="hidden" name="members[]" value="<?= e($token) ?>"><button class="btn-icon text-slate-400 hover:bg-rose-50 hover:text-rose-600" aria-label="<?= t('Revoke') ?>"><?= icon('x') ?></button></form><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <?php if ($canGrant): ?>
    <form id="grant" method="post" action="<?= url('/access-paths/grant') ?>" class="card scroll-mt-20">
      <?= csrf_field() ?><input type="hidden" name="path" value="<?= e($path) ?>">
      <div class="card-header"><h2 class="card-title"><?= icon('key-round', 'size-4 text-brand-600') ?><?= t('Grant access') ?></h2></div>
      <div class="card-body space-y-5">
        <div class="grid gap-5 md:grid-cols-2">
          <?php $pickName = 'users[]'; $pickType = 'checkbox'; $pickLabel = tr('Users'); $pickItems = array_map(static fn ($u) => ['value' => $u, 'label' => $u, 'icon' => 'user'], $users); include __DIR__ . '/../partials/picker.php'; ?>
          <?php $pickName = 'groups[]'; $pickLabel = tr('Groups'); $pickItems = array_map(static fn ($g) => ['value' => $g, 'label' => '@' . $g, 'icon' => 'users'], $groups); include __DIR__ . '/../partials/picker.php'; ?>
        </div>
        <div class="flex flex-wrap gap-2">
          <?php foreach ($special as $tok => $label): if (isset($userRules[$tok])) { continue; } ?>
            <label class="chip cursor-pointer bg-slate-100 text-slate-700 has-[:checked]:bg-amber-100 has-[:checked]:text-amber-800"><input type="checkbox" name="special[]" value="<?= e($tok) ?>" class="sr-only"><?= icon('globe', 'size-3.5') ?><?= t($label) ?></label>
          <?php endforeach; ?>
        </div>
        <?php $permName = 'permission'; $permDefault = 'r'; include __DIR__ . '/../partials/permselect.php'; ?>
      </div>
      <div class="flex justify-end border-t border-slate-100 px-5 py-4"><button class="btn btn-primary"><?= icon('check') ?><?= t('Grant') ?></button></div>
    </form>
    <?php endif; ?>
  </div>

  <aside class="space-y-6">
    <section class="card">
      <dl class="card-body space-y-3 text-sm">
        <div><dt class="text-xs text-slate-500"><?= t('Repository') ?></dt><dd class="font-medium text-slate-800"><?= $repo === '' ? t('All repositories') : e($repo) ?></dd></div>
        <div><dt class="text-xs text-slate-500"><?= t('Authz section') ?></dt><dd class="flex items-center gap-1"><code class="mono min-w-0 flex-1 truncate rounded bg-slate-100 px-2 py-1 text-slate-700">[<?= e($path) ?>]</code><button type="button" class="btn-icon" data-copy="[<?= e($path) ?>]" aria-label="<?= t('Copy') ?>"><?= icon('copy') ?></button></dd></div>
      </dl>
    </section>

    <?php if ($v->services->authEnabled()): ?>
    <section class="card">
      <div class="card-header"><h2 class="card-title"><?= icon('briefcase', 'size-4 text-brand-600') ?><?= t('Project managers') ?></h2></div>
      <?php if ($managers === []): ?><p class="card-body text-sm text-slate-500"><?= t('No project managers.') ?></p><?php else: ?>
        <ul class="list">
          <?php foreach ($managers as $m): ?>
            <li class="row text-sm"><span class="avatar avatar-sm" style="--h: <?= avatar_hue($m) ?>"><?= e(initials($m)) ?></span><span class="min-w-0 flex-1 truncate font-medium"><?= e($m) ?></span>
              <?php if ($canManagers): ?><form method="post" action="<?= url('/access-paths/managers/remove') ?>"><?= csrf_field() ?><input type="hidden" name="path" value="<?= e($path) ?>"><input type="hidden" name="users[]" value="<?= e($m) ?>"><input type="hidden" name="_back" value="<?= e($v->request->path . '?path=' . rawurlencode($path)) ?>"><button class="btn-icon text-slate-400 hover:bg-rose-50 hover:text-rose-600" aria-label="<?= t('Remove') ?>"><?= icon('x') ?></button></form><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <?php $candidates = array_values(array_diff($allUsers, $managers)); if ($canManagers && $candidates !== []): ?>
        <form method="post" action="<?= url('/access-paths/managers') ?>" class="flex gap-2 border-t border-slate-100 px-5 py-4">
          <?= csrf_field() ?><input type="hidden" name="paths[]" value="<?= e($path) ?>"><input type="hidden" name="_back" value="<?= e($v->request->path . '?path=' . rawurlencode($path)) ?>">
          <label class="sr-only" for="pm"><?= t('User') ?></label>
          <select id="pm" name="users[]" class="input"><?php foreach ($candidates as $u): ?><option value="<?= e($u) ?>"><?= e($u) ?></option><?php endforeach; ?></select>
          <button class="btn btn-secondary shrink-0"><?= icon('plus') ?></button>
        </form>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($canDelete): ?>
    <form method="post" action="<?= url('/access-paths/delete') ?>" class="card border-rose-200" data-confirm="<?= t('Delete access path %0 and all its permissions?', $path) ?>" data-confirm-label="<?= t('Delete') ?>">
      <?= csrf_field() ?><input type="hidden" name="selected_paths[]" value="<?= e($path) ?>">
      <div class="card-body space-y-3"><h2 class="font-semibold text-rose-700"><?= t('Danger zone') ?></h2>
        <button class="btn btn-danger-soft w-full"><?= icon('trash-2') ?><?= t('Delete access path') ?></button></div>
    </form>
    <?php endif; ?>
  </aside>
</div>
