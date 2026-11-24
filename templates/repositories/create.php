<?php
/** @var list<SvnManage\Svn\RepositoryParent> $parents @var array $old */
$heading = t('New repository');
$sub = t('Creates the repository with svnadmin and optionally an initial folder layout and access path.');
$crumbs = [tr('Repositories') => url('/repositories')];
include __DIR__ . '/../partials/header.php';
$pi = (int) ($v->request->query('pi') ?: 0);
?>
<form method="post" action="<?= url('/repositories') ?>" class="grid gap-6 lg:grid-cols-3">
  <?= csrf_field() ?>
  <div class="space-y-6 lg:col-span-2">
    <section class="card">
      <div class="card-header"><h2 class="card-title"><?= icon('database', 'size-4 text-brand-600') ?><?= t('Repository') ?></h2></div>
      <div class="card-body grid gap-5 sm:grid-cols-2">
        <div class="sm:col-span-2">
          <label class="label" for="reponame"><?= t('Name') ?></label>
          <input class="input input-mono" id="reponame" name="reponame" value="<?= e($old['name'] ?? '') ?>" required autofocus pattern="[A-Za-z0-9._\-]+" maxlength="128" autocapitalize="none" spellcheck="false" placeholder="my-project">
          <p class="hint"><?= t('Letters, digits, dot, dash and underscore.') ?></p>
        </div>
        <?php if (count($parents) > 1): ?>
          <div class="sm:col-span-2">
            <label class="label" for="pi"><?= t('Location') ?></label>
            <select class="input" id="pi" name="pi">
              <?php foreach ($parents as $p): ?><option value="<?= (int) $p->id ?>"<?= $p->id === $pi ? ' selected' : '' ?>><?= e($p->description) ?> — <?= e($p->path) ?></option><?php endforeach; ?>
            </select>
          </div>
        <?php else: ?>
          <input type="hidden" name="pi" value="<?= (int) ($parents[0]->id ?? 0) ?>">
        <?php endif; ?>
        <div class="sm:col-span-2">
          <label class="label" for="repotype"><?= t('Type') ?></label>
          <select class="input" id="repotype" name="repotype">
            <option value="fsfs" selected>FSFS (<?= t('recommended') ?>)</option>
            <option value="fsx">FSX (<?= t('experimental') ?>)</option>
            <option value="bdb">Berkeley DB (<?= t('deprecated') ?>)</option>
          </select>
        </div>
      </div>
    </section>

    <section class="card">
      <div class="card-header"><h2 class="card-title"><?= icon('folder-tree', 'size-4 text-brand-600') ?><?= t('Initial layout') ?></h2></div>
      <div class="card-body space-y-4">
        <div class="grid gap-2 sm:grid-cols-3">
          <?php foreach (['none' => ['Empty', 'No folders.', 'square-dashed'], 'single' => ['Single project', '/trunk /branches /tags', 'folder'], 'multi' => ['Multiple projects', '/<project>/trunk …', 'folders']] as $val => [$label, $desc, $ic]): ?>
            <label class="radio-card">
              <input type="radio" name="repostructuretype" value="<?= $val ?>" class="sr-only"<?= $val === 'none' ? ' checked' : '' ?>>
              <span class="mt-0.5 text-brand-600"><?= icon($ic) ?></span>
              <span><span class="block text-sm font-semibold text-slate-900"><?= t($label) ?></span><span class="mono block text-xs text-slate-500"><?= e($desc) ?></span></span>
            </label>
          <?php endforeach; ?>
        </div>
        <div data-show-when="repostructuretype=multi">
          <label class="label" for="projectname"><?= t('Project name') ?></label>
          <input class="input input-mono" id="projectname" name="projectname" value="<?= e($old['project'] ?? '') ?>" placeholder="core" autocapitalize="none">
        </div>
      </div>
    </section>
  </div>

  <aside class="space-y-6">
    <?php if ($canCreatePath): ?>
    <section class="card">
      <div class="card-body">
        <label class="flex cursor-pointer items-start gap-3">
          <input type="checkbox" class="checkbox mt-0.5" name="accesspathcreate" value="1" checked>
          <span><span class="block text-sm font-semibold text-slate-900"><?= t('Create access path') ?></span>
          <span class="block text-xs text-slate-500"><?= t('Adds a [repository:/] section to the authorization file so you can grant permissions right away.') ?></span></span>
        </label>
      </div>
    </section>
    <?php endif; ?>
    <div class="flex gap-2 lg:flex-col">
      <button class="btn btn-primary flex-1"><?= icon('plus') ?><?= t('Create repository') ?></button>
      <a href="<?= url('/repositories') ?>" class="btn btn-secondary flex-1"><?= t('Cancel') ?></a>
    </div>
  </aside>
</form>
