<?php /** @var list<array<string, mixed>> $groups */
$heading = t('Settings');
$sub = t('Configure how svnmanage talks to Subversion, your user directory and the web interface.');
include __DIR__ . '/../partials/header.php';
?>
<nav class="sticky top-14 z-20 -mx-4 mb-6 flex gap-2 overflow-x-auto border-b border-slate-200/60 bg-canvas px-4 py-2 lg:top-0 lg:border-0 lg:bg-canvas/90 lg:backdrop-blur" aria-label="<?= t('Settings sections') ?>">
  <?php foreach ($groups as $group): ?>
    <a href="#<?= e($group['id']) ?>" hx-boost="false" class="chip min-h-10 shrink-0 bg-white ring-1 ring-slate-200 hover:text-brand-700 lg:min-h-0"><?= icon($group['icon'], 'size-3.5') ?><?= t($group['title']) ?></a>
  <?php endforeach; ?>
</nav>
<?php include __DIR__ . '/form.php'; ?>
