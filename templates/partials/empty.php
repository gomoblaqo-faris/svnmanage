<?php /** @var string $emptyIcon @var string $emptyTitle @var string|null $emptyText @var string|null $emptyAction */ ?>
<div class="empty">
  <span class="empty-icon"><?= icon($emptyIcon, 'size-6') ?></span>
  <div>
    <p class="font-semibold text-slate-900"><?= e($emptyTitle) ?></p>
    <?php if (!empty($emptyText)): ?><p class="mt-1 max-w-sm text-sm text-slate-500"><?= e($emptyText) ?></p><?php endif; ?>
  </div>
  <?php if (!empty($emptyAction)): ?><div class="mt-1"><?= $emptyAction ?></div><?php endif; ?>
</div>
