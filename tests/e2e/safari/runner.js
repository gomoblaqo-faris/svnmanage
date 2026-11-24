// Safari QA runner: one step (phase) per call; state lives in localStorage so full page loads are fine.
(() => {
  const KEY = 'qaState', ADMIN = ['admin', 'Admin-Pass-2026!'];
  const NAV = 'NAV', AGAIN = 'AGAIN', NEXT = 'NEXT';
  const load = () => JSON.parse(localStorage.getItem(KEY) || '{"i":0,"ph":0,"n":0,"results":[],"vars":{},"g":{}}');
  const save = s => localStorage.setItem(KEY, JSON.stringify(s));
  const sleep = ms => new Promise(r => setTimeout(r, ms));
  const $ = (s, root) => (root || document).querySelector(s);
  const $$ = (s, root) => [...(root || document).querySelectorAll(s)];
  const has = (e, t) => (t instanceof RegExp ? t.test(e.textContent) : e.textContent.includes(t));
  const html = () => document.documentElement.outerHTML;
  const toasts = () => $$('[data-toast]').map(e => e.dataset.toast + ': ' + e.textContent.trim().replace(/\s+/g, ' '));
  async function settle() {
    await sleep(1000); let last = '', stable = 0;
    // Signature of the page minus the toasts, whose countdown changes the DOM while they show.
    for (let i = 0; i < 40; i++) {
      const ready = document.readyState === 'complete' && !$('.htmx-request');
      const main = $('main') || document.body;
      const sig = location.href + '#' + (main ? main.innerHTML.length : 0);
      if (ready && sig === last) { if (++stable >= 3) return; } else stable = 0;
      last = sig; await sleep(100);
    }
  }
  /** Full navigation unless already there; returns true when a load was started. */
  function nav(path) {
    if (new URL(path, location.origin).href === location.href) return false;
    setTimeout(() => { location.href = path; }, 400);
    return true;
  }
  function fill(el, v) {
    if (typeof el === 'string') { const s = el; el = $(s); if (!el) throw Error('no field ' + s); }
    el.value = v; el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true }));
  }
  async function confirmIfAsked() { await sleep(300); const ok = $('#confirm-dialog[open] [data-confirm-ok]'); if (ok) ok.click(); }
  /** Click inside the document (boosted forms and links): waits for the swap. */
  async function click(el) { if (!el) throw Error('nothing to click'); el.scrollIntoView({ block: 'center' }); el.click(); await confirmIfAsked(); await settle(); }
  /** Click that leads to a full page load (non-boosted forms): the next phase runs on the new page. */
  async function clickNav(el) {
    if (!el) throw Error('nothing to click'); el.scrollIntoView({ block: 'center' });
    // Runs after this call has returned, so the reply reaches the driver before the page unloads.
    setTimeout(() => el.click(), 400);
    setTimeout(() => { const ok = $('#confirm-dialog[open] [data-confirm-ok]'); if (ok) ok.click(); }, 900);
    return NAV;
  }
  function submitBtn(formSel, text) {
    const f = typeof formSel === 'string' ? $(formSel) : formSel; if (!f) throw Error('no form ' + formSel);
    const b = $$('button:not([type=button])', f); const btn = text ? b.find(x => has(x, text)) : b[b.length - 1];
    if (!btn) throw Error('no button ' + (text || '')); return btn;
  }
  const submit = (f, t) => click(submitBtn(f, t));
  const fieldset = (root, legend) => $$('fieldset', root).find(f => f.querySelector('legend') && has(f.querySelector('legend'), legend));
  function pick(root, text) { const lab = $$('label', root).find(l => has(l, text)); if (!lab) throw Error('no option ' + text); const i = lab.querySelector('input'); if (!i.checked) i.click(); }
  function choose(sel, wanted) { const s = typeof sel === 'string' ? $(sel) : sel; const o = [...s.options].find(o => o.value === wanted || o.textContent.trim() === wanted); if (!o) throw Error('no option ' + wanted); s.value = o.value; return s; }
  async function totp(secret, step) {
    const a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; let bits = '';
    for (const c of secret.replace(/[\s=]/g, '').toUpperCase()) bits += a.indexOf(c).toString(2).padStart(5, '0');
    const key = new Uint8Array(Math.floor(bits.length / 8)).map((_, i) => parseInt(bits.slice(i * 8, i * 8 + 8), 2));
    const msg = new ArrayBuffer(8); new DataView(msg).setUint32(4, step);
    const k = await crypto.subtle.importKey('raw', key, { name: 'HMAC', hash: 'SHA-1' }, false, ['sign']);
    const h = new Uint8Array(await crypto.subtle.sign('HMAC', k, msg)); const o = h[19] & 15;
    return String((((h[o] & 0x7f) << 24) | (h[o + 1] << 16) | (h[o + 2] << 8) | h[o + 3]) % 1e6).padStart(6, '0');
  }
  const tstep = () => Math.floor(Date.now() / 30000);
  async function logoutBtn() {
    if (innerWidth < 1024 && $('#sidebar') && $('#sidebar').dataset.open !== 'true') { $('button[aria-controls=sidebar]').click(); await sleep(450); }
    return $('#sidebar button[aria-label=Logout]');
  }
  const signedIn = () => !!$('#sidebar button[aria-label=Logout]');

  const items = [];
  let cur = '';
  const Q = (name, phases, expect = /success|info|warning/) => items.push({ name, label: cur, phases: [].concat(phases), expect });
  const loginPhases = (u, pw, expectPath = '/') => [
    async () => (signedIn() ? clickNav(await logoutBtn()) : NEXT),
    async () => { if (nav('/login')) return AGAIN; fill('#username', u); fill('#password', pw); return clickNav($('form[action$="/login"] button.btn-primary')); },
    async () => { if (location.pathname !== expectPath) throw Error('landed on ' + location.pathname); return NEXT; },
  ];

  function build(lab) {
    cur = lab; const s = lab[0], U = 'user' + s, G = 'team' + s, R = 'repo' + s, PW = 'User-Pass-2026!', PW2 = 'User-Pass-2027!';
    const AP = '/access-paths/view?path=' + encodeURIComponent(R + ':/trunk');
    Q('login as admin', [...loginPhases(...ADMIN), async () => 'dashboard'], null);
    Q('settings: enable dump/load/delete' + (lab === 'desktop' ? ', change timeout' : ''), [
      async v => {
        if (nav('/settings')) return AGAIN;
        for (const k of ['RepositoryDeleteEnabled', 'RepositoryDumpEnabled', 'RepositoryLoadEnabled']) { const c = $(`input[type=checkbox][name="GUI|${k}"]`); if (!c.checked) c.click(); }
        if (lab === 'desktop') fill('input[name="Common|SessionTimeout"]', '3000');
        return clickNav(submitBtn('form[action$="/settings"]'));
      },
      async v => { v.t ??= toasts().join(' | '); if (nav('/settings?reload=1')) return AGAIN; if (lab === 'desktop' && $('input[name="Common|SessionTimeout"]').value !== '3000') throw Error('not saved'); return v.t + ' (kept after reload)'; },
    ]);
    if (lab === 'desktop') Q('settings: connection test', [async () => { if (nav('/settings')) return AGAIN; return clickNav($$('button').find(b => has(b, /Test/))); }, async () => 'ran'], null);
    Q('create user', [async () => { if (nav('/users/new')) return AGAIN; fill('#username', U); fill('#pw1', PW); fill('#pw2', PW); return clickNav(submitBtn('form[action$="/users"]', 'Create user')); }, async () => '']);
    Q('search users', async () => { if (nav('/users?q=' + U)) return AGAIN; if (!html().includes('/users/' + U)) throw Error('not found'); return 'found'; }, null);
    Q('create group', [async () => { if (nav('/groups/new')) return AGAIN; fill('input[name=groupname]', G); return clickNav(submitBtn('form[action$="/groups"]')); }, async () => '']);
    Q('bulk memberships: add user to group', [async () => {
      if (nav('/groups/memberships')) return AGAIN; const f = $('form[action$="/groups/memberships"]'); const fs = $$('fieldset', f);
      pick(fieldset(f, 'Users'), U); pick(fs[fs.length - 1], '@' + G); return clickNav(submitBtn(f, /Add/));
    }, async () => '']);
    Q('group page shows member', async () => { if (nav('/groups/' + G)) return AGAIN; if (!html().includes(U)) throw Error('member missing'); return 'member listed'; }, null);
    Q('rename group (and back)', [
      async () => { if (nav('/groups/' + G)) return AGAIN; fill('input[name=newname]', G + 'x'); return clickNav(submitBtn(`form[action$="/groups/${G}/rename"]`)); },
      async v => {
        if (!location.pathname.endsWith('/' + G + 'x') || !html().includes(U)) throw Error('rename lost members or page: ' + location.pathname);
        v.t = toasts().join(' | '); fill('input[name=newname]', G); return clickNav(submitBtn(`form[action$="/groups/${G}x/rename"]`));
      },
      async v => v.t + ' / back:',
    ]);
    Q('create repository (layout + access path)', [async () => {
      if (nav('/repositories/new')) return AGAIN; fill('input[name=reponame]', R);
      const single = $('input[name=repostructuretype][value=single]'); if (single && !single.checked) single.click();
      const ap = $('input[name=accesspathcreate]'); if (ap && !ap.checked) ap.click();
      return clickNav(submitBtn('form[action$="/repositories"]'));
    }, async () => '']);
    Q('repository detail', async () => { if (nav(`/repositories/0/${R}`)) return AGAIN; if (!html().includes('Size on disk')) throw Error('no detail'); return 'detail'; }, null);
    Q('browse repository + folder', [
      async () => { if (nav(`/repositories/0/${R}/browse`)) return AGAIN; return clickNav($$('a').find(a => a.textContent.trim() === 'trunk/')); },
      async () => { if (!location.search.includes('path=trunk')) throw Error('did not open trunk'); return 'trunk opened'; },
    ], null);
    Q('download dump (POST + CSRF)', async (v, g) => {
      if (nav(`/repositories/0/${R}`)) return AGAIN; const f = $('form[action$="/dump"]');
      const r = await fetch(f.action, { method: 'POST', body: new FormData(f) }); const buf = new Uint8Array(await r.arrayBuffer());
      const head = new TextDecoder().decode(buf.slice(0, 26)); if (!head.startsWith('SVN-fs-dump-format')) throw Error('bad dump: ' + head);
      let bin = ''; buf.forEach(b => { bin += String.fromCharCode(b); }); g.dump = btoa(bin);
      return r.status + ' ' + (r.headers.get('content-disposition') || '') + ' ' + buf.length + 'B';
    }, null);
    Q('load dump into a new empty repository', [
      async () => {
        if (nav('/repositories/new')) return AGAIN; fill('input[name=reponame]', R + 'copy');
        const none = $('input[name=repostructuretype][value=none]'); if (none && !none.checked) none.click();
        const ap = $('input[name=accesspathcreate]'); if (ap && ap.checked) ap.click();
        return clickNav(submitBtn('form[action$="/repositories"]'));
      },
      async (v, g) => {
        if (nav(`/repositories/0/${R}copy`)) return AGAIN; const input = $('input[type=file][name=dumpfile]');
        const bytes = Uint8Array.from(atob(g.dump), c => c.charCodeAt(0)); const dt = new DataTransfer(); dt.items.add(new File([bytes], R + '.dump'));
        input.files = dt.files; input.dispatchEvent(new Event('change', { bubbles: true })); return clickNav(submitBtn(input.form));
      },
      async v => { v.t ??= toasts().join(' | '); if (nav(`/repositories/0/${R}copy/browse`)) return AGAIN; if (!html().includes('trunk/')) throw Error('history not loaded: ' + v.t); return v.t + ' (trunk/ browsable)'; },
    ]);
    Q('create access path', [async () => { if (nav('/access-paths/new')) return AGAIN; const sel = $('select[name=repository]'); if (sel) choose(sel, R); else fill('input[name=repository]', R); fill('input[name=path]', '/trunk'); return clickNav(submitBtn('form[action$="/access-paths"]')); }, async () => '']);
    Q('grant permission (user + group, read-write)', [
      async v => {
        if (nav('/permissions')) return AGAIN; const f = $('form[action$="/permissions"]');
        pick(fieldset(f, 'Users'), U); pick(fieldset(f, 'Groups'), '@' + G); pick(f, R + ':/trunk');
        $$('label.radio-card', f).find(l => has(l, 'Read-Write')).click(); return clickNav(submitBtn(f));
      },
      async v => { v.t ??= toasts().join(' | '); if (nav(AP)) return AGAIN; if (!html().includes('@' + G) || !html().includes(U)) throw Error('rule not shown'); return v.t + ' (listed on path page)'; },
    ], null);
    Q('user page: effective permissions', async () => { if (nav('/users/' + U)) return AGAIN; if (!html().includes(R + ':/trunk')) throw Error('no effective perm'); return 'shown'; }, null);
    Q('assign project manager to path', [async () => {
      if (nav(AP)) return AGAIN; const f = $('form[action$="/access-paths/managers"]'); if (!f) throw Error('no manager form');
      try { pick(f, U); } catch (e) { choose($('select', f), U); } return clickNav(submitBtn(f));
    }, async () => '']);
    Q('assign role on user page', [async () => { if (nav('/users/' + U)) return AGAIN; const f = $(`form[action$="/users/${U}/roles"]`); choose($('select', f), 'User-Group-Manager'); return clickNav(submitBtn(f, 'Assign')); }, async () => '']);
    Q('roles overview lists user', async () => { if (nav('/roles')) return AGAIN; if (!html().includes('/users/' + U)) throw Error('missing'); return 'listed'; }, null);
    Q('revoke group permission', [
      async () => { if (nav(AP)) return AGAIN; const f = $$('form[action$="/access-paths/revoke"]').find(f => f.querySelector(`input[value="@${G}"]`)); return clickNav($('button', f)); },
      async v => { v.t ??= toasts().join(' | '); if (nav(AP + '&check=1')) return AGAIN; if ($$('form[action$="/access-paths/revoke"]').some(f => f.querySelector(`input[value="@${G}"]`))) throw Error('still granted'); return v.t + ' (gone after reload)'; },
    ], null);
    Q("admin sets user's password", [
      async () => { if (nav(`/users/${U}/password`)) return AGAIN; fill('#pw1', PW2); fill('#pw2', PW2); return clickNav(submitBtn(`form[action$="/users/${U}/password"]`)); },
      async () => '',
    ]);
    Q('user: sign in with new password', [...loginPhases(U, PW2), async () => 'signed in as ' + U], null);
    Q('user: change own password', [
      async () => { if (nav('/account/password')) return AGAIN; fill('#current', PW2); fill('#pw1', PW); fill('#pw2', PW); return clickNav(submitBtn($$('form').find(f => f.action.includes('/password')))); },
      async () => '',
    ]);
    Q('user: enrol two-factor (QR + key + code)', [
      async (v, g) => {
        if (nav('/account/two-factor')) return AGAIN; if (!$('svg[role=img]')) throw Error('no QR');
        g.secret = $('code.mono').textContent.replace(/\s/g, ''); fill('#enable-pass', PW); fill('#enable-code', await totp(g.secret, tstep()));
        return clickNav(submitBtn('form[action$="/account/two-factor"]'));
      },
      async () => { const c = $$('ol li.mono').length; if (c !== 10) throw Error('recovery codes shown: ' + c); return '10 recovery codes shown'; },
    ]);
    Q('user: sign in with password + code', [
      ...loginPhases(U, PW, '/login/verify'),
      async (v, g) => { fill('#code', await totp(g.secret, tstep() + 1)); return clickNav(submitBtn('form[action$="/login/verify"]')); },
      async () => { if (location.pathname !== '/') throw Error('code rejected: ' + location.pathname); return 'signed in with code'; },
    ], null);
    Q('admin: 2FA badge on user page; reset two-factor', [
      ...loginPhases(...ADMIN),
      async () => { if (nav('/users/' + U)) return AGAIN; if (!html().includes('Two-factor on')) throw Error('no badge'); return clickNav(submitBtn(`form[action$="/users/${U}/two-factor/reset"]`)); },
      async () => 'badge shown;',
    ]);
    Q('activity log + search', async () => { if (nav('/activity?q=' + U)) return AGAIN; if (!html().includes(U)) throw Error('no entries'); return 'entries'; }, null);
    Q('backups list + download', async () => {
      if (nav('/backups')) return AGAIN; const a = $('a[href*="/backups/"]'); if (!a) throw Error('no backups');
      const r = await fetch(a.href); if (!r.ok) throw Error('download ' + r.status); return r.status + ' ' + (r.headers.get('content-disposition') || '');
    }, null);
    Q('language switch (de) and back', [
      async () => { if (nav('/')) return AGAIN; const sel = choose('#locale-select', 'de_DE'); setTimeout(() => sel.dispatchEvent(new Event('change', { bubbles: true })), 400); return NAV; },
      async v => { v.lang = document.documentElement.lang; v.title = document.title; const sel = choose('#locale-select', 'en_US'); setTimeout(() => sel.dispatchEvent(new Event('change', { bubbles: true })), 400); return NAV; },
      async v => { if (!v.lang.startsWith('de')) throw Error('lang=' + v.lang); return `lang=${v.lang} ("${v.title}") -> ${document.documentElement.lang}`; },
    ], null);
    if (lab === 'phone') {
      Q('phone sidebar drawer opens and navigates', [
        async () => {
          if (nav('/')) return AGAIN; $('button[aria-controls=sidebar]').click(); await sleep(450);
          if ($('#sidebar').dataset.open !== 'true') throw Error('drawer did not open');
          return clickNav($$('#sidebar a').find(a => a.textContent.trim() === 'Groups'));
        },
        async () => { if (!location.pathname.startsWith('/groups')) throw Error('nav failed'); return 'drawer opened, navigated to Groups'; },
      ], null);
    }
    Q('delete access path', [async () => { if (nav(AP)) return AGAIN; return clickNav(submitBtn('form[action$="/access-paths/delete"]')); }, async () => '']);
    Q('delete group', [async () => { if (nav('/groups/' + G)) return AGAIN; return clickNav(submitBtn('form[action$="/groups/delete"]')); }, async () => '']);
    Q('delete repository (+ its access paths)', [async () => { if (nav(`/repositories/0/${R}`)) return AGAIN; return clickNav(submitBtn('form[action$="/repositories/delete"]')); }, async () => '']);
    Q('delete loaded copy', [async () => { if (nav(`/repositories/0/${R}copy`)) return AGAIN; return clickNav(submitBtn('form[action$="/repositories/delete"]')); }, async () => '']);
    Q('delete user', [async () => { if (nav('/users/' + U)) return AGAIN; return clickNav(submitBtn('form[action$="/users/delete"]')); }, async () => '']);
    Q('logout', [async () => clickNav(await logoutBtn()), async () => { if (!location.pathname.startsWith('/login')) throw Error(location.pathname); return 'logged out'; }], null);
  }
  build('phone');
  items.push({ viewport: [1366, 900] });
  build('desktop');

  async function run() {
    await settle();
    const s = load();
    for (let guard = 0; guard < 12; guard++) {
      if (s.i >= items.length) return { done: true, results: s.results };
      const it = items[s.i];
      if (it.viewport) { s.i++; s.ph = 0; s.n = 0; save(s); return { viewport: it.viewport }; }
      let out, err = null;
      try { out = await it.phases[s.ph](s.vars, s.g); } catch (e) { err = e; }
      if (!err && out === AGAIN) { save(s); return { step: it.name, phase: s.ph, loading: true }; }
      if (!err && (out === NAV || out === NEXT) && s.ph < it.phases.length - 1) {
        s.ph++; save(s);
        if (out === NAV) return { step: it.name, phase: s.ph, loading: true };
        continue;
      }
      s.n++; let status = 'PASS', detail = '';
      if (err) { status = 'FAIL'; detail = String(err); } else {
        const t = toasts(); detail = (typeof out === 'string' && ![NAV, NEXT].includes(out) ? out + ' ' : '') + t.join(' | ');
        if (t.some(x => x.startsWith('error')) && !String(it.expect).includes('error')) status = 'FAIL';
        if (it.expect && t.length && !t.some(x => it.expect.test(x))) status = 'FAIL';
      }
      const ov = document.documentElement.scrollWidth - innerWidth; if (ov > 0) { status = 'FAIL'; detail += ' overflowX=' + ov; }
      const line = `${status} [${it.label} ${innerWidth}px] ${String(s.n).padStart(2, '0')} ${it.name} :: ${detail.trim().slice(0, 200)}`;
      s.results.push(line); s.i++; s.ph = 0; s.vars = {}; save(s);
      return { line, shot: `${it.label}-${String(s.n).padStart(2, '0')}` };
    }
    return { error: 'phase loop' };
  }
  window.__L = { run, reset: () => localStorage.removeItem(KEY), state: load, count: items.length };
})();
