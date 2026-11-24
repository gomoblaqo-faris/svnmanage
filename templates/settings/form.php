<?php
/**
 * @var SvnManage\View\View $v
 * @var SvnManage\Config\Config $config
 * @var list<array<string, mixed>> $groups
 * @var list<array{path: string, description: string}> $extraParents
 * @var string $action
 * @var string $mode settings|setup|setup-security
 * @var array<string, string> $hidden extra hidden inputs (optional)
 */
$collapsed = $mode === 'setup' ? ['ldap', 'ad', 'interface'] : [];
?>
<form method="post" action="<?= url($action) ?>" class="space-y-6" id="settings-form">
  <?= csrf_field() ?>
  <?php foreach ($hidden ?? [] as $hiddenName => $hiddenValue): ?><input type="hidden" name="<?= e($hiddenName) ?>" value="<?= e($hiddenValue) ?>"><?php endforeach; ?>
  <?php foreach ($groups as $group): $isCollapsed = in_array($group['id'], $collapsed, true); ?>
    <section id="<?= e($group['id']) ?>" class="card scroll-mt-24">
      <?php if ($isCollapsed): ?><details class="group"><summary class="card-header cursor-pointer list-none border-0 [&::-webkit-details-marker]:hidden"><?php else: ?><div class="card-header"><?php endif; ?>
        <div>
          <h2 class="card-title"><span class="flex size-8 items-center justify-center rounded-lg bg-brand-50 text-brand-600"><?= icon($group['icon']) ?></span><?= t($group['title']) ?></h2>
          <p class="mt-1 text-[13px] text-slate-500"><?= t($group['intro']) ?></p>
        </div>
        <?php if ($isCollapsed): ?><span class="text-slate-400 transition group-open:rotate-180"><?= icon('chevron-down', 'size-5') ?></span><?php endif; ?>
      <?php if ($isCollapsed): ?></summary><?php else: ?></div><?php endif; ?>
      <div class="card-body grid gap-x-5 gap-y-5 border-t border-slate-100 sm:grid-cols-2">
        <?php foreach ($group['fields'] as $field) { include __DIR__ . '/field.php'; } ?>
        <?php if ($group['id'] === 'repositories'): ?>
          <div class="sm:col-span-2">
            <p class="label"><?= t('Additional repository locations') ?></p>
            <p class="hint -mt-1 mb-3"><?= t('Stored as [Repositories:svnclient:N] sections. Leave a path empty to remove the location.') ?></p>
            <div class="space-y-2">
              <?php foreach ([...$extraParents, ['path' => '', 'description' => '']] as $i => $parent): ?>
                <div class="grid gap-2 sm:grid-cols-[1fr_16rem]">
                  <input class="input input-mono" name="extra_path[<?= $i ?>]" value="<?= e($parent['path']) ?>" placeholder="/srv/svn/other" aria-label="<?= t('Folder') ?>">
                  <input class="input" name="extra_desc[<?= $i ?>]" value="<?= e($parent['description']) ?>" placeholder="<?= t('Description') ?>" aria-label="<?= t('Description') ?>">
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>
      <?php if ($isCollapsed): ?></details><?php endif; ?>
    </section>
  <?php endforeach; ?>

  <div class="sticky bottom-3 z-20 flex items-center justify-between gap-3 rounded-2xl bg-white/90 p-3 shadow-lift ring-1 ring-slate-200 backdrop-blur">
    <p class="hidden truncate pl-2 text-[13px] text-slate-500 sm:block"><?= icon('file-text', 'mr-1 inline size-3.5') ?><span class="mono"><?= e($configPath ?? '') ?></span></p>
    <button class="btn btn-primary w-full sm:w-auto"><?= icon('save') ?><?= $mode === 'settings' ? t('Save settings') : t('Save and continue') ?></button>
  </div>
</form>
