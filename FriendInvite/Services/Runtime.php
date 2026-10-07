<?php

namespace Plugin\FriendInvite\Services;

use Illuminate\Support\Facades\Schema;

final class Runtime
{
    public static function inspect(): array
    {
        $interfaces = [];
        foreach ([Settings::class => ['revision', 'debug'], Assets::class => ['publish'], Telegram::class => ['recover'], Diagnostics::class => ['failure']] as $class => $methods) {
            foreach ($methods as $method) {
                $interfaces[class_basename($class).'::'.$method] = is_callable([$class, $method]);
            }
        }
        return ['compatible' => !in_array(false, $interfaces, true), 'interfaces' => $interfaces, 'process_id' => getmypid()];
    }
    public static function requireSupported(): void
    {
        if (!self::inspect()['compatible']) {
            throw new Failure('PLUGIN_RESTART_REQUIRED：请更新完整插件并重启常驻 PHP 进程', 503);
        }
    }
    public static function check(): array
    {
        $tables = [];
        foreach (['state', 'invitations', 'updates', 'outbox', 'events'] as $name) {
            try {
                $tables[$name] = Schema::hasTable('friend_invite_'.$name);
            } catch (\Throwable) {
                $tables[$name] = false;
            }
        }
        return ['runtime' => self::inspect(), 'tables' => $tables, 'assets' => Assets::inspect(),
            'storage_writable' => is_writable(storage_path()), 'app_key_configured' => (bool) config('app.key')];
    }
}
