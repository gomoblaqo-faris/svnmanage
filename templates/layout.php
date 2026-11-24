<?php
/** @var SvnManage\View\View $v */
/** @var string $content */
/** @var string $title */
/** @var string $nav */
$jsonHeaders = json_encode(['X-CSRF-Token' => $v->csrf()]);
?>
<!doctype html>
<html lang="<?= e($v->tr->htmlLang()) ?>" class="h-full scroll-smooth">
<head>
<?php include __DIR__ . '/partials/head.php'; ?>
</head>
<body class="min-h-full" hx-boost="true" hx-headers='<?= e($jsonHeaders) ?>' hx-indicator="#progress">
<div id="progress" class="progress-bar" aria-hidden="true"></div>
<a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-[70] focus:rounded-lg focus:bg-white focus:px-3 focus:py-2 focus:shadow-lift"><?= t('Skip to content') ?></a>

<?php include __DIR__ . '/partials/sidebar.php'; ?>

<div class="lg:pl-72">
  <header class="sticky top-0 z-30 flex h-14 items-center gap-2 border-b border-slate-200/70 bg-white/85 px-3 backdrop-blur-md lg:hidden">
    <button type="button" class="btn-icon" data-sidebar-toggle aria-controls="sidebar" aria-expanded="false" aria-label="<?= t('Open menu') ?>"><?= icon('menu', 'size-5') ?></button>
    <a href="<?= url('/') ?>" class="flex-1"><?php include __DIR__ . '/partials/logo.php'; ?></a>
    <?php if ($v->auth->username !== null): ?>
      <span class="avatar avatar-sm" style="--h: <?= avatar_hue($v->auth->username) ?>" title="<?= e($v->auth->username) ?>"><?= e(initials($v->auth->username)) ?></span>
    <?php endif; ?>
  </header>

  <?php if (!$v->services->authEnabled()): ?>
    <div class="border-b border-amber-200 bg-amber-50 px-4 py-2 text-center text-[13px] text-amber-900">
      <?= icon('triangle-alert', 'mr-1 inline size-4 -translate-y-px') ?>
      <?= t('Authentication is disabled. Everyone who can reach this page has full administrative access.') ?>
    </div>
  <?php endif; ?>

  <main id="main" class="mx-auto w-full max-w-6xl px-4 pb-28 pt-6 sm:px-6 lg:px-10 lg:pt-10">
    <?= $content ?>
  </main>
</div>

<div id="toasts" aria-live="polite" data-error-label="<?= t('Request failed') ?>"
     class="pointer-events-none fixed inset-x-3 bottom-3 z-[55] flex flex-col items-center gap-2 sm:inset-x-auto sm:bottom-auto sm:right-5 sm:top-5 sm:items-end">
  <?php include __DIR__ . '/partials/toasts.php'; ?>
</div>
<?php include __DIR__ . '/partials/confirm.php'; ?>
</body>
</html>
