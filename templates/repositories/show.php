<?php
/** @var SvnManage\Svn\Repository $repo @var SvnManage\Svn\RepositoryParent $parent @var ?SvnManage\Svn\RepositoryInfo $info @var ?list<SvnManage\Svn\LogEntry> $log */
$base = '/repositories/' . $repo->parentId . '/' . rawurlencode($repo->name);
$heading = e($repo->name);
$crumbs = [tr('Repositories') => url('/repositories'), $parent->description => url('/repositories')];
$actions = '<a href="' . url($base . '/browse') . '" class="btn btn-primary">' . icon('folder-open') . t('Browse') . '</a>';
if ($canDump) {
    $actions .= '<form method="post" action="' . url($base . '/dump') . '" hx-boost="false" class="contents">' . csrf_field()
        . '<button class="btn btn-secondary">' . icon('download') . t('Dump') . '</button></form>';
}
include __DIR__ . '/../partials/header.php';
$fileUrl = 'file://' . (str_starts_with($repo->path, '/') ? '' : '/') . str_replace('\\', '/', $repo->path);
?>
<?php if ($error !== null): ?>
  <div class="alert alert-error mb-6"><?= icon('circle-x', 'mt-0.5 size-4 shrink-0') ?><span class="break-words"><?= t($error) ?></span></div>
<?php endif; ?>

<?php if ($info !== null): ?>
<section class="mb-6 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
  <div class="stat"><span class="stat-icon bg-brand-50 text-brand-600"><?= icon('git-commit-horizontal', 'size-5') ?></span><span><span class="block text-2xl font-semibold tabular-nums text-slate-900"><?= (int) $info->youngest ?></span><span class="text-[13px] text-slate-500"><?= t('Revision') ?></span></span></div>
  <div class="stat"><span class="stat-icon bg-emerald-50 text-emerald-600"><?= icon('hard-drive', 'size-5') ?></span><span><span class="block text-2xl font-semibold tabular-nums text-slate-900"><?= e(human_bytes($info->sizeBytes)) ?></span><span class="text-[13px] text-slate-500"><?= t('Size on disk') ?></span></span></div>
  <div class="stat"><span class="stat-icon bg-coral-50 text-coral-600"><?= icon('clock', 'size-5') ?></span><span class="min-w-0"><span class="block truncate text-lg font-semibold text-slate-900"><?= e(ago($info->lastChanged)) ?></span><span class="text-[13px] text-slate-500"><?= t('Last commit') ?></span></span></div>
  <div class="stat"><span class="stat-icon bg-violet-50 text-violet-600"><?= icon('user', 'size-5') ?></span><span class="min-w-0"><span class="block truncate text-lg font-semibold text-slate-900"><?= e($info->lastAuthor !== '' ? $info->lastAuthor : '—') ?></span><span class="text-[13px] text-slate-500"><?= t('Last author') ?></span></span></div>
</section>
<?php endif; ?>

<div class="grid gap-6 lg:grid-cols-3 lg:items-start">
  <?php if ($log !== null): /* null: path-scoped manager, the log would reveal other folders */ ?>
  <section class="card lg:col-span-2">
    <div class="card-header"><h2 class="card-title"><?= icon('history', 'size-4 text-brand-600') ?><?= t('Recent commits') ?></h2></div>
    <?php if ($log === []): ?>
      <?php $emptyIcon = 'git-commit-horizontal'; $emptyTitle = tr('No commits yet'); $emptyText = null; $emptyAction = null; include __DIR__ . '/../partials/empty.php'; ?>
    <?php else: ?>
      <ol class="list">
        <?php foreach ($log as $entry): ?>
          <li class="flex gap-3 px-4 py-3.5 sm:px-5">
            <span class="mt-0.5 h-fit shrink-0 rounded-md bg-slate-100 px-1.5 py-0.5 font-mono text-xs font-semibold text-slate-600">r<?= (int) $entry->revision ?></span>
            <div class="min-w-0 flex-1">
              <p class="break-words text-sm text-slate-800"><?= $entry->message !== '' ? nl2br(e(trim($entry->message))) : '<span class="italic text-slate-400">' . t('(no message)') . '</span>' ?></p>
              <p class="mt-1 text-xs text-slate-500"><span class="font-medium text-slate-700"><?= e($entry->author ?: '—') ?></span> · <time datetime="<?= e($entry->date?->format(DATE_ATOM) ?? '') ?>" title="<?= e($entry->date?->format('Y-m-d H:i') ?? '') ?>"><?= e(ago($entry->date)) ?></time> · <?= t('%0 changed paths', count($entry->paths)) ?></p>
            </div>
          </li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <aside class="space-y-6<?= $log === null ? ' lg:col-span-3 lg:grid lg:grid-cols-2 lg:gap-6 lg:space-y-0' : '' ?>">
    <section class="card">
      <div class="card-header"><h2 class="card-title"><?= icon('info', 'size-4 text-brand-600') ?><?= t('Details') ?></h2></div>
      <dl class="card-body space-y-3 text-sm">
        <div><dt class="text-xs text-slate-500"><?= t('Location') ?></dt><dd class="mono break-all text-slate-800"><?= e($repo->path) ?></dd></div>
        <div><dt class="text-xs text-slate-500"><?= t('Local URL') ?></dt><dd class="flex items-center gap-1"><span class="mono min-w-0 flex-1 break-all text-slate-800"><?= e($fileUrl) ?></span><button type="button" class="btn-icon" data-copy="<?= e($fileUrl) ?>" title="<?= t('Copy') ?>" aria-label="<?= t('Copy') ?>"><?= icon('copy') ?></button></dd></div>
        <?php if ($info !== null): ?>
          <div><dt class="text-xs text-slate-500">UUID</dt><dd class="mono break-all text-slate-800"><?= e($info->uuid) ?></dd></div>
          <div><dt class="text-xs text-slate-500"><?= t('Type') ?></dt><dd class="text-slate-800"><?= e(strtoupper($info->fsType)) ?></dd></div>
        <?php endif; ?>
      </dl>
    </section>

    <?php if ($v->services->hasAccessPathView() && $v->can(SvnManage\Acl\Acl::MOD_ACCESSPATH, SvnManage\Acl\Acl::VIEW)): ?>
    <section class="card">
      <div class="card-header"><h2 class="card-title"><?= icon('folder-tree', 'size-4 text-brand-600') ?><?= t('Access-Paths') ?></h2>
        <?php if ($canAddPath): ?><a class="btn btn-sm btn-ghost" href="<?= url('/access-paths/new', ['path' => $repo->name . ':/']) ?>"><?= icon('plus', 'size-3.5') ?><?= t('Add') ?></a><?php endif; ?>
      </div>
      <?php if ($paths === []): ?>
        <p class="card-body text-sm text-slate-500"><?= t('No access paths for this repository.') ?></p>
      <?php else: ?>
        <ul class="list">
          <?php foreach ($paths as $p): ?>
            <li><a class="row row-link text-sm" href="<?= url('/access-paths/view', ['path' => $p['path']]) ?>"><?= icon('folder', 'size-4 text-slate-400') ?><span class="mono min-w-0 flex-1 truncate"><?= e($p['path']) ?></span><span class="badge badge-slate"><?= (int) $p['rules'] ?></span></a></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($canLoad): ?>
    <section class="card">
      <div class="card-header"><h2 class="card-title"><?= icon('upload', 'size-4 text-brand-600') ?><?= t('Load dump file') ?></h2></div>
      <form method="post" action="<?= url($base . '/load') ?>" enctype="multipart/form-data" hx-boost="false" class="card-body space-y-3">
        <?= csrf_field() ?>
        <input type="file" name="dumpfile" required class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-brand-700 hover:file:bg-brand-100">
        <p class="hint"><?= t('Maximum upload size: %0', human_bytes($maxUpload)) ?></p>
        <button class="btn btn-secondary w-full"><?= icon('upload') ?><?= t('Load into repository') ?></button>
      </form>
    </section>
    <?php endif; ?>

    <?php if ($canDelete): ?>
    <form method="post" action="<?= url('/repositories/delete') ?>" class="card border-rose-200" data-confirm="<?= t('Delete repository %0? This cannot be undone.', $repo->name) ?>" data-confirm-label="<?= t('Delete') ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="pi" value="<?= (int) $repo->parentId ?>"><input type="hidden" name="selected_repos[]" value="<?= e($repo->name) ?>">
      <div class="card-body space-y-3">
        <h2 class="font-semibold text-rose-700"><?= t('Danger zone') ?></h2>
        <?php if ($v->services->hasAccessPathEdit()): ?><label class="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" class="checkbox" name="delete_ap" value="1" checked><?= t('Also delete access paths') ?></label><?php endif; ?>
        <button class="btn btn-danger-soft w-full"><?= icon('trash-2') ?><?= t('Delete repository') ?></button>
      </div>
    </form>
    <?php endif; ?>
  </aside>
</div>
