<?php

namespace Plugin\FriendInvite\Services;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class Bot
{
    public static function receive(array $u): void
    {
        $s = Store::read('bot');
        if (empty($s['connected']) || !isset($u['update_id']) || !is_int($u['update_id'])) {
            return;
        }
        $cb = $u['callback_query'] ?? null;
        $msg = $cb['message'] ?? $u['message'] ?? [];
        $from = $cb['from'] ?? $msg['from'] ?? [];
        $id = (string) ($from['id'] ?? '');
        if (!ctype_digit($id) || $id === '0' || !empty($from['is_bot']) || ($msg['chat']['type'] ?? '') !== 'private' || (string) ($msg['chat']['id'] ?? '') !== $id) {
            return;
        }
        Store::locked('bot', function ($s) use ($u, $cb, $msg, $id) {
            if (empty($s['connected'])) {
                return;
            }
            $text = trim((string) ($msg['text'] ?? ''));
            $key = $s['epoch'].':'.$u['update_id'];
            if (str_starts_with($text, '/bind ') && !$cb && empty($s['owner_id'])) {
                Store::locked('pair', function ($pair) use ($s, $id, $text, $key) {
                    $code = substr($text, 6);
                    if (empty($pair['hash']) || ($pair['expires_at'] ?? 0) <= now()->timestamp || ($pair['epoch'] ?? '') !== $s['epoch'] || !hash_equals($pair['hash'], hash('sha256', $code))) {
                        return;
                    }
                    if (!User::where('id', $pair['admin_id'])->where('is_admin', true)->where('banned', false)->exists()) {
                        return;
                    }
                    Store::put('bot', array_replace($s, ['owner_id' => $id, 'admin_id' => (int) $pair['admin_id']]));
                    Store::put('pair', []);
                    self::send($key.':bind', $id, '绑定成功。只有此 TG 账号可以管理邀请。', [[['text' => '生成邀请', 'callback_data' => 'new'], ['text' => '最近邀请', 'callback_data' => 'recent']]]);
                });
                return;
            }
            if (($s['owner_id'] ?? null) !== $id || !User::where('id', $s['admin_id'])->where('is_admin', true)->where('banned', false)->exists()) {
                return;
            }
            if (!DB::table('friend_invite_updates')->insertOrIgnore(['id' => $key, 'created_at' => now()->timestamp])) {
                return;
            }
            if ($cb && isset($cb['id']) && is_string($cb['id'])) {
                Outbox::enqueue($key.':ack', 'answerCallbackQuery', ['callback_query_id' => $cb['id']]);
            }
            try {
                self::action($key, $s, $id, $cb ? (string) ($cb['data'] ?? '') : $text);
            } catch (Failure $e) {
                self::send($key.':error', $id, $e->reason);
            }
        });
    }

    private static function action(string $key, array $s, string $id, string $action): void
    {
        if ($action === '/start' || $action === '/help') {
            self::send($key, $id, '好友邀请：选择套餐和期限，生成一次性注册链接。', [[['text' => '生成邀请', 'callback_data' => 'new'], ['text' => '最近邀请', 'callback_data' => 'recent']]]);
        } elseif ($action === 'new' || $action === '/new' || str_starts_with($action, '/new ')) {
            $draftId = bin2hex(random_bytes(16));
            $draft = ['owner_id' => $id, 'epoch' => $s['epoch'], 'expires_at' => now()->timestamp + 1200, 'note' => mb_substr(str_starts_with($action, '/new ') ? substr($action, 5) : '', 0, 120)];
            Store::put('draft:'.$draftId, $draft);
            self::catalog($key, $id, $draftId, 0);
        } elseif ($action === 'recent' || $action === '/list') {
            $rows = Invitations::table()->where('created_by', $s['admin_id'])->orderByDesc('created_at')->limit(10)->get();
            if ($rows->isEmpty()) {
                self::send($key, $id, '还没有邀请。发送 /new 开始。');
            } else {
                $buttons = [];
                foreach ($rows as $r) {
                    $p = Invitations::present($r);
                    $buttons[] = [['text' => mb_substr(($p['note'] ?: $p['plan_name']).' · '.$p['duration_label'].' · '.self::stateLabel($p['state']), 0, 55), 'callback_data' => 'view:'.$r->id]];
                }
                self::send($key, $id, '最近 10 份邀请', $buttons);
            }
        } elseif (preg_match('/^(view|revoke):([a-f0-9]{32})$/D', $action, $m)) {
            $row = Invitations::table()->where('id', $m[2])->where('created_by', $s['admin_id'])->first();
            if (!$row) {
                throw new Failure('邀请不存在');
            }
            if ($m[1] === 'revoke') {
                Invitations::revoke($row->id);
            }
            self::show($key, $id, Invitations::present(Invitations::table()->where('id', $row->id)->first()));
        } elseif (preg_match('/^(pg|p|t|make):([a-f0-9]{32})(?::([a-z0-9_]+))?$/D', $action, $m)) {
            $d = Store::read('draft:'.$m[2]);
            if (($d['owner_id'] ?? '') !== $id || ($d['epoch'] ?? '') !== $s['epoch'] || ($d['expires_at'] ?? 0) <= now()->timestamp) {
                throw new Failure('菜单已过期，请发送 /new 重新开始');
            }
            if (!empty($d['invitation_id'])) {
                self::show($key, $id, Invitations::present(Invitations::table()->where('id', $d['invitation_id'])->first()));
                return;
            }
            if ($m[1] === 'pg') {
                self::catalog($key, $id, $m[2], max(0, (int) ($m[3] ?? 0)));
                return;
            }
            if ($m[1] === 'p') {
                $planId = (int) ($m[3] ?? 0);
                if (!in_array($planId, Settings::get()['plan_ids'], true) || !Plan::find($planId)) {
                    throw new Failure('套餐已不可用');
                }
                $d['plan_id'] = $planId;
                unset($d['duration']);
                Store::put('draft:'.$m[2], $d);
                $buttons = [];
                foreach (Duration::OPTIONS as $term => $label) {
                    $buttons[] = [['text' => $label, 'callback_data' => 't:'.$m[2].':'.$term]];
                }
                self::send($key, $id, '请选择使用期限；从好友注册成功时开始计算。永久沿用 XBoard 不自动重置流量的行为。', $buttons);
            } elseif ($m[1] === 't') {
                $term = $m[3] ?? '';
                Duration::expires($term);
                if (empty($d['plan_id'])) {
                    throw new Failure('请先选择套餐');
                }
                $d['duration'] = $term;
                Store::put('draft:'.$m[2], $d);
                self::send($key, $id, '套餐：'.(Plan::find($d['plan_id'])?->name ?? '已删除').'；期限：'.Duration::OPTIONS[$term].'。邀请 '.Settings::get()['invite_days'].' 天内有效，仅限一个新账号。', [[['text' => '确认生成邀请', 'callback_data' => 'make:'.$m[2]]]]);
            } elseif ($m[1] === 'make') {
                if (empty($d['plan_id']) || empty($d['duration'])) {
                    throw new Failure('请先选择套餐和期限');
                }
                // The draft ID, not update_id, makes repeated button presses idempotent.
                $p = Invitations::create((int) $s['admin_id'], $d, 'tg:'.$m[2]);
                $d['invitation_id'] = $p['id'];
                Store::put('draft:'.$m[2], $d);
                self::show($key, $id, $p);
            }
        } else {
            self::send($key, $id, '发送 /new 生成邀请，/new 备注 可附带朋友备注；/list 查看最近邀请。');
        }
    }

    private static function catalog(string $key, string $id, string $draft, int $page): void
    {
        $plans = Plan::whereIn('id', Settings::get()['plan_ids'])->orderBy('sort')->orderBy('id')->get();
        if ($plans->isEmpty()) {
            throw new Failure('请先在管理后台选择允许赠送的套餐');
        }
        $buttons = [];
        foreach ($plans->slice($page * 12, 12) as $p) {
            $buttons[] = [['text' => mb_substr($p->name, 0, 50), 'callback_data' => 'p:'.$draft.':'.$p->id]];
        }
        if ($page > 0) {
            $buttons[] = [['text' => '上一页', 'callback_data' => 'pg:'.$draft.':'.($page - 1)]];
        }
        if ($plans->count() > ($page + 1) * 12) {
            $buttons[] = [['text' => '下一页', 'callback_data' => 'pg:'.$draft.':'.($page + 1)]];
        }
        self::send($key, $id, '请选择赠送套餐', $buttons);
    }

    private static function show(string $key, string $id, array $p): void
    {
        $text = ($p['note'] ? $p['note']."\n" : '').$p['plan_name'].' · '.$p['duration_label'].' · '.self::stateLabel($p['state']);
        $buttons = [];
        if ($p['url']) {
            $text .= "\n\n".$p['url']."\n\n请将链接发给朋友注册。";
            if (mb_strlen($p['url']) <= 256) {
                $buttons[] = [['text' => '复制邀请链接', 'copy_text' => ['text' => $p['url']]]];
            }
            $buttons[] = [['text' => '撤销此邀请', 'callback_data' => 'revoke:'.$p['id']]];
        }
        self::send($key, $id, $text, $buttons);
    }

    private static function stateLabel(string $s): string
    {
        return ['pending' => '待领取', 'used' => '已领取', 'revoked' => '已撤销', 'expired' => '已过期'][$s];
    }

    private static function send(string $key, string $id, string $text, array $buttons = []): void
    {
        $data = ['chat_id' => $id, 'text' => $text, 'link_preview_options' => ['is_disabled' => true]];
        if ($buttons) {
            $data['reply_markup'] = ['inline_keyboard' => $buttons];
        }
        Outbox::enqueue($key, 'sendMessage', $data);
    }

    public static function notifyClaim(object $invite, User $user): void
    {
        $s = Store::read('bot');
        if (empty($s['owner_id']) || (int) ($s['admin_id'] ?? 0) !== (int) $invite->created_by) {
            return;
        }
        $d = Store::unpack($invite->details);
        self::send('claim:'.$invite->id, $s['owner_id'], '好友邀请已领取：'.($d['note'] ?: '未填写备注').'；套餐 '.(Plan::find($invite->plan_id)?->name ?? '').'；期限 '.Duration::OPTIONS[$invite->duration].'。领取账号可在后台查看。');
    }
}
