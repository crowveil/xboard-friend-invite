<?php

namespace Plugin\FriendInvite\Services;

use Illuminate\Support\Facades\DB;

final class Diagnostics
{
    public static function record(string $event, array $context = [], bool $always = false): void
    {
        try {
            if (!$always && Settings::get()['debug_until'] <= now()->timestamp) {
                return;
            }
            $safe = array_intersect_key($context, array_flip(['plan_id', 'duration', 'count', 'http_status', 'attempt', 'phase', 'error_type']));
            if (isset($safe['error_type'])) {
                $safe['error_type'] = preg_replace('/@anonymous.*/s', '@anonymous', $safe['error_type']);
            }
            DB::table('friend_invite_events')->insert(['event' => $event, 'context' => json_encode($safe), 'created_at' => now()->timestamp]);
            $cutoff = DB::table('friend_invite_events')->orderByDesc('id')->skip(199)->value('id');
            if ($cutoff) {
                DB::table('friend_invite_events')->where('id', '<', $cutoff)->delete();
            }
        } catch (\Throwable) {
            // Diagnostic failures never expose exception text or affect registration.
        }
    }

    public static function report(): array
    {
        return ['version' => Settings::version(), 'debug_active' => Settings::get()['debug_until'] > now()->timestamp,
            'invitation_count' => DB::table('friend_invite_invitations')->count(),
            'native_readiness' => Settings::readiness(),
            'console_open' => ConsoleAccess::status()['open'],
            'telegram_connected' => Telegram::summary()['connected'],
            'telegram_bound' => (bool) Telegram::summary()['owner_id'],
            'failed_messages' => DB::table('friend_invite_outbox')->whereNull('sent_at')->where('attempts', '>=', 6)->count(),
            'pending_messages' => DB::table('friend_invite_outbox')->whereNull('sent_at')->count(),
            'events' => DB::table('friend_invite_events')->orderByDesc('id')->limit(200)->get()->all()];
    }
}
