<?php

namespace Plugin\FriendInvite\Services;

use App\Models\Plugin;
use App\Models\Plan;

final class Settings
{
    public const CODE = 'friend_invite';

    public static function defaults(): array
    {
        return ['accepting' => false, 'plan_ids' => [], 'invite_days' => 7, 'register_url' => '', 'debug_until' => 0];
    }

    public static function get(): array
    {
        return array_replace(self::defaults(), Store::read('settings'));
    }

    public static function enabled(): bool
    {
        return Plugin::where('code', self::CODE)->where('is_enabled', true)->exists();
    }

    public static function readiness(): array
    {
        $issues = [];
        if ((bool) admin_setting('stop_register', false)) {
            $issues[] = '请在 XBoard 开放注册';
        }
        if (!(bool) admin_setting('invite_force', false)) {
            $issues[] = '请在 XBoard 开启强制邀请码注册';
        }
        if ((int) admin_setting('try_out_plan_id', 0)) {
            $issues[] = '请关闭全站注册试用套餐';
        }
        return $issues;
    }

    public static function save(array $input): array
    {
        $c = self::get();
        $ids = array_values(array_unique(array_map('intval', $input['plan_ids'] ?? [])));
        if (count($ids) !== Plan::whereIn('id', $ids)->count()) {
            throw new Failure('套餐不存在，请刷新页面');
        }
        $days = (int) ($input['invite_days'] ?? 7);
        if ($days < 1 || $days > 90) {
            throw new Failure('邀请有效期应为 1 至 90 天');
        }
        $url = trim((string) ($input['register_url'] ?? ''));
        $parts = parse_url(str_replace('{code}', 'sample', $url));
        if (preg_match('/[\x00-\x20\x7f]/', $url) || !is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || substr_count($url, '{code}') !== 1) {
            throw new Failure('注册链接应为完整 HTTP(S) 地址，并包含一个 {code} 占位符');
        }
        $accepting = filter_var($input['accepting'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($accepting && (self::readiness() || !$ids)) {
            throw new Failure('请先完成注册设置并选择允许赠送的套餐');
        }
        $c = array_replace($c, ['accepting' => $accepting, 'plan_ids' => $ids, 'invite_days' => $days, 'register_url' => $url]);
        Store::put('settings', $c);
        Diagnostics::record('SETTINGS_SAVED');
        return $c;
    }

    public static function plans(): array
    {
        return Plan::query()->orderBy('sort')->orderBy('id')->get(['id', 'name', 'group_id', 'transfer_enable', 'speed_limit', 'device_limit', 'reset_traffic_method'])->toArray();
    }

    public static function version(): string
    {
        return json_decode(file_get_contents(dirname(__DIR__).'/config.json'), true)['version'];
    }
}
