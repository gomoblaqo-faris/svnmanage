// End-to-end walk through every svnmanage capability via the real UI.
// usage: node tests/e2e/capabilities.cjs <baseUrl> <dataDir> <shotDir>
// Needs: npm i -D playwright-core (or PW=<path>), a fresh server: SVNMANAGE_DATA=<dataDir> php -S 127.0.0.1:8098 -t public public/index.php
// BROWSER=webkit for Safari's engine; CHROME=<path to Chrome/Chromium> for an installed Chrome. Walks every capability at phone and desktop size.
const playwright = require(process.env.PW || 'playwright-core');
// BROWSER=webkit runs Safari's engine; chromium (default) can use CHROME=<installed browser>.
const ENGINE = process.env.BROWSER || 'chromium';
const crypto = require('crypto');
const fs = require('fs');
const [base, dataDir, shots] = process.argv.slice(2);
const ADMIN = ['admin', 'Admin-Pass-2026!'];

function b32decode(s) {
  const a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; let bits = '';
  for (const c of s.replace(/[\s=]/g, '').toUpperCase()) bits += a.indexOf(c).toString(2).padStart(5, '0');
  const out = []; for (let i = 0; i + 8 <= bits.length; i += 8) out.push(parseInt(bits.slice(i, i + 8), 2));
  return Buffer.from(out);
}
function totp(secret, step) {
  const msg = Buffer.alloc(8); msg.writeBigUInt64BE(BigInt(step));
  const h = crypto.createHmac('sha1', b32decode(secret)).update(msg).digest();
  const o = h[h.length - 1] & 15;
  return String(((h.readUInt32BE(o) & 0x7fffffff) % 1e6)).padStart(6, '0');
}
const step = () => Math.floor(Date.now() / 30000);

const results = [];
let n = 0;

async function run(label, viewport, browser) {
  const ctx = await browser.newContext({ viewport, acceptDownloads: true });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(String(e)));
  page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('response', r => { if (r.status() >= 500) errors.push(`${r.status()} ${r.url()}`); });
  const s = label[0]; // m / d suffix keeps the two runs apart
  const U = `user${s}`, G = `team${s}`, R = `repo${s}`, PW = 'User-Pass-2026!', PW2 = 'User-Pass-2027!';

  const settle = async () => { await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(150); };
  const go = async p => { await page.goto(base + p); await settle(); };
  const toasts = async () => page.$$eval('[data-toast]', els => els.map(e => e.dataset.toast + ': ' + e.textContent.trim().replace(/\s+/g, ' ')));
  const confirmIfAsked = async () => {
    const ok = page.locator('#confirm-dialog[open] [data-confirm-ok]');
    if (await ok.count()) { await ok.click(); }
  };
  const submit = async (formSel, buttonText) => {
    const form = page.locator(formSel).first();
    const btn = buttonText ? form.locator('button', { hasText: buttonText }).first() : form.locator('button:not([type=button])').last();
    await btn.scrollIntoViewIfNeeded();
    await btn.click();
    await page.waitForTimeout(200);
    await confirmIfAsked();
    await settle();
  };
  const check = async (name, fn, expect = /success|info|warning/) => {
    n++;
    const before = errors.length;
    let status = 'PASS', detail = '';
    try {
      const extra = await fn();
      const t = await toasts();
      detail = (extra ? extra + ' ' : '') + t.join(' | ');
      if (t.some(x => x.startsWith('error')) && !String(expect).includes('error')) status = 'FAIL';
      if (expect && t.length && !t.some(x => expect.test(x))) status = 'FAIL';
    } catch (e) { status = 'FAIL'; detail = String(e).split('\n')[0]; }
    if (errors.length > before) { status = 'FAIL'; detail += ' ERR: ' + errors.slice(before).join(' ; '); }
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - innerWidth).catch(() => -1);
    if (overflow > 0) { status = 'FAIL'; detail += ` overflowX=${overflow}`; }
    await page.screenshot({ path: `${shots}/${label}-${String(n).padStart(2, '0')}-${name.replace(/\W+/g, '_')}.png`, fullPage: true }).catch(() => {});
    results.push(`${status} [${label}] ${name} :: ${detail.slice(0, 220)}`);
  };
  const logoutVia = async pg => {
    const btn = pg.locator('#sidebar button[aria-label=Logout]').first();
    const closedDrawer = await pg.evaluate(() => innerWidth < 1024 && document.getElementById('sidebar').dataset.open !== 'true');
    if (closedDrawer) { await pg.click('button[aria-controls=sidebar]'); await pg.waitForTimeout(400); }
    await btn.click(); await pg.waitForLoadState('networkidle').catch(() => {});
  };
  const login = async (user, pw) => { await go('/login'); await page.fill('#username', user); await page.fill('#password', pw); await submit('form[action$="/login"]'); };

  await check('login as admin', async () => { await login(...ADMIN); if (!page.url().endsWith('/')) throw new Error('not on dashboard: ' + page.url()); return 'dashboard'; }, null);

  if (label === 'desktop') {
    await check('settings: enable dump/load/delete, change timeout', async () => {
      await go('/settings');
      for (const k of ['RepositoryDeleteEnabled', 'RepositoryDumpEnabled', 'RepositoryLoadEnabled']) await page.check(`input[type=checkbox][name="GUI|${k}"]`);
      await page.fill('input[name="Common|SessionTimeout"]', '3000');
      await submit('form[action$="/settings"]');
      if (!fs.readFileSync(dataDir + '/config.ini', 'utf8').includes('SessionTimeout=3000')) throw new Error('not saved');
    });
    await check('settings: connection tests', async () => { await go('/settings'); const b = page.locator('button', { hasText: /Test/ }).first(); if (await b.count()) { await b.click(); await settle(); } return 'ran'; }, null);
  }

  await check('create user', async () => {
    await go('/users/new'); await page.fill('#username', U); await page.fill('#pw1', PW); await page.fill('#pw2', PW);
    await submit('form[action$="/users"]', 'Create user');
  });
  await check('search users', async () => { await go('/users?q=' + U); if (!(await page.content()).includes('/users/' + U)) throw new Error('not found'); return 'found'; }, null);
  await check('create group', async () => { await go('/groups/new'); await page.fill('input[name=groupname]', G); await submit('form[action$="/groups"]'); });
  await check('bulk memberships: add user to group', async () => {
    await go('/groups/memberships');
    const pickers = page.locator('form[action$="/groups/memberships"] fieldset');
    await pickers.filter({ hasText: 'Users' }).first().locator('label', { hasText: U }).locator('input').check();
    await pickers.last().locator('label', { hasText: '@' + G }).locator('input').check();
    await submit('form[action$="/groups/memberships"]', /Add/);
  });
  await check('group page shows member', async () => { await go('/groups/' + G); if (!(await page.content()).includes(U)) throw new Error('member missing'); return 'member listed'; }, null);
  await check('rename group', async () => {
    await go('/groups/' + G); await page.fill('input[name=newname]', G + 'x'); await submit(`form[action$="/groups/${G}/rename"]`);
    await go('/groups/' + G + 'x'); if (!(await page.content()).includes(U)) throw new Error('rename lost members');
    await page.fill('input[name=newname]', G); await submit(`form[action$="/groups/${G}x/rename"]`);
  });

  await check('create repository (single layout + access path)', async () => {
    await go('/repositories/new'); await page.fill('input[name=reponame]', R);
    const single = page.locator('input[name=repostructuretype][value=single]'); if (await single.count()) await single.check({ force: true });
    const ap = page.locator('input[name=accesspathcreate]'); if (await ap.count()) await ap.check({ force: true });
    await submit('form[action$="/repositories"]');
  });
  await check('repository detail', async () => { await go(`/repositories/0/${R}`); if (!(await page.content()).includes('Size on disk')) throw new Error('no detail'); return 'detail'; }, null);
  await check('browse repository + folder', async () => {
    await go(`/repositories/0/${R}/browse`); await page.click('a:has-text("trunk/")'); await settle();
    if (!page.url().includes('path=trunk')) throw new Error('did not open trunk'); return 'trunk opened';
  }, null);
  await check('download dump (POST + CSRF)', async () => {
    await go(`/repositories/0/${R}`);
    const [dl] = await Promise.all([page.waitForEvent('download'), page.locator('form[action$="/dump"] button').first().click()]);
    const file = await dl.path(); const head = fs.readFileSync(file).subarray(0, 26).toString();
    if (!head.startsWith('SVN-fs-dump-format')) throw new Error('bad dump: ' + head);
    fs.copyFileSync(file, `${shots}/${R}.dump`); return dl.suggestedFilename();
  }, null);
  await check('load dump into a new empty repository', async () => {
    await go('/repositories/new'); await page.fill('input[name=reponame]', R + 'copy');
    const none = page.locator('input[name=repostructuretype][value=none]'); if (await none.count()) await none.check({ force: true });
    const ap = page.locator('input[name=accesspathcreate]'); if (await ap.count()) await ap.uncheck({ force: true });
    await submit('form[action$="/repositories"]');
    await go(`/repositories/0/${R}copy`);
    await page.locator('input[type=file][name=dumpfile]').setInputFiles(`${shots}/${R}.dump`);
    await submit('form[action$="/load"]');
    await go(`/repositories/0/${R}copy/browse`); if (!(await page.content()).includes('trunk/')) throw new Error('history not loaded');
  });

  await check('create access path', async () => {
    await go('/access-paths/new');
    const sel = page.locator('select[name=repository]'); if (await sel.count()) await sel.selectOption(R); else await page.fill('input[name=repository]', R);
    await page.fill('input[name=path]', '/trunk'); await submit('form[action$="/access-paths"]');
  });
  await check('grant permission (user + group, read-write)', async () => {
    await go('/permissions');
    const f = page.locator('form[action$="/permissions"]');
    await f.locator('fieldset').filter({ hasText: 'Users' }).first().locator('label', { hasText: U }).locator('input').check();
    await f.locator('fieldset').filter({ hasText: 'Groups' }).first().locator('label', { hasText: '@' + G }).locator('input').check();
    await f.locator('label', { hasText: `${R}:/trunk` }).locator('input').check();
    await f.locator('label.radio-card', { hasText: 'Read-Write' }).click();
    await submit('form[action$="/permissions"]');
    if (!fs.readFileSync(dataDir + '/svn/authz', 'utf8').includes(`${U} = rw`)) throw new Error('rule not written');
  });
  await check('user page: effective permissions', async () => { await go('/users/' + U); if (!(await page.content()).includes(`${R}:/trunk`)) throw new Error('no effective perm'); return 'shown'; }, null);
  await check('assign project manager to path', async () => {
    await go('/access-paths/view?path=' + encodeURIComponent(`${R}:/trunk`));
    const f = page.locator('form[action$="/access-paths/managers"]').first();
    if (!(await f.count())) return 'no manager form';
    await f.locator('label', { hasText: U }).locator('input').check().catch(async () => { await f.locator('select').first().selectOption(U); });
    await submit('form[action$="/access-paths/managers"]');
  });
  await check('assign role on user page', async () => {
    await go('/users/' + U);
    const f = page.locator(`form[action$="/users/${U}/roles"]`).first();
    await f.locator('select').selectOption({ label: 'User-Group-Manager' }); await submit(`form[action$="/users/${U}/roles"]`, 'Assign');
  });
  await check('roles overview lists user', async () => { await go('/roles'); if (!(await page.content()).includes('/users/' + U)) throw new Error('missing'); return 'listed'; }, null);
  await check('revoke group permission', async () => {
    await go('/access-paths/view?path=' + encodeURIComponent(`${R}:/trunk`));
    const f = page.locator('form[action$="/access-paths/revoke"]').filter({ has: page.locator(`input[value="@${G}"]`) }).first();
    await f.locator('button').click(); await page.waitForTimeout(200); await confirmIfAsked(); await settle();
    if (fs.readFileSync(dataDir + '/svn/authz', 'utf8').includes(`@${G} = rw`)) throw new Error('still granted');
  });
  await check("admin sets user's password", async () => {
    await go(`/users/${U}/password`); await page.fill('#pw1', PW2); await page.fill('#pw2', PW2); await submit(`form[action$="/users/${U}/password"]`);
  });

  // ----- the user's own session: sign-in, password, two-factor
  const uctx = await browser.newContext({ viewport }); const up = await uctx.newPage();
  up.on('pageerror', e => errors.push('user page: ' + e));
  const uSettle = async () => { await up.waitForLoadState('networkidle').catch(() => {}); await up.waitForTimeout(150); };
  const uLogin = async pw => { await up.goto(base + '/login'); await up.fill('#username', U); await up.fill('#password', pw); await up.click('form[action$="/login"] button.btn-primary'); await uSettle(); };
  const swap = async (fn) => { const p = page; return fn(up, uSettle); };
  let secret = '';
  await check('user: sign in with new password', () => swap(async (p, st) => { await uLogin(PW2); if (!p.url().endsWith('/')) throw new Error('login failed: ' + p.url()); return 'signed in'; }), null);
  await check('user: change own password', () => swap(async (p, st) => {
    await p.goto(base + '/account/password'); await st(); await p.fill('#current', PW2); await p.fill('#pw1', PW); await p.fill('#pw2', PW);
    await p.locator('form[action*="/password"] button:not([type=button])').last().click(); await st();
    return (await p.$$eval('[data-toast]', e => e.map(x => x.dataset.toast + ': ' + x.textContent.trim()))).join(' | ');
  }), null);
  await check('user: enrol two-factor (QR + key + code)', () => swap(async (p, st) => {
    await p.goto(base + '/account/two-factor'); await st();
    if (!(await p.locator('svg[role=img]').count())) throw new Error('no QR');
    secret = (await p.locator('code.mono').first().textContent()).replace(/\s/g, '');
    await p.fill('#enable-pass', PW); await p.fill('#enable-code', totp(secret, step())); await p.click('form[action$="/account/two-factor"] button'); await st();
    const codes = await p.locator('ol li.mono').count(); if (codes !== 10) throw new Error('recovery codes shown: ' + codes);
    return '10 recovery codes shown';
  }), null);
  await check('user: sign in with password + code', () => swap(async (p, st) => {
    await logoutVia(p); await st();
    await uLogin(PW); if (!p.url().includes('/login/verify')) throw new Error('no code step: ' + p.url());
    await p.fill('#code', totp(secret, step() + 1)); await p.click('form[action$="/login/verify"] button'); await st();
    if (!p.url().endsWith('/')) throw new Error('code rejected: ' + p.url()); return 'signed in with code';
  }), null);
  await uctx.close();

  await check('admin: user page shows 2FA badge; reset two-factor', async () => {
    await go('/users/' + U); if (!(await page.content()).includes('Two-factor on')) throw new Error('no badge');
    await submit(`form[action$="/users/${U}/two-factor/reset"]`);
  });
  await check('activity log + search', async () => { await go('/activity?q=' + U); const c = await page.content(); if (!c.includes('user.create') && !c.includes(U)) throw new Error('no entries'); return 'entries'; }, null);
  await check('backups list + download', async () => {
    await go('/backups'); const a = page.locator('a[href*="/backups/"]').first();
    if (!(await a.count())) throw new Error('no backups');
    const [dl] = await Promise.all([page.waitForEvent('download'), a.click()]); return 'downloaded ' + dl.suggestedFilename();
  }, null);
  await check('language switch (de) and back', async () => {
    await page.selectOption('#locale-select', 'de_DE'); await settle();
    const lang = await page.getAttribute('html', 'lang'); await page.selectOption('#locale-select', 'en_US'); await settle();
    if (!lang.startsWith('de')) throw new Error('lang=' + lang); return 'lang=' + lang;
  }, null);
  if (label === 'phone') {
    await check('phone sidebar opens and navigates', async () => {
      await go('/'); await page.click('[data-sidebar-toggle], button[aria-controls=sidebar]'); await page.waitForTimeout(300);
      await page.locator('#sidebar a', { hasText: 'Groups' }).first().click(); await settle();
      if (!page.url().includes('/groups')) throw new Error('nav failed'); return 'navigated';
    }, null);
  }

  // ----- clean up through the UI
  await check('delete access path', async () => { await go('/access-paths/view?path=' + encodeURIComponent(`${R}:/trunk`)); await submit('form[action$="/access-paths/delete"]'); });
  await check('delete group', async () => { await go('/groups/' + G); await submit('form[action$="/groups/delete"]'); });
  await check('delete repository (+ its access paths)', async () => { await go(`/repositories/0/${R}`); await submit('form[action$="/repositories/delete"]'); });
  await check('delete loaded copy', async () => { await go(`/repositories/0/${R}copy`); await submit('form[action$="/repositories/delete"]'); });
  await check('delete user', async () => { await go('/users/' + U); await submit('form[action$="/users/delete"]'); });
  await check('logout', async () => { await logoutVia(page); await settle(); if (!page.url().includes('/login')) throw new Error(page.url()); return 'logged out'; }, null);
  await ctx.close();
}

(async () => {
  const browser = await playwright[ENGINE].launch(ENGINE === 'chromium' ? { executablePath: process.env.CHROME || undefined, headless: true } : { headless: true });
  console.log('engine:', ENGINE, browser.version());
  // fresh install through the UI: setup key, defaults, security step, first admin
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } }); const p = await ctx.newPage();
  await p.goto(base + '/setup');
  const key = fs.readFileSync(dataDir + '/setup.key', 'utf8').trim();
  await p.fill('input[name=key]', key); await p.click('form[action$="/setup/unlock"] button'); await p.waitForLoadState('networkidle');
  await p.click('form[action$="/setup"] button'); await p.waitForLoadState('networkidle');
  // security step: keep two-factor optional here; its enrolment flow is walked further down
  await p.selectOption('select[name="Common|TwoFactor"]', 'optional');
  await p.click('#settings-form button.btn-primary'); await p.waitForLoadState('networkidle');
  const f = p.locator('form[action$="/setup/admin"]').filter({ has: p.locator('input[name=mode][value=create]') });
  await f.locator('input[name=username]').fill(ADMIN[0]); await f.locator('input[name=password]').fill(ADMIN[1]); await f.locator('input[name=password2]').fill(ADMIN[1]);
  await f.locator('button:not([type=button])').last().click(); await p.waitForLoadState('networkidle');
  results.push(`${p.url().endsWith('/') && !fs.existsSync(dataDir + '/setup.key') ? 'PASS' : 'FAIL'} [phone] setup wizard (key, defaults, security, first admin) :: ${p.url()}`);
  await p.screenshot({ path: `${shots}/phone-00-setup_done.png` }); await ctx.close();

  await run('desktop', { width: 1366, height: 900 }, browser);
  n = 0;
  await run('phone', { width: 390, height: 844 }, browser);
  await browser.close();
  console.log(results.join('\n'));
  console.log(`\n${results.filter(r => r.startsWith('PASS')).length}/${results.length} passed`);
})().catch(e => { console.error(e); console.log(results.join('\n')); process.exit(1); });
