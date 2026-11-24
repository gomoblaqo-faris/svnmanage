<?php
/** @var SvnManage\View\View $v */
/** @var array<string, int|null> $stats */
use SvnManage\Acl\Acl;

$user = $v->auth->username;
$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$cards = [
    'repositories' => ['Repositories', 'database', 'bg-brand-50 text-brand-600', '/repositories'],
    'users' => ['Users', 'user', 'bg-coral-50 text-coral-600', '/users'],
    'groups' => ['Groups', 'users', 'bg-violet-50 text-violet-600', '/groups'],
    'paths' => ['Access-Paths', 'folder-tree', 'bg-emerald-50 text-emerald-600', '/access-paths'],
];
$quick = [];
if ($v->services->hasRepoEdit() && $v->can(Acl::MOD_REPO, Acl::ADD)) { $quick[] = ['/repositories/new', 'database', 'New repository', 'Create a repository with an optional trunk/branches/tags layout.']; }
if ($v->services->hasUserEdit() && $v->can(Acl::MOD_USER, Acl::ADD)) { $quick[] = ['/users/new', 'user-plus', 'New user', 'Add an account to the Subversion user file.']; }
if ($v->services->hasAccessPathEdit() && $v->can(Acl::MOD_ACCESSPATH, Acl::ASSIGN)) { $quick[] = ['/permissions', 'key-round', 'Grant permissions', 'Give users and groups access to paths in bulk.']; }
if ($v->services->hasGroupEdit() && $v->can(Acl::MOD_GROUP, Acl::ASSIGN)) { $quick[] = ['/groups/memberships', 'network', 'Manage memberships', 'Add users and sub-groups to groups.']; }
?>
<div class="mb-8">
  <p class="eyebrow mb-1.5"><?= e(date('l, j F Y')) ?></p>
  <h1 class="page-title"><?= t($greeting) ?><?= $user !== null ? ', <span class="text-brand-600">' . e($user) . '</span>' : '' ?></h1>
  <p class="mt-1.5 text-[15px] text-slate-500"><?= t('Here is what is happening in your Subversion server.') ?></p>
</div>

<?php foreach ($codeAlerts as $who => $n): ?>
  <div class="alert alert-error mb-3"><?= icon('shield-alert', 'mt-0.5 size-4 shrink-0') ?><span class="break-words">
    <strong><?= t('Wrong two-factor codes') ?>:</strong>
    <?= str_replace('{user}', '<a class="font-medium underline" href="' . url('/users/' . rawurlencode((string) $who)) . '">' . e((string) $who) . '</a>', t('%0 wrong codes for %1 in the last 24 hours. Their password is probably known to someone else.', $n, '{user}')) ?>
  </span></div>
<?php endforeach; ?>
<?php foreach ($exposure as [$message, $args]): ?>
  <div class="alert alert-error mb-3"><?= icon('shield-alert', 'mt-0.5 size-4 shrink-0') ?><span class="break-words"><?= t($message, tr($args[0]), ...array_slice($args, 1)) ?></span></div>
<?php endforeach; ?>
<?php foreach ($problems as $p): ?>
  <div class="alert alert-warning mb-3"><?= icon('triangle-alert', 'mt-0.5 size-4 shrink-0') ?><span class="break-words"><?= t($p) ?></span></div>
<?php endforeach; ?>

<?php if ($stats !== []): ?>
<section class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4" aria-label="<?= t('Statistics') ?>">
  <?php foreach ($cards as $key => [$label, $ic, $tone, $href]): if (!array_key_exists($key, $stats)) { continue; } ?>
    <a href="<?= url($href) ?>" class="stat group">
      <span class="stat-icon <?= $tone ?>"><?= icon($ic, 'size-5') ?></span>
      <span class="min-w-0">
        <span class="block text-2xl font-semibold tracking-tight text-slate-900 tabular-nums"><?= $stats[$key] === null ? '—' : (int) $stats[$key] ?></span>
        <span class="block text-[13px] leading-snug break-words text-slate-500 group-hover:text-brand-700"><?= t($label) ?></span>
      </span>
    </a>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<div class="mt-6 grid gap-6 lg:grid-cols-3 lg:items-start">
  <div class="space-y-6 lg:col-span-2">
    <?php if ($quick !== []): ?>
    <section class="card">
      <div class="card-header"><h2 class="card-title"><?= icon('sparkles', 'size-4 text-coral-500') ?><?= t('Quick actions') ?></h2></div>
      <div class="grid gap-px overflow-hidden rounded-b-2xl bg-slate-100 sm:grid-cols-2">
        <?php foreach ($quick as [$href, $ic, $label, $desc]): ?>
          <a href="<?= url($href) ?>" class="group flex items-start gap-3.5 bg-white p-4 transition hover:bg-brand-50/40 sm:p-5">
            <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-slate-600 transition group-hover:bg-brand-600 group-hover:text-white"><?= icon($ic, 'size-[18px]') ?></span>
            <span class="min-w-0">
              <span class="flex items-center gap-1 font-semibold text-slate-900"><?= t($label) ?><?= icon('arrow-right', 'size-3.5 -translate-x-1 opacity-0 transition group-hover:translate-x-0 group-hover:opacity-100') ?></span>
              <span class="mt-0.5 block text-[13px] text-slate-500"><?= t($desc) ?></span>
            </span>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($user !== null): ?>
    <section class="card">
      <div class="card-header">
        <h2 class="card-title"><?= icon('key-round', 'size-4 text-brand-600') ?><?= t('My repository access') ?></h2>
        <span class="badge badge-slate"><?= count($myPermissions) ?></span>
      </div>
      <?php if ($myPermissions === []): ?>
        <?php $emptyIcon = 'lock'; $emptyTitle = tr('No permissions yet'); $emptyText = tr('You have no access-path permissions in the authorization file.'); $emptyAction = null; include __DIR__ . '/partials/empty.php'; ?>
      <?php else: ?>
        <ul class="list max-h-80 overflow-y-auto">
          <?php foreach ($myPermissions as $p): ?>
            <li class="row">
              <?= icon('folder', 'size-4 shrink-0 text-slate-400') ?>
              <span class="min-w-0 flex-1">
                <span class="mono block truncate text-slate-800"><?= e($p['path']) ?></span>
                <span class="block text-xs text-slate-400"><?= $p['via'] === '' ? t('direct') : t('via %0', $p['via']) ?></span>
              </span>
              <?php $perm = $p['permission']; include __DIR__ . '/partials/perm.php'; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($isAdmin && $activity !== []): ?>
    <section class="card">
      <div class="card-header">
        <h2 class="card-title"><?= icon('history', 'size-4 text-brand-600') ?><?= t('Recent activity') ?></h2>
        <a href="<?= url('/activity') ?>" class="btn btn-sm btn-ghost"><?= t('View all') ?><?= icon('arrow-right', 'size-3.5') ?></a>
      </div>
      <ul class="list">
        <?php foreach ($activity as $a): ?>
          <li class="row text-sm">
            <span class="avatar avatar-sm" style="--h: <?= avatar_hue($a['user']) ?>"><?= e(initials($a['user'])) ?></span>
            <span class="min-w-0 flex-1 truncate"><span class="font-medium text-slate-900"><?= e($a['user']) ?></span> <span class="text-slate-500"><?= e($a['action']) ?></span> <span class="mono text-slate-700"><?= e($a['target']) ?></span></span>
            <time class="shrink-0 text-xs text-slate-400" datetime="<?= e($a['ts']) ?>"><?= e(ago(new DateTimeImmutable($a['ts']))) ?></time>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>
  </div>

  <aside class="space-y-6">
    <section class="card">
      <div class="card-header"><h2 class="card-title"><?= icon('user-cog', 'size-4 text-brand-600') ?><?= t('Your session') ?></h2></div>
      <dl class="card-body space-y-3 text-sm">
        <div class="flex justify-between gap-3"><dt class="text-slate-500"><?= t('Logged in as') ?></dt><dd class="font-medium text-slate-900"><?= $user !== null ? e($user) : t('Guest') ?></dd></div>
        <?php if ($v->services->authEnabled()): ?>
        <div>
          <dt class="mb-1.5 text-slate-500"><?= t('Roles of user') ?></dt>
          <dd class="flex flex-wrap gap-1.5">
            <?php foreach ($v->auth->roles as $r): ?><span class="badge badge-brand" title="<?= e(tr(SvnManage\Acl\Acl::description($r))) ?>"><?= t($r) ?></span><?php endforeach; ?>
            <?php if ($v->auth->isProjectManager()): ?><span class="badge badge-coral"><?= t('Project-Manager') ?></span><?php endif; ?>
            <?php if ($v->auth->roles === [] && !$v->auth->isProjectManager()): ?><span class="text-slate-400">—</span><?php endif; ?>
          </dd>
        </div>
        <?php endif; ?>
      </dl>
    </section>

    <section class="card">
      <div class="card-header"><h2 class="card-title"><?= icon('server', 'size-4 text-brand-600') ?><?= t('General information') ?></h2></div>
      <dl class="card-body space-y-2.5 text-sm">
        <div class="flex justify-between gap-3"><dt class="text-slate-500"><?= t('Application version') ?></dt><dd class="font-mono text-[13px] text-slate-800"><?= e($appVersion) ?></dd></div>
        <div class="flex justify-between gap-3"><dt class="text-slate-500"><?= t('PHP version') ?></dt><dd class="font-mono text-[13px] text-slate-800"><?= e($phpVersion) ?></dd></div>
        <?php if (!empty($svnVersion)): ?>
          <div class="flex justify-between gap-3"><dt class="text-slate-500"><?= t('Subversion version') ?></dt><dd class="font-mono text-[13px] text-slate-800"><?= e($svnVersion) ?></dd></div>
        <?php endif; ?>
        <?php if ($isAdmin): ?>
          <div class="border-t border-slate-100 pt-2.5"></div>
          <?php foreach ($providers as [$label, $value]): ?>
            <div class="flex justify-between gap-3"><dt class="text-slate-500"><?= t($label) ?></dt><dd class="text-right font-mono text-[12px] text-slate-700"><?= e($value) ?></dd></div>
          <?php endforeach; ?>
        <?php endif; ?>
      </dl>
    </section>
  </aside>
</div>
