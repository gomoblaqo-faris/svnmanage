<dialog id="confirm-dialog" class="m-auto w-[min(26rem,calc(100vw-2rem))] rounded-2xl bg-white p-0 shadow-2xl ring-1 ring-slate-200 backdrop:animate-fade-in">
  <form method="dialog" class="p-5">
    <div class="flex items-start gap-3.5">
      <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-rose-50 text-rose-600"><?= icon('triangle-alert', 'size-5') ?></span>
      <div class="min-w-0">
        <h2 class="text-base font-semibold text-slate-900"><?= t('Are you sure?') ?></h2>
        <p class="mt-1 text-sm text-slate-600" data-confirm-text></p>
      </div>
    </div>
    <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
      <button value="cancel" class="btn btn-secondary"><?= t('Cancel') ?></button>
      <button value="ok" class="btn btn-danger" data-confirm-ok data-default-label="<?= t('Confirm') ?>"><?= t('Confirm') ?></button>
    </div>
  </form>
</dialog>
