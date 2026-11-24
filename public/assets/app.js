/* svnmanage UI glue. No framework: event delegation so it survives htmx swaps. */
(function () {
  'use strict';

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  /* ---------- sidebar (off-canvas on mobile) ---------- */
  function setSidebar(open) {
    const sidebar = $('#sidebar');
    const backdrop = $('#sidebar-backdrop');
    if (!sidebar) return;
    const wasOpen = sidebar.dataset.open === 'true';
    sidebar.dataset.open = open ? 'true' : 'false';
    sidebar.classList.toggle('-translate-x-full', !open);
    backdrop && backdrop.classList.toggle('hidden', !open);
    document.body.classList.toggle('overflow-hidden', open && window.innerWidth < 1024);
    const toggle = $('[data-sidebar-toggle]');
    toggle && toggle.setAttribute('aria-expanded', String(open));
    if (open) { const first = $('a, button', sidebar); first && first.focus({ preventScroll: true }); }
    // Return focus to the menu button so keyboard users are not dropped on <body>.
    else if (wasOpen && toggle && (document.activeElement === document.body || sidebar.contains(document.activeElement))) { toggle.focus({ preventScroll: true }); }
  }

  /* ---------- bulk selection ---------- */
  function refreshBulk(scope) {
    const items = $$('[data-bulk-item]', scope).filter((el) => !el.closest('[hidden]'));
    const checked = items.filter((el) => el.checked);
    const all = $('[data-bulk-all]', scope);
    if (all) {
      all.checked = items.length > 0 && checked.length === items.length;
      all.indeterminate = checked.length > 0 && checked.length < items.length;
    }
    $$('[data-bulk-count]', scope).forEach((el) => { el.textContent = String(checked.length); });
    $$('[data-bulk-bar]', scope).forEach((el) => { el.hidden = checked.length === 0; });
    $$('[data-bulk-requires]', scope).forEach((el) => { el.disabled = checked.length === 0; });
  }

  /* ---------- client-side filtering of picker lists ---------- */
  function applyFilter(input) {
    const scope = input.closest('[data-filter]');
    if (!scope) return;
    const q = input.value.trim().toLowerCase();
    let visible = 0;
    $$('[data-filter-item]', scope).forEach((item) => {
      const text = (item.dataset.filterText || item.textContent || '').toLowerCase();
      const show = q === '' || text.includes(q);
      item.hidden = !show;
      if (show) visible++;
    });
    const empty = $('[data-filter-empty]', scope);
    empty && (empty.hidden = visible !== 0);
  }

  /* ---------- conditional fields ---------- */
  function refreshConditional(root = document) {
    $$('[data-show-when]', root).forEach((el) => {
      const [name, values] = el.dataset.showWhen.split('=');
      const field = $$(`[name="${name}"]`).find((f) => (f.type === 'radio' || f.type === 'checkbox') ? f.checked : true);
      const value = field ? field.value : '';
      const show = values.split('|').includes(value);
      el.hidden = !show;
      $$('input, select, textarea', el).forEach((i) => { i.disabled = !show; });
    });
  }

  /* ---------- toasts ---------- */
  function armToasts(root = document) {
    $$('[data-toast]:not([data-armed])', root).forEach((toast) => {
      toast.dataset.armed = '1';
      const ms = toast.dataset.toast === 'error' ? 9000 : 5000;
      setTimeout(() => dismiss(toast), ms);
    });
  }
  function dismiss(toast) {
    toast.style.transition = 'opacity .2s, transform .2s';
    toast.style.opacity = '0';
    toast.style.transform = 'translateY(-6px)';
    setTimeout(() => toast.remove(), 200);
  }

  /* ---------- confirm dialog (hx-confirm and data-confirm) ---------- */
  function ask(question, elt, onOk) {
    const dialog = $('#confirm-dialog');
    if (!dialog || typeof dialog.showModal !== 'function') {
      if (window.confirm(question)) onOk();
      return;
    }
    $('[data-confirm-text]', dialog).textContent = question;
    const ok = $('[data-confirm-ok]', dialog);
    const danger = (elt.dataset.confirmTone || 'danger') === 'danger';
    ok.className = 'btn ' + (danger ? 'btn-danger' : 'btn-primary');
    ok.textContent = elt.dataset.confirmLabel || ok.dataset.defaultLabel;
    dialog.returnValue = '';
    dialog.showModal();
    dialog.addEventListener('close', function onClose() {
      dialog.removeEventListener('close', onClose);
      if (dialog.returnValue === 'ok') onOk();
    });
  }

  document.addEventListener('htmx:confirm', (evt) => {
    const question = evt.detail.question;
    if (!question) return;
    evt.preventDefault();
    ask(question, evt.detail.elt, () => evt.detail.issueRequest(true));
  });

  /* data-confirm on a <form> or on its submit button; runs before htmx (capture phase) */
  document.addEventListener('submit', (e) => {
    const form = e.target;
    const btn = e.submitter && e.submitter.form === form ? e.submitter : null;
    const source = btn && btn.dataset.confirm ? btn : (form.dataset.confirm ? form : null);
    if (!source) return;
    if (form.dataset.confirmed === '1') { delete form.dataset.confirmed; return; }
    e.preventDefault();
    e.stopImmediatePropagation();
    ask(source.dataset.confirm, source, () => {
      form.dataset.confirmed = '1';
      btn ? form.requestSubmit(btn) : form.requestSubmit();
    });
  }, true);

  /* ---------- global click handling ---------- */
  document.addEventListener('click', (e) => {
    const t = e.target;
    if (t.closest('[data-sidebar-toggle]')) { setSidebar($('#sidebar').dataset.open !== 'true'); return; }
    if (t.closest('[data-sidebar-close]') || t.id === 'sidebar-backdrop') { setSidebar(false); return; }
    const close = t.closest('[data-toast-close]');
    if (close) { dismiss(close.closest('[data-toast]')); return; }
    const copy = t.closest('[data-copy]');
    if (copy) {
      navigator.clipboard && navigator.clipboard.writeText(copy.dataset.copy).then(() => {
        const old = copy.getAttribute('title');
        copy.setAttribute('title', copy.dataset.copiedLabel || 'Copied');
        copy.classList.add('text-emerald-600');
        setTimeout(() => { copy.classList.remove('text-emerald-600'); old && copy.setAttribute('title', old); }, 1200);
      });
      return;
    }
    const reveal = t.closest('[data-reveal]');
    if (reveal) {
      const input = document.getElementById(reveal.dataset.reveal);
      if (input) input.type = input.type === 'password' ? 'text' : 'password';
      return;
    }
    const gen = t.closest('[data-generate]');
    if (gen) {
      const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#%+=?';
      const bytes = new Uint32Array(16);
      crypto.getRandomValues(bytes);
      const pw = Array.from(bytes, (b) => chars[b % chars.length]).join('');
      gen.dataset.generate.split(',').forEach((id) => {
        const input = document.getElementById(id);
        if (input) { input.value = pw; input.type = 'text'; }
      });
      return;
    }
  });

  document.addEventListener('change', (e) => {
    const t = e.target;
    if (t.matches('[data-bulk-all]')) {
      const scope = t.closest('[data-bulk]');
      $$('[data-bulk-item]', scope).filter((el) => !el.closest('[hidden]')).forEach((el) => { el.checked = t.checked; });
    }
    const scope = t.closest('[data-bulk]');
    scope && refreshBulk(scope);
    if (t.name) refreshConditional();
    if (t.matches('[data-autosubmit]')) t.form && t.form.requestSubmit();
  });

  document.addEventListener('input', (e) => {
    if (e.target.matches('[data-filter-input]')) applyFilter(e.target);
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') setSidebar(false);
    if (e.key === '/' && !/input|textarea|select/i.test(document.activeElement.tagName)) {
      const search = $('[data-hotkey-search]');
      if (search) { e.preventDefault(); search.focus(); }
    }
  });

  function init(root = document) {
    $$('[data-bulk]', root).forEach(refreshBulk);
    refreshConditional(root);
    armToasts(root);
    $$('[data-filter-input]', root).forEach((i) => i.value && applyFilter(i));
  }

  document.addEventListener('DOMContentLoaded', () => init());
  document.addEventListener('htmx:afterSettle', (e) => init(e.detail.elt || document));
  document.addEventListener('htmx:beforeRequest', (e) => {
    if (e.detail.boosted) setSidebar(false);
  });
  document.addEventListener('htmx:responseError', (e) => {
    const box = $('#toasts');
    if (!box || e.detail.xhr.getResponseHeader('HX-Retarget')) return;
    const div = document.createElement('div');
    div.className = 'toast';
    div.dataset.toast = 'error';
    div.setAttribute('role', 'alert');
    div.textContent = (box.dataset.errorLabel || 'Request failed') + ' (' + e.detail.xhr.status + ')';
    box.appendChild(div);
    armToasts(box);
  });
})();
