<?php

namespace Plugin\FriendInvite\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class Outbox
{
    public static function enqueue(string $key, string $method, array $data): void
    {
        $s = Store::read('bot');
        if (empty($s['connected']) || empty($s['owner_id'])) {
            return;
        }
        DB::table('friend_invite_outbox')->insertOrIgnore(['id' => hash('sha256', $s['epoch'].':'.$key), 'epoch' => $s['epoch'],
            'payload' => Store::pack(['method' => $method, 'data' => $data, 'owner_id' => $s['owner_id'], 'admin_id' => $s['admin_id']]),
            'available_at' => now()->timestamp, 'created_at' => now()->timestamp]);
    }

    public static function flush(int $limit = 3): void
    {
        if (!Settings::enabled()) {
            return;
        }
        for ($i = 0; $i < $limit; $i++) {
            $lease = bin2hex(random_bytes(16));
            $row = DB::transaction(function () use ($lease) {
                $q = DB::table('friend_invite_outbox');
                $row = $q->whereNull('sent_at')->where('attempts', '<', 6)->where('available_at', '<=', now()->timestamp)->orderBy('created_at')->lockForUpdate()->first();
                if (!$row) {
                    return null;
                }
                $n = DB::table('friend_invite_outbox')->where('id', $row->id)->where('available_at', '<=', now()->timestamp)->update(['lease' => $lease, 'available_at' => now()->timestamp + 60, 'attempts' => $row->attempts + 1]);
                return $n === 1 ? $row : null;
            }, 3);
            if (!$row) {
                return;
            }
            try {
                $s = Store::read('bot');
                $p = Store::unpack($row->payload);
                $valid = !empty($s['connected']) && $row->epoch === $s['epoch'] && ($s['owner_id'] ?? null) === $p['owner_id'] && ($s['admin_id'] ?? null) === $p['admin_id']
                    && User::where('id', $p['admin_id'])->where('is_admin', true)->where('banned', false)->exists();
                if ($valid) {
                    Telegram::call($s['token'], $p['method'], $p['data']);
                }
                DB::table('friend_invite_outbox')->where('id', $row->id)->where('lease', $lease)->update(['sent_at' => now()->timestamp, 'lease' => null]);
            } catch (\Throwable) {
                DB::table('friend_invite_outbox')->where('id', $row->id)->where('lease', $lease)->update(['available_at' => now()->timestamp + min(3600, 30 * (2 ** $row->attempts)), 'lease' => null]);
                Diagnostics::record('OUTBOX_RETRY', ['attempt' => $row->attempts + 1], true);
            }
        }
    }
}
