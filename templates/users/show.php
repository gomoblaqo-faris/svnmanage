<?php
/**
 * @var SvnManage\Users\User $user
 * @var array<string, ?string> $groups
 * @var list<array{path: string, permission: SvnManage\Authz\Permission, via: string}> $permissions
 * @var list<string> $userRoles @var list<string> $managed @var list<string> $allGroups @var list<string> $assignableRoles
 */
use SvnManage\Acl\Acl;

$base = '/users/' . rawurlencode($user->name);
$heading = '<span class="flex items-center gap-3"><span class="avatar size-11 text-base" style="--h: ' . avatar_hue($user->name) . '">' . e(initials($user->name)) . '</span>' . e($user->name) . '</span>';
$sub = $user->displayName ? e($user->displayName) : null;
if ($twoFactor) {
    $sub = ($sub === null ? '' : $sub . ' · ') . '<span class="badge badge-rw align-middle">' . icon('shield-check', 'size-3') . t('Two-factor on') . '</span>';
}
$crumbs = $isSelf ? [] : [tr('Users') => url('/users')];
$actions = '';
if ($canPassword) { $actions .= '<a href="' . url($isSelf ? '/account/password' : $base . '/password') . '" class="btn btn-secondary">' . icon('key-round') . t('Change password') . '</a>'; }
if ($isSelf) { $actions .= '<a href="' . url('/account/two-factor') . '" class="btn btn-secondary">' . icon('shield-check') . t('Two-factor') . '</a>'; }
if ($twoFactor && $canResetTwoFactor) {
    $actions .= '<form method="post" action="' . url($base . '/two-factor/reset') . '" class="contents" data-confirm="' . t('Reset two-factor authentication for %0? Use this when they lost their phone.', $user->name) . '" data-confirm-label="' . t('Reset') . '">'
        . csrf_field() . '<button class="btn btn-secondary">' . icon('shield-off') . t('Reset two-factor') . '</button></form>';
}
include __DIR__ . '/../partials/header.php';
$directGroups = array_keys(array_filter($groups, static fn ($via) => $via === null));
$addable = array_values(array_diff($allGroups, $directGroups));
$roleAddable = array_values(array_diff($assignableRoles, $userRoles));
?>
<?php if (!$exists): ?>
  <div class="alert alert-warning mb-6"><?= icon('triangle-alert', 'mt-0.5 size-4 shrink-0') ?><span><?= t('This user is referenced in the authorization file but does not exist in the user provider.') ?></span></div>
<?php endif; ?>

<div class="grid gap-6 lg:grid-cols-2 lg:items-start">
  <section class="card">
    <div class="card-header"><h2 class="card-title"><?= icon('users', 'size-4 text-brand-600') ?><?= t('Groups') ?> <span class="badge badge-slate"><?= count($groups) ?></span></h2></div>
    <?php if ($groups === []): ?>
      <p class="card-body text-sm text-slate-500"><?= t('Not a member of any group.') ?></p>
    <?php else: ?>
      <ul class="list">
        <?php foreach ($groups as $g => $via): ?>
          <li class="row relative text-sm">
            <span class="flex size-8 items-center justify-center rounded-lg bg-violet-50 text-violet-600"><?= icon('users', 'size-4') ?></span>
            <a href="<?= url('/groups/' . rawurlencode($g)) ?>" class="tap-y min-w-0 flex-1 truncate font-medium text-slate-800 hover:text-brand-700">@<?= e($g) ?></a>
            <?php if ($via !== null): ?>
              <span class="text-xs text-slate-400"><?= t('via %0', '@' . $via) ?></span>
            <?php elseif ($canUngroup): ?>
              <?php $rmAction = $base . '/groups/remove'; $rmField = 'groups[]'; $rmValue = $g; $rmLabel = tr('Remove from group'); include __DIR__ . '/../partials/remove-btn.php'; ?>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <?php if ($canGroups && $addable !== []): ?>
      <details class="border-t border-slate-100">
        <summary class="cursor-pointer list-none px-5 py-3 text-sm font-medium text-brand-700 hover:bg-slate-50 [&::-webkit-details-marker]:hidden"><?= icon('plus', 'mr-1 inline size-4') ?><?= t('Add to groups') ?></summary>
        <form method="post" action="<?= url($base . '/groups') ?>" class="space-y-3 px-5 pb-5">
          <?= csrf_field() ?>
          <?php $pickName = 'groups[]'; $pickType = 'checkbox'; $pickLabel = tr('Groups'); $pickItems = array_map(static fn ($g) => ['value' => $g, 'label' => $g, 'icon' => 'users'], $addable); include __DIR__ . '/../partials/picker.php'; ?>
          <button class="btn btn-primary btn-sm"><?= icon('plus', 'size-3.5') ?><?= t('Add') ?></button>
        </form>
      </details>
    <?php endif; ?>
  </section>

  <?php if ($v->services->authEnabled()): ?>
  <section class="card">
    <div class="card-header"><h2 class="card-title"><?= icon('shield-check', 'size-4 text-brand-600') ?><?= t('Roles') ?></h2></div>
    <?php if ($userRoles === [] && $managed === []): ?>
      <p class="card-body text-sm text-slate-500"><?= t('No roles assigned. The user cannot sign in to svnmanage.') ?></p>
    <?php else: ?>
      <ul class="list">
        <?php foreach ($userRoles as $r): $removable = $canUnrole && ($r !== Acl::ADMINISTRATOR || $v->can(Acl::MOD_ROLE, Acl::UNASSIGN_ADMIN)); ?>
          <li class="row text-sm">
            <span class="flex size-8 items-center justify-center rounded-lg <?= $r === Acl::ADMINISTRATOR ? 'bg-coral-50 text-coral-600' : 'bg-brand-50 text-brand-600' ?>"><?= icon($r === Acl::ADMINISTRATOR ? 'crown' : 'shield-check', 'size-4') ?></span>
            <span class="min-w-0 flex-1"><span class="block font-medium text-slate-800"><?= t($r) ?></span><span class="block truncate text-xs text-slate-500"><?= t(Acl::description($r)) ?></span></span>
            <?php if ($removable): $rmAction = $base . '/roles/remove'; $rmField = 'roles[]'; $rmValue = $r; $rmLabel = tr('Remove role'); include __DIR__ . '/../partials/remove-btn.php'; endif; ?>
          </li>
        <?php endforeach; ?>
        <?php if ($managed !== []): ?>
          <li class="row text-sm"><span class="flex size-8 items-center justify-center rounded-lg bg-slate-100 text-slate-600"><?= icon('briefcase', 'size-4') ?></span><span class="min-w-0 flex-1"><span class="block font-medium text-slate-800"><?= t('Project-Manager') ?></span><span class="block text-xs text-slate-500"><?= t('Manages %0 access paths.', count($managed)) ?></span></span></li>
        <?php endif; ?>
      </ul>
    <?php endif; ?>
    <?php if ($canRoles && $roleAddable !== []): ?>
      <form method="post" action="<?= url($base . '/roles') ?>" class="flex gap-2 border-t border-slate-100 px-5 py-4">
        <?= csrf_field() ?>
        <label class="sr-only" for="add-role"><?= t('Role') ?></label>
        <select id="add-role" name="roles[]" class="input">
          <?php foreach ($roleAddable as $r): ?><option value="<?= e($r) ?>"><?= t($r) ?></option><?php endforeach; ?>
        </select>
        <button class="btn btn-secondary shrink-0"><?= icon('plus') ?><?= t('Assign') ?></button>
      </form>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <section class="card lg:col-span-2">
    <div class="card-header"><h2 class="card-title"><?= icon('key-round', 'size-4 text-brand-600') ?><?= t('Effective permissions') ?> <span class="badge badge-slate"><?= count($permissions) ?></span></h2></div>
    <?php if ($permissions === []): ?>
      <p class="card-body text-sm text-slate-500"><?= t('No permissions.') ?></p>
    <?php else: ?>
      <ul class="list">
        <?php foreach ($permissions as $p): ?>
          <li class="row text-sm">
            <?= icon('folder', 'size-4 shrink-0 text-slate-400') ?>
            <span class="min-w-0 flex-1">
              <a href="<?= url('/access-paths/view', ['path' => $p['path']]) ?>" class="tap-y mono block truncate text-slate-800 hover:text-brand-700"><?= e($p['path']) ?></a>
              <span class="block text-xs text-slate-400"><?= $p['via'] === '' ? t('direct') : t('via %0', $p['via']) ?></span>
            </span>
            <?php $perm = $p['permission']; include __DIR__ . '/../partials/perm.php'; ?>
            <?php if ($canRevoke && $p['via'] === ''): $rmAction = $base . '/permissions/remove'; $rmField = 'paths[]'; $rmValue = $p['path']; $rmLabel = tr('Revoke'); include __DIR__ . '/../partials/remove-btn.php'; else: ?><span class="w-9"></span><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <?php if ($managed !== []): ?>
  <section class="card">
    <div class="card-header"><h2 class="card-title"><?= icon('briefcase', 'size-4 text-brand-600') ?><?= t('Managed access paths') ?></h2></div>
    <ul class="list">
      <?php foreach ($managed as $m): ?>
        <li class="row text-sm"><?= icon('folder-tree', 'size-4 text-slate-400') ?><span class="mono min-w-0 flex-1 truncate"><?= e($m) ?></span>
          <?php if ($canRevoke && !$v->auth->isPathScoped()): $rmAction = $base . '/managed/remove'; $rmField = 'paths[]'; $rmValue = $m; $rmLabel = tr('Remove'); include __DIR__ . '/../partials/remove-btn.php'; endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>

  <?php if ($canDelete): ?>
  <form method="post" action="<?= url('/users/delete') ?>" class="card border-rose-200 <?= $managed === [] ? 'lg:col-span-2' : '' ?>" data-confirm="<?= t('Delete user %0? Group memberships, permissions and roles are removed too.', $user->name) ?>" data-confirm-label="<?= t('Delete') ?>">
    <?= csrf_field() ?><input type="hidden" name="selected_users[]" value="<?= e($user->name) ?>">
    <div class="card-body flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <div><h2 class="font-semibold text-rose-700"><?= t('Danger zone') ?></h2><p class="text-sm text-slate-500"><?= t('Removes the account and every reference to it.') ?></p></div>
      <button class="btn btn-danger-soft"><?= icon('trash-2') ?><?= t('Delete user') ?></button>
    </div>
  </form>
  <?php endif; ?>
</div>
