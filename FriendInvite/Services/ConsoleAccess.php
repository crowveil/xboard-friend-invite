<?php

namespace Plugin\FriendInvite\Services;

use App\Models\Plugin;

final class ConsoleAccess
{
    public static function status(): array
    {
        $expiry = (int) (Store::read('console')['expires_at'] ?? 0);
        return ['open' => Settings::enabled() && $expiry > now()->timestamp, 'expires_at' => $expiry];
    }

    public static function change(bool $open, bool $renew = false): void
    {
        Store::locked('console', function ($s) use ($open, $renew) {
            $expiry = (int) ($s['expires_at'] ?? 0);
            if (!$open) {
                $expiry = 0;
            } elseif ($renew || $expiry <= now()->timestamp) {
                $expiry = now()->timestamp + 3600;
            }
            Store::put('console', ['expires_at' => $expiry]);
        });
    }

    public static function withOpen(callable $fn): mixed
    {
        return Store::locked('console', function () use ($fn) {
            if (!self::status()['open']) {
                throw new Failure('CONSOLE_CLOSED', 403);
            }
            return $fn();
        });
    }

    public static function close(): void
    {
        self::change(false);
        $row = Plugin::where('code', Settings::CODE)->first();
        if ($row) {
            $c = is_array($row->config) ? $row->config : json_decode($row->config ?: '{}', true);
            $c['console_open'] = false;
            $row->config = json_encode($c);
            $row->save();
        }
    }
}
