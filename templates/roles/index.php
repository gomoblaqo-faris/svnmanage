<?php /** @var list<array{name: string, description: string, parents: list<string>, capabilities: array|string, members: list<string>}> $roles @var array<string, list<string>> $managers */
use SvnManage\Acl\Acl;

$heading = t('Roles');
$sub = t('Web application roles decide what a user can do in svnmanage. They do not affect Subversion access.');
include __DIR__ . '/../partials/header.php';
$moduleLabels = ['basics' => tr('Basics'), 'repositories' => tr('Repositories'), 'users' => tr('Users'), 'groups' => tr('Groups'), 'accesspaths' => tr('Access-Paths'), 'roles' => tr('Roles'), 'settings' => tr('Settings'), 'updates' => tr('Synchronization'), 'projectmanagers' => tr('Project managers')];
$actionLabels = ['view' => tr('View'), 'add' => tr('Add'), 'delete' => tr('Delete'), 'assign' => tr('Assign'), 'unassign' => tr('Unassign'), 'login' => tr('Log in'), 'changepass' => tr('Change own password'), 'changepassother' => tr('Change other passwords'), 'synchronize' => tr('Synchronize'), 'change' => tr('Change'), 'dump' => tr('Dump & load'), 'assignadmin' => tr('Grant Administrator'), 'unassignadmin' => tr('Revoke Administrator')];
$actionIcons = ['view' => 'eye', 'add' => 'plus', 'delete' => 'trash-2', 'assign' => 'link', 'unassign' => 'unlink', 'login' => 'log-in', 'changepass' => 'key-round', 'changepassother' => 'key-round', 'synchronize' => 'refresh-cw', 'change' => 'settings', 'dump' => 'download', 'assignadmin' => 'crown', 'unassignadmin' => 'crown'];
?>
<?php if ($canAssign && $users !== [] && $assignable !== []): ?>
<form method="post" action="<?= url('/users/roles') ?>" class="card mb-6" data-bulk>
  <?= csrf_field() ?><input type="hidden" name="_back" value="/roles">
  <div class="card-body grid gap-4 md:grid-cols-[1fr_16rem_auto] md:items-end">
    <?php $pickName = 'selected_users[]'; $pickType = 'checkbox'; $pickLabel = tr('Assign role to users'); $pickItems = array_map(static fn ($u) => ['value' => $u, 'label' => $u, 'icon' => 'user'], $users); include __DIR__ . '/../partials/picker.php'; ?>
    <div><label class="label" for="role-select"><?= t('Role') ?></label>
      <select id="role-select" name="role" class="input"><?php foreach ($assignable as $r): ?><option value="<?= e($r) ?>"><?= t($r) ?></option><?php endforeach; ?></select></div>
    <button class="btn btn-primary"><?= icon('shield-check') ?><?= t('Assign') ?></button>
  </div>
</form>
<?php endif; ?>

<div class="grid gap-4 md:grid-cols-2">
  <?php foreach ($roles as $role): $isAdmin = $role['name'] === Acl::ADMINISTRATOR; ?>
    <section class="card flex flex-col">
      <div class="card-header items-start">
        <div class="flex items-start gap-3">
          <span class="flex size-10 shrink-0 items-center justify-center rounded-xl <?= $isAdmin ? 'bg-coral-50 text-coral-600' : 'bg-brand-50 text-brand-600' ?>"><?= icon($isAdmin ? 'crown' : 'shield-check', 'size-5') ?></span>
          <div><h2 class="font-semibold text-slate-900"><?= t($role['name']) ?></h2><p class="mt-0.5 text-[13px] text-slate-500"><?= t($role['description']) ?></p>
            <?php if ($role['parents'] !== []): ?><p class="mt-1 text-xs text-slate-400"><?= t('Inherits') ?>: <?= e(implode(', ', array_map(static fn ($p) => tr($p), $role['parents']))) ?></p><?php endif; ?></div>
        </div>
        <span class="badge badge-slate shrink-0"><?= count($role['members']) ?></span>
      </div>
      <div class="card-body flex-1 space-y-4">
        <?php if ($role['capabilities'] === '*'): ?>
          <p class="chip bg-coral-50 text-coral-700"><?= icon('infinity', 'size-3.5') ?><?= t('Full access to every module') ?></p>
        <?php else: ?>
          <dl class="space-y-1.5 text-[13px]">
            <?php foreach ($role['capabilities'] as $module => $actions): ?>
              <div class="flex flex-wrap items-center gap-1.5"><dt class="w-28 shrink-0 font-medium text-slate-600"><?= e($moduleLabels[$module] ?? ucfirst($module)) ?></dt>
                <?php foreach ($actions as $a): ?><dd class="chip bg-slate-100 py-0.5 text-[11px] text-slate-600"><?= icon($actionIcons[$a] ?? 'check', 'size-3') ?><?= e($actionLabels[$a] ?? $a) ?></dd><?php endforeach; ?></div>
            <?php endforeach; ?>
          </dl>
        <?php endif; ?>
        <?php if ($role['members'] !== []): ?>
          <div class="flex flex-wrap gap-1.5 border-t border-slate-100 pt-3">
            <?php foreach ($role['members'] as $m): ?><a href="<?= url('/users/' . rawurlencode($m)) ?>" class="chip min-h-10 bg-white ring-1 ring-slate-200 hover:ring-brand-300 lg:min-h-0"><span class="avatar size-5 text-[9px]" style="--h: <?= avatar_hue($m) ?>"><?= e(initials($m)) ?></span><?= e($m) ?></a><?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </section>
  <?php endforeach; ?>

  <section class="card md:col-span-2">
    <div class="card-header"><div class="flex items-start gap-3"><span class="flex size-10 items-center justify-center rounded-xl bg-slate-100 text-slate-600"><?= icon('briefcase', 'size-5') ?></span>
      <div><h2 class="font-semibold text-slate-900"><?= t('Project-Manager') ?></h2><p class="mt-0.5 text-[13px] text-slate-500"><?= t('Implicit role: users assigned to access paths can manage permissions below those paths only.') ?></p></div></div>
      <span class="badge badge-slate"><?= count($managers) ?></span></div>
    <?php if ($managers === []): ?><p class="card-body text-sm text-slate-500"><?= t('No project managers. Assign them on the access paths page.') ?></p><?php else: ?>
      <ul class="list">
        <?php foreach ($managers as $user => $paths): ?>
          <li class="row text-sm"><span class="avatar avatar-sm" style="--h: <?= avatar_hue($user) ?>"><?= e(initials($user)) ?></span><a href="<?= url('/users/' . rawurlencode($user)) ?>" class="tap-y w-32 shrink-0 truncate font-medium hover:text-brand-700"><?= e($user) ?></a>
            <span class="flex min-w-0 flex-1 flex-wrap gap-1"><?php foreach ($paths as $p): ?><a href="<?= url('/access-paths/view', ['path' => $p]) ?>" class="chip mono tap-area bg-slate-100 text-[11px] hover:bg-brand-50"><?= e($p) ?></a><?php endforeach; ?></span></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>
