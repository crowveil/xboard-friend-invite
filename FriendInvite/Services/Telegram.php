<?php

namespace Plugin\FriendInvite\Services;

use Illuminate\Support\Facades\Http;

final class Telegram
{
    public static function call(string $token, string $method, array $data = []): array
    {
        try {
            $response = Http::connectTimeout(3)->timeout(8)->asJson()->post('https://api.telegram.org/bot'.$token.'/'.$method, $data);
            if (!$response->successful() || $response->json('ok') !== true) {
                Diagnostics::record('TELEGRAM_API_FAILED', ['http_status' => $response->status()], true);
                throw new Failure('TG 请求失败，请检查 Token、服务器网络和机器人状态');
            }
            $result = $response->json('result');
            return is_array($result) ? $result : ['ok' => (bool) $result];
        } catch (Failure $e) {
            throw $e;
        } catch (\Throwable) {
            // HTTP exceptions include the token-bearing URL: never log them.
            Diagnostics::record('TELEGRAM_NETWORK_FAILED', [], true);
            throw new Failure('无法连接 Telegram，请检查服务器网络');
        }
    }

    public static function connect(string $token, string $origin): array
    {
        if (!preg_match('/^[0-9]{5,20}:[A-Za-z0-9_-]{20,200}$/D', $token)) {
            throw new Failure('机器人 Token 格式不正确');
        }
        if (hash_equals((string) admin_setting('telegram_bot_token', ''), $token)) {
            throw new Failure('请使用独立机器人，不要复用 XBoard 现有机器人的 Token');
        }
        if (!str_starts_with($origin, 'https://')) {
            throw new Failure('TG Webhook 需要可公开访问的 HTTPS 地址，请先配置反向代理 HTTPS');
        }
        $old = Store::read('bot');
        if (!empty($old['pending'])) {
            throw new Failure('机器人操作等待恢复，请点击恢复连接操作', 409);
        }
        $same = !empty($old['token']) && hash_equals($old['token'], $token);
        if (!empty($old['connected']) && !$same) {
            throw new Failure('请先断开当前机器人，再连接另一只机器人');
        }
        $url = $origin.'/api/v1/friend-invite/telegram';
        $me = self::call($token, 'getMe');
        if (empty($me['is_bot']) || empty($me['username'])) {
            throw new Failure('Token 未对应有效机器人');
        }
        $remote = self::call($token, 'getWebhookInfo');
        if (!empty($remote['url']) && $remote['url'] !== $url) {
            throw new Failure('此机器人已连接其他 Webhook；请使用新机器人或先在原服务解除连接');
        }
        Store::locked('bot', function ($current) use ($old, $same, $token, $me, $url) {
            if ($current !== $old) {
                throw new Failure('机器人状态已变化，请刷新后重试', 409);
            }
            $state = $same ? $old : ['epoch' => bin2hex(random_bytes(16)), 'owner_id' => null, 'admin_id' => null];
            $state = array_replace($state, ['token' => $token, 'secret' => bin2hex(random_bytes(32)), 'username' => $me['username'], 'webhook_url' => $url, 'connected' => false, 'pending' => 'connect']);
            Store::put('bot', $state);
        });
        return self::recover();
    }

    /** Reapply the saved operation with the same secret; no new pairing is created. */
    public static function recover(): array
    {
        Store::locked('bot', function ($s) {
            $pending = $s['pending'] ?? null;
            if (!$pending) {
                return;
            }
            $remote = self::call($s['token'], 'getWebhookInfo');
            if (!empty($remote['url']) && $remote['url'] !== $s['webhook_url']) {
                throw new Failure('机器人 Webhook 已由其他服务接管，请先核对远端设置', 409);
            }
            if ($pending === 'connect') {
                self::call($s['token'], 'setWebhook', ['url' => $s['webhook_url'], 'secret_token' => $s['secret'], 'allowed_updates' => ['message', 'callback_query'], 'drop_pending_updates' => false]);
                $s['connected'] = true;
                unset($s['pending']);
                Store::put('bot', $s);
                Diagnostics::record('TELEGRAM_CONNECTED');
            } elseif ($pending === 'disconnect') {
                self::call($s['token'], 'deleteWebhook', ['drop_pending_updates' => false]);
                Store::put('bot', []);
                Store::put('pair', []);
                Diagnostics::record('TELEGRAM_DISCONNECTED');
            } else {
                throw new Failure('机器人恢复状态无效，请检查诊断', 409);
            }
        });
        return self::summary();
    }

    public static function summary(): array
    {
        $s = Store::read('bot');
        return ['connected' => (bool) ($s['connected'] ?? false), 'username' => $s['username'] ?? null,
            'pending' => $s['pending'] ?? null, 'owner_id' => $s['owner_id'] ?? null, 'webhook_url' => $s['webhook_url'] ?? null];
    }

    public static function pair(int $adminId): string
    {
        return Store::locked('bot', function ($s) use ($adminId) {
            if (empty($s['connected'])) {
                throw new Failure('请先连接机器人');
            }
            if (!empty($s['owner_id'])) {
                throw new Failure('机器人已绑定，请先解除绑定');
            }
            $code = bin2hex(random_bytes(16));
            Store::put('pair', ['hash' => hash('sha256', $code), 'admin_id' => $adminId, 'expires_at' => now()->timestamp + 600, 'epoch' => $s['epoch']]);
            return '/bind '.$code;
        });
    }

    public static function unpair(): void
    {
        Store::locked('bot', function ($s) {
            if (!empty($s['pending'])) {
                throw new Failure('请先恢复待确认的机器人操作', 409);
            }
            $s['epoch'] = bin2hex(random_bytes(16));
            $s['owner_id'] = $s['admin_id'] = null;
            Store::put('bot', $s);
            Store::put('pair', []);
        });
    }

    public static function forget(): void
    {
        Store::locked('bot', function ($s) {
            if (empty($s['pending'])) {
                throw new Failure('仅待恢复操作可清除本地连接，请优先正常断开', 409);
            }
            Store::put('bot', []);
            Store::put('pair', []);
            Diagnostics::record('TELEGRAM_LOCAL_CONNECTION_CLEARED', [], true);
        });
    }
    public static function disconnect(): void
    {
        Store::locked('bot', function ($s) {
            if (empty($s['token'])) {
                return;
            }
            if (!empty($s['pending']) && $s['pending'] !== 'disconnect') {
                throw new Failure('请先恢复待确认的机器人连接', 409);
            }
            $s['connected'] = false;
            $s['pending'] = 'disconnect';
            Store::put('bot', $s);
        });
        self::recover();
    }
}
