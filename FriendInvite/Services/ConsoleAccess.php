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

    /** Serialize management across Web workers without an open DB transaction during HTTP calls. */
    public static function serialize(callable $fn): mixed
    {
        $directory = storage_path('app/friend-invite');
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new Failure('管理锁目录不可写，请检查 storage 权限', 503);
        }
        $file = @fopen($directory.'/console.lock', 'c');
        if (!$file) {
            throw new Failure('无法创建管理锁，请检查 storage 权限', 503);
        }
        try {
            $deadline = microtime(true) + 35;
            while (!flock($file, LOCK_EX | LOCK_NB)) {
                if (microtime(true) >= $deadline) {
                    throw new Failure('管理操作正在执行，请稍后重试', 409);
                }
                usleep(20000);
            }
            return $fn();
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }
    public static function change(bool $open, bool $renew = false): void
    {
        self::serialize(fn () => self::setOpen($open, $renew));
    }
    /** Caller holds the management lock. */
    public static function setOpen(bool $open, bool $renew = false): void
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
        return self::serialize(function () use ($fn) {
            if (!self::status()['open']) {
                throw new Failure('CONSOLE_CLOSED', 403);
            }
            return $fn();
        });
    }

    public static function close(): void
    {
        self::serialize(function () {
            self::setOpen(false);
            $row = Plugin::where('code', Settings::CODE)->first();
            if ($row) {
                $c = is_array($row->config) ? $row->config : json_decode($row->config ?: '{}', true);
                $c['console_open'] = false;
                $row->config = json_encode($c);
                $row->save();
            }
        });
    }
}
