<?php
/** @var list<SvnManage\Users\User> $users @var ?SvnManage\Acl\RoleAssignments $roles @var ?SvnManage\Authz\AuthzFile $authz */
$heading = t('Users');
$sub = t('%0 accounts from the %1 user provider.', $total, $v->services->type('UserViewProviderType'));
$actions = $canAdd ? '<a href="' . url('/users/new') . '" class="btn btn-primary">' . icon('user-plus') . t('New user') . '</a>' : '';
include __DIR__ . '/../partials/header.php';
$bulk = $canDelete || ($canAssignRole && $assignableRoles !== []);
?>
<form method="get" action="<?= url('/users') ?>" class="mb-5" hx-get="<?= url('/users') ?>" hx-trigger="input changed delay:300ms from:find input, search from:find input" hx-select="#user-list" hx-target="#user-list" hx-swap="outerHTML" hx-push-url="true">
  <div class="search max-w-md">
    <?= icon('search') ?>
    <input type="search" name="q" value="<?= e($q) ?>" class="input" placeholder="<?= t('Search users…') ?>" data-hotkey-search aria-label="<?= t('Search') ?>">
  </div>
</form>

<form id="user-list" method="post" action="<?= url('/users/delete') ?>" class="card" data-bulk>
  <?= csrf_field() ?>
  <?php if ($users === []): ?>
    <?php $emptyIcon = 'users'; $emptyTitle = $q !== '' ? tr('No matches.') : tr('No users yet'); $emptyText = null; $emptyAction = $canAdd && $q === '' ? '<a class="btn btn-secondary btn-sm" href="' . url('/users/new') . '">' . icon('user-plus', 'size-3.5') . t('New user') . '</a>' : null; include __DIR__ . '/../partials/empty.php'; ?>
  <?php else: ?>
    <?php if ($bulk): ?>
      <div class="card-header py-3">
        <label class="flex items-center gap-2 text-[13px] text-slate-500"><input type="checkbox" class="checkbox" data-bulk-all><?= t('Select all') ?></label>
        <span class="text-xs text-slate-400"><?= t('%0 shown', count($users)) ?></span>
      </div>
    <?php endif; ?>
    <ul class="list">
      <?php foreach ($users as $user):
          $userRoles = $roles?->rolesOf($user->name) ?? [];
          $isPm = $roles?->isProjectManager($user->name) ?? false;
          $groupCount = $authz !== null ? count($authz->groupsOfUser($user->name)) : 0; ?>
        <li class="row row-link group relative">
          <?php if ($bulk): ?><input type="checkbox" class="checkbox relative z-10" name="selected_users[]" value="<?= e($user->name) ?>" data-bulk-item aria-label="<?= t('Select') ?> <?= e($user->name) ?>"><?php endif; ?>
          <span class="avatar" style="--h: <?= avatar_hue($user->name) ?>"><?= e(initials($user->name)) ?></span>
          <a href="<?= url('/users/' . rawurlencode($user->name)) ?>" class="min-w-0 flex-1 before:absolute before:inset-0">
            <span class="block truncate font-medium text-slate-900 group-hover:text-brand-700"><?= e($user->name) ?><?= $user->name === $v->auth->username ? ' <span class="text-xs font-normal text-slate-400">(' . t('you') . ')</span>' : '' ?></span>
            <?php if ($user->displayName): ?><span class="block truncate text-xs text-slate-500"><?= e($user->displayName) ?></span><?php endif; ?>
          </a>
          <span class="hidden flex-wrap justify-end gap-1 md:flex">
            <?php foreach ($userRoles as $r): ?><span class="badge <?= $r === 'Administrator' ? 'badge-coral' : 'badge-brand' ?>"><?= t($r) ?></span><?php endforeach; ?>
            <?php if ($isPm): ?><span class="badge badge-slate"><?= t('Project-Manager') ?></span><?php endif; ?>
          </span>
          <?php if ($groupCount > 0): ?><span class="hidden items-center gap-1 text-xs text-slate-400 sm:flex" title="<?= t('Groups') ?>"><?= icon('users', 'size-3.5') ?><?= $groupCount ?></span><?php endif; ?>
          <?= icon('chevron-right', 'size-4 text-slate-300') ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php $pagerPath = '/users'; $pagerQuery = ['q' => $q]; include __DIR__ . '/../partials/pager.php'; ?>
    <?php if ($bulk): ?>
      <div data-bulk-bar hidden class="sticky bottom-3 m-3 flex flex-wrap items-center gap-2 rounded-xl bg-slate-900 px-4 py-3 text-sm text-white shadow-lift">
        <span class="mr-auto"><span data-bulk-count>0</span> <?= t('selected') ?></span>
        <?php if ($canAssignRole && $assignableRoles !== []): ?>
          <label class="sr-only" for="bulk-role"><?= t('Role') ?></label>
          <select id="bulk-role" name="role" class="input h-9 w-auto border-0 bg-white/10 py-1 text-sm text-white">
            <?php foreach ($assignableRoles as $r): ?><option value="<?= e($r) ?>" class="text-slate-900"><?= t($r) ?></option><?php endforeach; ?>
          </select>
          <button class="btn btn-sm bg-white text-slate-900 hover:bg-slate-100" formaction="<?= url('/users/roles') ?>" data-bulk-requires><?= icon('shield-check', 'size-3.5') ?><?= t('Assign role') ?></button>
        <?php endif; ?>
        <?php if ($canDelete): ?>
          <button class="btn btn-danger btn-sm" data-bulk-requires data-confirm="<?= t('Delete the selected users? Their group memberships, permissions and roles are removed too.') ?>" data-confirm-label="<?= t('Delete') ?>"><?= icon('trash-2', 'size-3.5') ?><?= t('Delete') ?></button>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</form>
