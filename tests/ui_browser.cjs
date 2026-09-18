'use strict';
// Real browser / responsive layout, synthetic administrator and API only.
const assert = require('node:assert/strict'),
  fs = require('node:fs'),
  path = require('node:path');
const { chromium } = require('playwright');
(async () => {
  const portable = process.env.FI_CHROMIUM_MODULE ? require(process.env.FI_CHROMIUM_MODULE) : null;
  const browser = await chromium.launch(
    portable
      ? {
          headless: true,
          executablePath: process.env.FI_CHROMIUM_EXECUTABLE || (await portable.executablePath()),
          args: portable.args,
        }
      : { headless: true },
  );
  try {
    const ctx = await browser.newContext({
        viewport: { width: 1280, height: 960 },
      }),
      p = await ctx.newPage(),
      errors = [];
    p.on('pageerror', (e) => errors.push(e.message));
    let open = true;
    await ctx.addInitScript(() =>
      localStorage.setItem('XBOARD_ACCESS_TOKEN', JSON.stringify({ value: 'synthetic-admin' })),
    );
    await ctx.route('https://panel.example.test/**', async (route) => {
      const req = route.request(),
        url = new URL(req.url()),
        name = url.pathname.split('/').pop();
      if (url.pathname.startsWith('/api/')) {
        assert.equal(req.headers().authorization, 'synthetic-admin');
        let data = { ok: true };
        if (name === 'session')
          data = {
            access: { open, expires_at: Date.now() / 1000 + 3600 },
            version: '0.1.0',
            summary: {
              invitations: 0,
              accepting: true,
              telegram_connected: false,
            },
          };
        if (name === 'state')
          data = {
            access: { open, expires_at: Date.now() / 1000 + 3600 },
            config: {
              accepting: true,
              plan_ids: [1],
              invite_days: 7,
              register_url: 'https://panel.example.test/#/register?code={code}',
              debug_until: 0,
            },
            durations: {
              day: '1 天',
              month: '1 个月',
              year: '1 年',
              three_years: '3 年',
              forever: '永久',
            },
            plans: [{ id: 1, name: '亲友套餐', transfer_enable: 100, group_id: 1 }],
            readiness: [],
            telegram: { connected: false },
            page: 1,
            total: 0,
            invitations: [],
          };
        if (name === 'create')
          data = {
            invitation: {
              id: 'example',
              url: 'https://panel.example.test/#/register?code=synthetic',
              plan_name: '亲友套餐',
              duration_label: '3 年',
            },
          };
        if (name === 'close') open = false;
        return route.fulfill({ json: data });
      }
      const file = path.join(__dirname, '../FriendInvite/resources/assets', name);
      return route.fulfill({
        body: fs.readFileSync(file),
        contentType: name.endsWith('.html')
          ? 'text/html'
          : name.endsWith('.css')
            ? 'text/css'
            : 'application/javascript',
      });
    });
    await p.goto('https://panel.example.test/plugins/friend_invite/console.html');
    await p.locator('#workspace').waitFor({ state: 'visible' });
    await p.locator('#duration').selectOption('three_years');
    await p.locator('#note').fill('小林');
    await p.getByRole('button', { name: '生成邀请链接' }).click();
    await p.locator('#result').waitFor({ state: 'visible' });
    assert.match(await p.locator('#result-url').inputValue(), /synthetic/);
    fs.mkdirSync(path.join(__dirname, 'runtime'), { recursive: true });
    await p.screenshot({
      path: path.join(__dirname, 'runtime/desktop.png'),
      fullPage: true,
    });
    await p.setViewportSize({ width: 390, height: 844 });
    await p.locator('[data-tab=settings]').click();
    assert.equal(await p.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    await p.screenshot({
      path: path.join(__dirname, 'runtime/mobile.png'),
      fullPage: true,
    });
    await p.locator('#close').click();
    await p.locator('#landing').waitFor({ state: 'visible' });
    assert.equal(await p.locator('#result-url').inputValue(), '');
    assert.deepEqual(errors, []);
    console.log(
      'PASS: real Chromium, 1280px/390px layout, create invitation, close console, zero page errors',
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
