<?php
/** @var SvnManage\View\View $v */
/** @var string $content */
/** @var string $title */
?>
<!doctype html>
<html lang="<?= e($v->tr->htmlLang()) ?>" class="h-full">
<head>
<?php include __DIR__ . '/partials/head.php'; ?>
</head>
<body class="min-h-full" hx-boost="true" hx-headers='<?= e(json_encode(['X-CSRF-Token' => $v->csrf()])) ?>' hx-indicator="#progress">
<div id="progress" class="progress-bar" aria-hidden="true"></div>
<div class="relative flex min-h-dvh flex-col overflow-hidden">
  <div class="pointer-events-none absolute inset-0 -z-10" aria-hidden="true">
    <div class="absolute -left-32 -top-40 size-[34rem] rounded-full bg-brand-200/50 blur-3xl"></div>
    <div class="absolute -right-40 top-1/3 size-[28rem] rounded-full bg-coral-100/70 blur-3xl"></div>
    <div class="absolute bottom-0 left-1/3 size-[22rem] rounded-full bg-sky-100/70 blur-3xl"></div>
  </div>
  <header class="flex items-center justify-between px-5 py-5 sm:px-8">
    <?php include __DIR__ . '/partials/logo.php'; ?>
    <div class="w-44"><?php include __DIR__ . '/partials/locale.php'; ?></div>
  </header>
  <main id="main" class="flex flex-1 items-start justify-center px-4 pb-16 pt-4 sm:items-center sm:pt-0">
    <?= $content ?>
  </main>
  <footer class="px-6 pb-6 text-center text-xs text-slate-400">svnmanage</footer>
</div>
<div id="toasts" aria-live="polite" class="pointer-events-none fixed inset-x-3 bottom-3 z-[55] flex flex-col items-center gap-2 sm:inset-x-auto sm:bottom-auto sm:right-5 sm:top-5 sm:items-end">
  <?php include __DIR__ . '/partials/toasts.php'; ?>
</div>
<?php include __DIR__ . '/partials/confirm.php'; ?>
</body>
</html>
