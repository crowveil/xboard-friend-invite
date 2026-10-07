'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const root = path.join(__dirname, '../FriendInvite/resources/assets');
const tick = () => new Promise((r) => setTimeout(r, 5));
(async () => {
  const dom = new JSDOM(fs.readFileSync(path.join(root, 'console.html'), 'utf8'), {
    url: 'https://panel.test/plugins/friend_invite/console.html',
    runScripts: 'outside-only',
  });
  const w = dom.window,
    d = w.document,
    el = (id) => d.getElementById(id);
  let closed = false,
    failSave = false,
    failSession = true,
    revision = 'original';
  let config = {
    accepting: true,
    plan_ids: [1],
    invite_days: 7,
    register_url: 'https://panel.test/#/register?code={code}',
    debug_until: 0,
  };
  const calls = [];
  w.confirm = () => true;
  w.localStorage.setItem('XBOARD_ACCESS_TOKEN', JSON.stringify('test-admin'));
  w.fetch = async (url, options) => {
    const action = url.split('/').pop().split('?')[0],
      body = options.body && JSON.parse(options.body);
    calls.push({ action, body });
    let status = 200,
      data = { ok: true };
    if (action === 'session') {
      if (failSession) {
        status = 500;
        data = { error: 'FAILED', trace: 'synthetic-trace', error_file: 'Test.php', error_line: 9 };
      } else
        data = {
          version: '0.1.1',
          access: { open: !closed, expires_at: 2000000000 },
          summary: { invitations: 0 },
        };
    }
    if (action === 'state')
      data = {
        config,
        revision,
        access: { open: true, expires_at: 2000000000 },
        durations: { month: '1 个月' },
        plans: [{ id: 1, name: '朋友', transfer_enable: 10, group_id: 1 }],
        readiness: [],
        telegram: { connected: true, owner_id: '12345' },
        invitations: [],
        page: 1,
        total: 0,
      };
    if (action === 'settings') {
      if (failSave) {
        status = 409;
        data = { error: '设置已变化' };
      } else {
        assert.equal(body.revision, revision);
        config = body;
        revision = 'saved';
      }
    }
    if (action === 'close') closed = true;
    return { ok: status === 200, status, json: async () => JSON.parse(JSON.stringify(data)) };
  };
  w.eval(fs.readFileSync(path.join(root, 'console.js'), 'utf8'));
  await tick();
  assert.match(el('access-title').textContent, /无法检查/);
  assert.match(el('notice').textContent, /synthetic-trace.*Test.php:9/);
  failSession = false;
  el('check').click();
  await tick();
  el('default-days').value = '12';
  el('settings-form').dispatchEvent(new w.Event('input', { bubbles: true }));
  el('unpair').click();
  await tick();
  assert.equal(el('default-days').value, '12');
  assert.equal(el('dirty').hidden, false);
  el('close').click();
  await tick();
  assert.equal(el('close-dialog').hidden, false);
  el('close-cancel').click();
  await tick();
  assert.equal(closed, false);
  el('close').click();
  await tick();
  failSave = true;
  el('close-save').click();
  await tick();
  assert.equal(closed, false);
  assert.equal(el('default-days').value, '12');
  assert.equal(el('dirty').hidden, false);
  failSave = false;
  el('close-save').click();
  await tick();
  assert.equal(closed, true);
  assert.equal(config.invite_days, 12);
  assert.equal(el('workspace').hidden, true);
  closed = false;
  el('check').click();
  await tick();
  el('bot-token').value = 'synthetic-unsaved';
  el('close').click();
  await tick();
  assert.equal(el('close-dialog').hidden, false);
  el('close-save').click();
  await tick();
  assert.equal(closed, false);
  assert.equal(calls.filter((c) => c.action === 'connect').length, 0);
  el('close-discard').click();
  await tick();
  assert.equal(el('bot-token').value, '');
  closed = false;
  el('check').click();
  await tick();
  w.dispatchEvent(
    new w.StorageEvent('storage', { key: 'friend-invite-console-revoked', newValue: 'other-tab' }),
  );
  assert.equal(el('workspace').hidden, true);
  assert.equal(el('register-url').value, '');
  dom.window.close();
  console.log(
    'PASS: failed access diagnostics, draft retention, revision, save/discard/cancel close, token protection and cross-tab revoke',
  );
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
