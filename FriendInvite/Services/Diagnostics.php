<?php

namespace Plugin\FriendInvite\Services;

use Illuminate\Support\Facades\DB;

final class Diagnostics
{
    public static function failure(string $event, \Throwable $error): array
    {
        $data = ['trace' => bin2hex(random_bytes(8)), 'error_file' => basename($error->getFile()),
            'error_line' => $error->getLine(), 'error_type' => preg_replace('/@anonymous.*/s', '@anonymous', get_class($error))];
        self::record($event, $data, true);
        return $data;
    }
    public static function record(string $event, array $context = [], bool $always = false): void
    {
        try {
            if (!$always && Settings::get()['debug_until'] <= now()->timestamp) {
                return;
            }
        } catch (\Throwable) {
            if (!$always) {
                return;
            }
        }
        $safe = array_intersect_key($context, array_flip(['trace', 'error_file', 'error_line', 'plan_id', 'duration', 'count', 'http_status', 'attempt', 'phase', 'error_type']));
        if (isset($safe['error_type'])) {
            $safe['error_type'] = preg_replace('/@anonymous.*/s', '@anonymous', $safe['error_type']);
        }
        $row = ['event' => $event, 'context' => json_encode($safe), 'created_at' => now()->timestamp];
        try {
            DB::table('friend_invite_events')->insert($row);
            $cutoff = DB::table('friend_invite_events')->orderByDesc('id')->skip(199)->value('id');
            if ($cutoff) {
                DB::table('friend_invite_events')->where('id', '<', $cutoff)->delete();
            }
        } catch (\Throwable) {
            $always = true;
        }
        if ($always) {
            self::file($row);
        }
    }
    private static function file(array $row): void
    {
        $handle = null;
        try {
            $directory = storage_path('app/friend-invite');
            if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new \RuntimeException();
            }
            $handle = @fopen($directory.'/diagnostics.lock', 'c');
            if (!$handle || !flock($handle, LOCK_EX)) {
                throw new \RuntimeException();
            }
            $path = $directory.'/diagnostics.jsonl';
            clearstatcache(true, $path);
            if (is_file($path) && filesize($path) >= 1048576) {
                @rename($path, $path.'.1');
            }
            if (@file_put_contents($path, json_encode($row, JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND) === false) {
                throw new \RuntimeException();
            }
            @chmod($path, 0600);
        } catch (\Throwable) {
            error_log('FriendInvite '.json_encode($row));
        } finally {
            if (is_resource($handle)) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }
    public static function report(): array
    {
        $report = ['version' => Settings::version(), 'deployment' => Runtime::check()];
        foreach ([
            'debug_active' => fn () => Settings::get()['debug_until'] > now()->timestamp,
            'invitation_count' => fn () => DB::table('friend_invite_invitations')->count(),
            'native_readiness' => fn () => Settings::readiness(),
            'console_open' => fn () => ConsoleAccess::status()['open'],
            'telegram_connected' => fn () => Telegram::summary()['connected'],
            'telegram_bound' => fn () => (bool) Telegram::summary()['owner_id'],
            'failed_messages' => fn () => DB::table('friend_invite_outbox')->whereNull('sent_at')->where('attempts', '>=', 6)->count(),
            'pending_messages' => fn () => DB::table('friend_invite_outbox')->whereNull('sent_at')->count(),
            'events' => fn () => DB::table('friend_invite_events')->orderByDesc('id')->limit(200)->get()->all(),
        ] as $key => $read) {
            try {
                $report[$key] = $read();
            } catch (\Throwable) {
                $report[$key] = null;
            }
        }
        return $report;
    }
}
