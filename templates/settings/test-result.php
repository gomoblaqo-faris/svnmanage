<?php /** @var bool $ok @var string $message */ ?>
<p class="mt-2 flex items-start gap-1.5 text-[13px] animate-fade-in <?= $ok ? 'text-emerald-700' : 'text-rose-700' ?>">
  <?= icon($ok ? 'circle-check' : 'circle-x', 'mt-0.5 size-4 shrink-0') ?><span class="break-words"><?= t($message) ?></span>
</p>
