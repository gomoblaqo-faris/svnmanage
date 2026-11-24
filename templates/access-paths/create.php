<?php /** @var list<string> $repos @var string $prefillRepo @var string $prefillPath @var bool $scoped */
$heading = t('New access path');
$sub = t('An access path is a [repository:/path] section in the authorization file.');
$crumbs = [tr('Access-Paths') => url('/access-paths')];
include __DIR__ . '/../partials/header.php';
?>
<form method="post" action="<?= url('/access-paths') ?>" class="card max-w-2xl">
  <?= csrf_field() ?>
  <div class="card-body space-y-5">
    <div class="grid gap-3 sm:grid-cols-[minmax(0,14rem)_auto_1fr] sm:items-end">
      <div>
        <label class="label" for="repository"><?= t('Repository') ?></label>
        <?php if ($repos !== []): ?>
          <select class="input" id="repository" name="repository">
            <?php if (!$scoped): ?><option value=""><?= t('(all repositories)') ?></option><?php endif; ?>
            <?php foreach ($repos as $r): ?><option value="<?= e($r) ?>"<?= $r === $prefillRepo ? ' selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?>
          </select>
        <?php else: ?>
          <input class="input input-mono" id="repository" name="repository" value="<?= e($prefillRepo) ?>" placeholder="<?= t('(all repositories)') ?>" autocapitalize="none">
        <?php endif; ?>
      </div>
      <span class="hidden pb-2.5 font-mono text-lg text-slate-400 sm:block">:</span>
      <div>
        <label class="label" for="path"><?= t('Path') ?></label>
        <input class="input input-mono" id="path" name="path" value="<?= e($prefillPath) ?>" required placeholder="/trunk" autocapitalize="none" spellcheck="false" autofocus>
      </div>
    </div>
    <p class="hint"><?= t('Use "/" for the whole repository. Leave the repository empty for a path that applies to every repository.') ?></p>
  </div>
  <div class="flex justify-end gap-2 border-t border-slate-100 px-5 py-4">
    <a href="<?= url('/access-paths') ?>" class="btn btn-secondary"><?= t('Cancel') ?></a>
    <button class="btn btn-primary"><?= icon('plus') ?><?= t('Create access path') ?></button>
  </div>
</form>
