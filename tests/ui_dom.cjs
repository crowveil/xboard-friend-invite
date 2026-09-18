'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const root = path.join(__dirname, '../FriendInvite/resources/assets');
const config = {
  accepting: true,
  plan_ids: [1],
  invite_days: 7,
  register_url: 'https://panel.example.test/#/register?code={code}',
  debug_until: 0,
};
const state = {
  config,
  access: { open: true, expires_at: 2000000000 },
  durations: {
    day: '1 天',
    month: '1 个月',
    year: '1 年',
    three_years: '3 年',
    forever: '永久',
  },
  plans: [
    { id: 1, name: '朋友套餐', transfer_enable: 100, group_id: 1 },
    {
      id: 2,
      name: '<img src=x onerror=alert(1)>',
      transfer_enable: 200,
      group_id: 1,
    },
  ],
  readiness: [],
  telegram: { connected: false },
  page: 1,
  total: 0,
  invitations: [],
};
const tick = () => new Promise((resolve) => setTimeout(resolve, 5));
(async () => {
  const dom = new JSDOM(fs.readFileSync(path.join(root, 'console.html'), 'utf8'), {
    url: 'https://panel.example.test/plugins/friend_invite/console.html',
    runScripts: 'outside-only',
  });
  const w = dom.window,
    d = w.document,
    calls = [];
  let closed = false,
    failCreate = true,
    copied = '';
  w.localStorage.setItem('XBOARD_ACCESS_TOKEN', JSON.stringify({ value: 'fake-admin' }));
  w.confirm = () => true;
  w.navigator.clipboard = { writeText: async (s) => (copied = s) };
  w.fetch = async (url, opts) => {
    assert.ok(url.startsWith('/api/v1/friend-invite/admin/'));
    assert.equal(opts.headers.Authorization, 'fake-admin');
    const action = url.split('/').pop().split('?')[0],
      body = opts.body ? JSON.parse(opts.body) : undefined;
    calls.push({ action, body });
    let data = { ok: true },
      ok = true,
      status = 200;
    if (action === 'session')
      data = {
        access: { open: !closed, expires_at: 2000000000 },
        version: '0.1.0',
        summary: { invitations: 0, accepting: true, telegram_connected: false },
      };
    if (action === 'state') data = structuredClone(state);
    if (action === 'settings') Object.assign(config, body);
    if (action === 'create') {
      if (failCreate) {
        failCreate = false;
        throw new Error('network interrupted');
      }
      data = {
        invitation: {
          id: 'test-id',
          url: 'https://panel.example.test/#/register?code=SYNTHETIC',
          plan_name: '朋友套餐',
          duration_label: '1 年',
        },
      };
    }
    if (action === 'close') closed = true;
    if (action === 'pair') data = { command: '/bind SYNTHETIC_PAIR' };
    return { ok, status, json: async () => data };
  };
  w.eval(fs.readFileSync(path.join(root, 'console.js'), 'utf8'));
  await tick();
  assert.equal(d.getElementById('workspace').hidden, false);
  assert.equal(d.getElementById('duration').options.length, 5);
  assert.equal(d.querySelectorAll('#plans input[type=checkbox]').length, 2);
  assert.equal(d.querySelectorAll('#plans img').length, 0);
  d.getElementById('duration').value = 'year';
  d.getElementById('create-form').dispatchEvent(new w.Event('submit', { cancelable: true }));
  await tick();
  d.getElementById('create-form').dispatchEvent(new w.Event('submit', { cancelable: true }));
  await tick();
  const creates = calls.filter((x) => x.action === 'create');
  assert.equal(creates.length, 2);
  assert.equal(creates[0].body.request_id, creates[1].body.request_id);
  assert.equal(creates[1].body.duration, 'year');
  assert.match(d.getElementById('result-url').value, /SYNTHETIC/);
  d.getElementById('copy-result').click();
  await tick();
  assert.match(copied, /SYNTHETIC/);
  d.querySelector('#plans input[value="2"]').checked = true;
  d.getElementById('settings-form').dispatchEvent(new w.Event('input', { bubbles: true }));
  d.getElementById('settings-form').dispatchEvent(new w.Event('submit', { cancelable: true }));
  await tick();
  assert.deepEqual(calls.find((x) => x.action === 'settings').body.plan_ids, [1, 2]);
  d.getElementById('pair').disabled = false;
  d.getElementById('pair').click();
  await tick();
  assert.equal(d.getElementById('pair-command').value, '/bind SYNTHETIC_PAIR');
  d.getElementById('close').click();
  await tick();
  assert.equal(d.getElementById('workspace').hidden, true);
  assert.equal(d.getElementById('result-url').value, '');
  assert.equal(d.getElementById('pair-command').value, '');
  assert.equal(d.querySelectorAll('#plans input').length, 0);
  dom.window.close();
  console.log(
    'PASS: five periods, safe rendering, checkbox sets, stable retry ID, clipboard, pairing, close clears data',
  );
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
