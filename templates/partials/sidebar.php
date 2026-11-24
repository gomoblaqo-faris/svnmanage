<?php
/** @var SvnManage\View\View $v */
/** @var string $nav */
use SvnManage\Acl\Acl;

$s = $v->services;
$cur = static fn (string ...$keys): string => in_array($nav, $keys, true) ? ' aria-current="page"' : '';
$repoNav = $s->hasRepoView() && ($v->can(Acl::MOD_REPO, Acl::VIEW) || $v->can(Acl::MOD_REPO, Acl::ADD));
$userNav = $s->hasUserView() && ($v->can(Acl::MOD_USER, Acl::VIEW) || $v->can(Acl::MOD_USER, Acl::ADD));
$groupNav = $s->hasGroupView() && ($v->can(Acl::MOD_GROUP, Acl::VIEW) || $v->can(Acl::MOD_GROUP, Acl::ADD) || $v->can(Acl::MOD_GROUP, Acl::ASSIGN));
$pathNav = $s->hasAccessPathView() && ($v->can(Acl::MOD_ACCESSPATH, Acl::VIEW) || $v->can(Acl::MOD_ACCESSPATH, Acl::ADD) || $v->can(Acl::MOD_ACCESSPATH, Acl::ASSIGN));
$roleNav = $s->authEnabled() && $v->can(Acl::MOD_ROLE, Acl::VIEW);
$syncNav = $s->isUpdateable() && $v->can(Acl::MOD_UPDATE, Acl::SYNCHRONIZE) && $s->config->bool('GUI', 'AllowUpdateByGui', true);
$adminNav = $v->can(Acl::MOD_SETTINGS, Acl::CHANGE);
$user = $v->auth->username;
?>
<div id="sidebar-backdrop" class="fixed inset-0 z-40 hidden bg-slate-900/30 backdrop-blur-[2px] lg:hidden" aria-hidden="true"></div>
<aside id="sidebar" data-open="false" aria-label="<?= t('Main navigation') ?>"
       class="fixed inset-y-0 left-0 z-50 flex w-72 -translate-x-full flex-col border-r border-slate-200/80 bg-white transition-transform duration-200 ease-out lg:translate-x-0">
  <div class="flex h-16 shrink-0 items-center justify-between px-5">
    <a href="<?= url('/') ?>" class="rounded-lg"><?php include __DIR__ . '/logo.php'; ?></a>
    <button type="button" class="btn-icon lg:hidden" data-sidebar-close aria-label="<?= t('Close menu') ?>"><?= icon('x', 'size-5') ?></button>
  </div>

  <nav class="flex-1 overflow-y-auto px-3 pb-4">
    <a href="<?= url('/') ?>" class="nav-link"<?= $cur('dashboard') ?>><?= icon('layout-dashboard', 'size-[18px]') ?><?= t('Dashboard') ?></a>

    <?php if ($repoNav || $pathNav): ?>
      <p class="nav-section"><?= t('Subversion') ?></p>
      <?php if ($repoNav): ?>
        <a href="<?= url('/repositories') ?>" class="nav-link"<?= $cur('repositories') ?>><?= icon('database', 'size-[18px]') ?><?= t('Repositories') ?></a>
        <?php if ($s->hasRepoEdit() && $v->can(Acl::MOD_REPO, Acl::ADD)): ?>
          <a href="<?= url('/repositories/new') ?>" class="nav-sub"<?= $cur('repositories.new') ?>><?= icon('plus', 'size-3.5') ?><?= t('New repository') ?></a>
        <?php endif; ?>
      <?php endif; ?>
      <?php if ($pathNav): ?>
        <a href="<?= url('/access-paths') ?>" class="nav-link"<?= $cur('access-paths') ?>><?= icon('folder-tree', 'size-[18px]') ?><?= t('Access-Paths') ?></a>
        <?php if ($s->hasAccessPathEdit() && $v->can(Acl::MOD_ACCESSPATH, Acl::ADD)): ?>
          <a href="<?= url('/access-paths/new') ?>" class="nav-sub"<?= $cur('access-paths.new') ?>><?= icon('plus', 'size-3.5') ?><?= t('New access path') ?></a>
        <?php endif; ?>
        <?php if ($s->hasAccessPathEdit() && $v->can(Acl::MOD_ACCESSPATH, Acl::ASSIGN)): ?>
          <a href="<?= url('/permissions') ?>" class="nav-sub"<?= $cur('permissions') ?>><?= icon('key-round', 'size-3.5') ?><?= t('Permissions') ?></a>
        <?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($userNav || $groupNav || $roleNav): ?>
      <p class="nav-section"><?= t('People') ?></p>
      <?php if ($userNav): ?>
        <a href="<?= url('/users') ?>" class="nav-link"<?= $cur('users') ?>><?= icon('user', 'size-[18px]') ?><?= t('Users') ?></a>
        <?php if ($s->hasUserEdit() && $v->can(Acl::MOD_USER, Acl::ADD)): ?>
          <a href="<?= url('/users/new') ?>" class="nav-sub"<?= $cur('users.new') ?>><?= icon('plus', 'size-3.5') ?><?= t('New user') ?></a>
        <?php endif; ?>
      <?php endif; ?>
      <?php if ($groupNav): ?>
        <a href="<?= url('/groups') ?>" class="nav-link"<?= $cur('groups') ?>><?= icon('users', 'size-[18px]') ?><?= t('Groups') ?></a>
        <?php if ($s->hasGroupEdit() && $v->can(Acl::MOD_GROUP, Acl::ADD)): ?>
          <a href="<?= url('/groups/new') ?>" class="nav-sub"<?= $cur('groups.new') ?>><?= icon('plus', 'size-3.5') ?><?= t('New group') ?></a>
        <?php endif; ?>
        <?php if ($s->hasGroupEdit() && $v->can(Acl::MOD_GROUP, Acl::ASSIGN)): ?>
          <a href="<?= url('/groups/memberships') ?>" class="nav-sub"<?= $cur('memberships') ?>><?= icon('network', 'size-3.5') ?><?= t('Memberships') ?></a>
        <?php endif; ?>
      <?php endif; ?>
      <?php if ($roleNav): ?>
        <a href="<?= url('/roles') ?>" class="nav-link"<?= $cur('roles') ?>><?= icon('shield-check', 'size-[18px]') ?><?= t('Roles') ?></a>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($syncNav || $adminNav): ?>
      <p class="nav-section"><?= t('System') ?></p>
      <?php if ($syncNav): ?>
        <a href="<?= url('/sync') ?>" class="nav-link"<?= $cur('sync') ?>><?= icon('refresh-cw', 'size-[18px]') ?><?= t('Synchronize') ?></a>
      <?php endif; ?>
      <?php if ($adminNav): ?>
        <a href="<?= url('/settings') ?>" class="nav-link"<?= $cur('settings') ?>><?= icon('settings', 'size-[18px]') ?><?= t('Settings') ?></a>
        <a href="<?= url('/activity') ?>" class="nav-link"<?= $cur('activity') ?>><?= icon('history', 'size-[18px]') ?><?= t('Activity log') ?></a>
        <a href="<?= url('/backups') ?>" class="nav-link"<?= $cur('backups') ?>><?= icon('archive', 'size-[18px]') ?><?= t('Backups') ?></a>
      <?php endif; ?>
    <?php endif; ?>
  </nav>

  <div class="shrink-0 border-t border-slate-100 p-3">
    <?php if ($user !== null): ?>
      <div class="flex items-center gap-3 rounded-xl p-2">
        <span class="avatar" style="--h: <?= avatar_hue($user) ?>"><?= e(initials($user)) ?></span>
        <div class="min-w-0 flex-1">
          <p class="truncate text-sm font-semibold text-slate-900"><?= e($user) ?></p>
          <p class="truncate text-xs text-slate-500"><?= e($v->auth->roles === [] ? ($v->auth->isProjectManager() ? tr('Project-Manager') : tr('User')) : implode(', ', array_map(fn ($r) => tr($r), $v->auth->roles))) ?></p>
        </div>
        <form method="post" action="<?= url('/logout') ?>" hx-boost="false">
          <?= csrf_field() ?>
          <button class="btn-icon" title="<?= t('Logout') ?>" aria-label="<?= t('Logout') ?>"><?= icon('log-out', 'size-[18px]') ?></button>
        </form>
      </div>
      <?php if ($s->hasUserEdit() && $v->can(Acl::MOD_USER, Acl::CHANGEPASS)): ?>
        <a href="<?= url('/account/password') ?>" class="nav-link mt-1 text-[13px]"<?= $cur('account') ?>><?= icon('key-round', 'size-4') ?><?= t('Change password') ?></a>
      <?php endif; ?>
      <?php if ($s->twoFactorPolicy() !== 'off'): ?>
        <a href="<?= url('/account/two-factor') ?>" class="nav-link text-[13px]"<?= $cur('account.2fa') ?>><?= icon('shield-check', 'size-4') ?><?= t('Two-factor authentication') ?></a>
      <?php endif; ?>
    <?php endif; ?>
    <?php include __DIR__ . '/locale.php'; ?>
  </div>
</aside>
