'use strict';
(() => {
  const $ = (id) => document.getElementById(id);
  const base =
    new URL('../../..', location.href).pathname.replace(/\/$/, '') + '/api/v1/friend-invite/admin/';
  let active = false,
    busy = false,
    dirty = false,
    epoch = 0,
    page = 1,
    current,
    requestId;
  const stamp = (v) => (v ? new Date(v * 1000).toLocaleString() : '—');
  const notice = (s, error = false) => {
    $('notice').textContent = s;
    $('notice').classList.toggle('error', error);
  };
  const auth = () => {
    try {
      const v = JSON.parse(localStorage.getItem('XBOARD_ACCESS_TOKEN'));
      return typeof v === 'string' ? v : v?.value || '';
    } catch {
      return '';
    }
  };
  async function api(path, body) {
    const generation = epoch,
      token = auth();
    if (!token) {
      lock();
      throw new Error('请先在同一域名登录 XBoard 管理后台。');
    }
    const response = await fetch(base + path, {
      method: body === undefined ? 'GET' : 'POST',
      headers: {
        Authorization: token,
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
      cache: 'no-store',
      body: body === undefined ? undefined : JSON.stringify(body),
    });
    const data = await response.json().catch(() => ({}));
    if (generation !== epoch) throw new Error('访问状态已改变，请重新检查。');
    if (!response.ok) {
      if ([401, 403].includes(response.status)) lock();
      throw new Error(
        data.error === 'CONSOLE_CLOSED'
          ? '管理访问已关闭或到期，请在插件配置中重新开放。'
          : data.error || data.message || `请求失败（${response.status}），请检查输入及登录状态。`,
      );
    }
    return data;
  }
  function setDirty(v) {
    dirty = v;
    $('dirty').hidden = !v;
  }
  function lock() {
    epoch++;
    active = false;
    current = undefined;
    requestId = undefined;
    setDirty(false);
    $('workspace').hidden = $('session-actions').hidden = true;
    $('landing').hidden = false;
    ['invitations', 'plans', 'plan', 'duration'].forEach((id) => $(id).replaceChildren());
    [
      'result-url',
      'result-label',
      'pair-command',
      'bot-token',
      'register-url',
      'email',
      'note',
    ].forEach((id) => {
      if ('value' in $(id)) $(id).value = '';
      else $(id).textContent = '';
    });
    ['bot-status', 'diagnostics', 'debug-status', 'readiness'].forEach(
      (id) => ($(id).textContent = ''),
    );
    $('pair-result').hidden = $('result').hidden = true;
    $('access-title').textContent = '管理控制台已关闭';
    $('access-help').textContent =
      '在 XBoard → 插件管理 → 好友邀请 → 配置，开放管理控制台并保存。邀请注册和 Telegram 继续运行。';
  }
  function tab(name) {
    document
      .querySelectorAll('[data-tab]')
      .forEach((b) => b.setAttribute('aria-current', b.dataset.tab === name ? 'page' : 'false'));
    ['invites', 'settings', 'telegram', 'debug'].forEach(
      (id) => ($('tab-' + id).hidden = name !== id),
    );
  }
  async function check(enter = false) {
    const s = await api('session');
    $('version').textContent = `XBOARD · FRIEND INVITE · v${s.version}`;
    $('footer-version').textContent = s.version;
    $('summary').textContent =
      `当前保存：${s.summary.invitations} 份邀请 · 好友注册${s.summary.accepting ? '开放' : '关闭'} · TG ${s.summary.telegram_connected ? '已连接' : '未连接'}`;
    if (!s.access.open) {
      lock();
      return;
    }
    $('access-title').textContent = '管理权限已开放';
    $('access-help').textContent = '访问有效期至 ' + stamp(s.access.expires_at);
    $('expires').textContent = '管理访问至 ' + stamp(s.access.expires_at);
    if (enter && !active) {
      await load();
      active = true;
      $('workspace').hidden = $('session-actions').hidden = false;
      $('landing').hidden = true;
      tab(current.config.plan_ids.length ? 'invites' : 'settings');
    }
  }
  function option(parent, value, text) {
    const o = document.createElement('option');
    o.value = value;
    o.textContent = text;
    parent.append(o);
  }
  function renderInvitations(s) {
    $('invitations').replaceChildren();
    if (!s.invitations.length)
      $('invitations').textContent = '还没有邀请。在上方选好套餐和期限，就可以生成第一份。';
    for (const inv of s.invitations) {
      const item = document.createElement('article');
      item.className = 'invite';
      const title = document.createElement('h3');
      title.textContent = `${inv.note || inv.plan_name} · ${inv.duration_label}`;
      const sub = document.createElement('p');
      const states = {
        pending: '待领取',
        used: '已领取',
        revoked: '已撤销',
        expired: '已过期',
      };
      sub.textContent = `${states[inv.state]} · ${inv.plan_name} · 邀请有效至 ${stamp(inv.expires_at)}${inv.used_email ? ' · ' + inv.used_email : ''}${inv.email ? ' · 限定邮箱 ' + inv.email : ''}`;
      item.append(title, sub);
      if (inv.url) {
        const actions = document.createElement('div');
        actions.className = 'actions';
        const copy = document.createElement('button');
        copy.textContent = '复制链接';
        copy.onclick = () => run(() => copyText(inv.url));
        const revoke = document.createElement('button');
        revoke.textContent = '撤销';
        revoke.className = 'danger';
        revoke.onclick = () =>
          run(async () => {
            if (!confirm('撤销后此链接将无法注册，确定吗？')) return;
            await api('revoke', { id: inv.id });
            await load(true);
            notice('邀请已撤销。');
          });
        actions.append(copy, revoke);
        item.append(actions);
      }
      $('invitations').append(item);
    }
    $('page').textContent = `第 ${s.page} 页 · 共 ${s.total} 份`;
    $('prev').disabled = s.page <= 1;
    $('next').disabled = s.page * 20 >= s.total;
  }
  async function load(listOnly = false) {
    const s = await api('state?page=' + page);
    current = s;
    renderInvitations(s);
    $('expires').textContent = '管理访问至 ' + stamp(s.access.expires_at);
    const tg = s.telegram;
    $('bot-status').textContent = tg.connected
      ? `已连接 @${tg.username} · ${tg.owner_id ? '已绑定 TG ID ' + tg.owner_id : '尚未绑定管理员'}\n${tg.webhook_url}`
      : '尚未连接，可只使用网页管理。';
    $('pair').disabled = !tg.connected || !!tg.owner_id;
    $('unpair').disabled = !tg.owner_id;
    $('disconnect').disabled = !tg.connected;
    if (listOnly) return;
    const c = s.config;
    $('accepting').checked = c.accepting;
    $('default-days').value = c.invite_days;
    $('invite-days').value = c.invite_days;
    $('register-url').value = c.register_url;
    $('plans').replaceChildren();
    $('plan').replaceChildren();
    $('duration').replaceChildren();
    for (const p of s.plans) {
      const label = document.createElement('label');
      label.className = 'check';
      const input = document.createElement('input');
      input.type = 'checkbox';
      input.value = p.id;
      input.checked = c.plan_ids.includes(Number(p.id));
      label.append(
        input,
        document.createTextNode(
          `${p.name} · ${p.transfer_enable} GB · 权限组 ${p.group_id ?? '未设置'}`,
        ),
      );
      $('plans').append(label);
      if (input.checked) option($('plan'), p.id, p.name);
    }
    if (!s.plans.length) $('plans').textContent = '请先在 XBoard 创建套餐。';
    for (const [value, label] of Object.entries(s.durations)) option($('duration'), value, label);
    $('duration').value = 'month';
    $('readiness').textContent = s.readiness.length
      ? s.readiness.join('\n')
      : 'XBoard 注册设置已满足要求。';
    $('debug-status').textContent =
      c.debug_until > Date.now() / 1000
        ? '调试开放至 ' + stamp(c.debug_until)
        : '调试已关闭；必要故障仍保留脱敏事件。';
    setDirty(false);
  }
  async function copyText(text) {
    try {
      await navigator.clipboard.writeText(text);
      notice('已复制。');
    } catch {
      $('result-url').value = text;
      $('result-label').textContent = '浏览器未允许剪贴板，请选中文本复制。';
      $('result').hidden = false;
      tab('invites');
      $('result-url').focus();
      $('result-url').select();
    }
  }
  async function run(fn) {
    if (busy) return;
    busy = true;
    document.body.setAttribute('aria-busy', 'true');
    try {
      await fn();
    } catch (e) {
      notice(e.message, true);
    } finally {
      busy = false;
      document.body.removeAttribute('aria-busy');
    }
  }
  const click = (id, fn) => $(id).addEventListener('click', () => run(fn));
  document.querySelectorAll('[data-tab]').forEach((b) => (b.onclick = () => tab(b.dataset.tab)));
  $('settings-form').addEventListener('input', () => setDirty(true));
  $('create-form').addEventListener('input', () => {
    requestId = undefined;
  });
  $('settings-form').addEventListener('submit', (e) => {
    e.preventDefault();
    run(async () => {
      await api('settings', {
        accepting: $('accepting').checked,
        plan_ids: Array.from($('plans').querySelectorAll('input:checked'), (x) => Number(x.value)),
        invite_days: Number($('default-days').value),
        register_url: $('register-url').value.trim(),
      });
      await load();
      notice('设置已保存。');
    });
  });
  $('create-form').addEventListener('submit', (e) => {
    e.preventDefault();
    run(async () => {
      if (dirty) throw new Error('请先保存设置，再生成邀请。');
      requestId ||= crypto.randomUUID();
      const s = await api('create', {
        plan_id: Number($('plan').value),
        duration: $('duration').value,
        note: $('note').value,
        email: $('email').value,
        invite_days: Number($('invite-days').value),
        request_id: requestId,
      });
      $('result-url').value = s.invitation.url || '';
      $('result-label').textContent = `${s.invitation.plan_name} · ${s.invitation.duration_label}`;
      $('result').hidden = false;
      requestId = undefined;
      await load(true);
      notice('邀请已生成，请把链接发给朋友。');
    });
  });
  $('bot-form').addEventListener('submit', (e) => {
    e.preventDefault();
    run(async () => {
      if (dirty) throw new Error('请先保存设置。');
      await api('telegram/connect', { token: $('bot-token').value.trim() });
      $('bot-token').value = '';
      await load();
      notice('机器人已连接，接下来生成管理员绑定指令。');
    });
  });
  click('bot-refresh', () => load(true));
  click('check', () => check(true));
  click('refresh', () => load(true));
  click('prev', async () => {
    page--;
    await load(true);
  });
  click('next', async () => {
    page++;
    await load(true);
  });
  click('copy-result', () => copyText($('result-url').value));
  click('copy-pair', () => copyText($('pair-command').value));
  click('renew', async () => {
    const s = await api('renew', {});
    $('expires').textContent = '管理访问至 ' + stamp(s.access.expires_at);
    notice('已延长至 60 分钟。');
  });
  click('close', async () => {
    if (dirty && !confirm('设置尚未保存，仍要关闭吗？')) return;
    await api('close', {});
    lock();
    notice('控制台已关闭。邀请注册与机器人继续运行。');
  });
  click('pair', async () => {
    const s = await api('telegram/pair', {});
    $('pair-command').value = s.command;
    $('pair-result').hidden = false;
    notice('请在你与机器人私聊中发送绑定指令，再刷新本页查看状态。');
  });
  click('unpair', async () => {
    if (!confirm('解除后当前 TG 账号将失去管理权限，继续吗？')) return;
    await api('telegram/unpair', {});
    $('pair-result').hidden = true;
    $('pair-command').value = '';
    await load();
  });
  click('disconnect', async () => {
    if (!confirm('将删除此机器人的 Webhook，网页邀请仍可使用。继续吗？')) return;
    await api('telegram/disconnect', {});
    $('pair-result').hidden = true;
    $('pair-command').value = '';
    await load();
  });
  for (const [id, enabled] of [
    ['debug-on', true],
    ['debug-off', false],
  ])
    click(id, async () => {
      const s = await api('debug', { enabled });
      $('debug-status').textContent = enabled ? '调试开放至 ' + stamp(s.until) : '调试已关闭。';
    });
  click('export', async () => {
    const s = await api('export');
    const text = JSON.stringify(s, null, 2);
    $('diagnostics').textContent = text;
    const url = URL.createObjectURL(new Blob([text], { type: 'application/json' }));
    const a = document.createElement('a');
    a.href = url;
    a.download = 'friend-invite-diagnostics.json';
    a.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  });
  window.addEventListener('beforeunload', (e) => {
    if (dirty) {
      e.preventDefault();
      e.returnValue = '';
    }
  });
  setInterval(() => {
    if (!busy && active) run(() => check());
  }, 15000);
  run(() => check(true));
})();
