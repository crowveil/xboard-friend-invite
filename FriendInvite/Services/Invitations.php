<?php

namespace Plugin\FriendInvite\Services;

use App\Models\InviteCode;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class Invitations
{
    public static function table()
    {
        return DB::table('friend_invite_invitations');
    }

    public static function create(int $adminId, array $input, string $operation): array
    {
        if (!User::where('id', $adminId)->where('is_admin', true)->where('banned', false)->exists()) {
            throw new Failure('需要管理员权限', 403);
        }
        $c = Settings::get();
        if (!Settings::enabled() || !$c['accepting'] || Settings::readiness()) {
            throw new Failure('请先启用好友邀请并完成注册设置');
        }
        $planId = (int) ($input['plan_id'] ?? 0);
        $duration = (string) ($input['duration'] ?? '');
        Duration::expires($duration);
        if (!in_array($planId, $c['plan_ids'], true)) {
            throw new Failure('该套餐未允许赠送');
        }
        $plan = Plan::find($planId);
        if (!$plan || !$plan->group_id || !$plan->transfer_enable) {
            throw new Failure('套餐必须设置权限组和非零额度');
        }
        $email = trim((string) ($input['email'] ?? ''));
        if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || !admin_setting('email_verify', false))) {
            throw new Failure('绑定邮箱需要有效地址并开启 XBoard 邮箱验证');
        }
        $days = (int) ($input['invite_days'] ?? $c['invite_days']);
        if ($days < 1 || $days > 90) {
            throw new Failure('邀请有效期应为 1 至 90 天');
        }
        $key = hash('sha256', $adminId.':'.$operation);
        return DB::transaction(function () use ($adminId, $input, $key, $planId, $duration, $email, $days, $c) {
            $existing = self::table()->where('operation_key', $key)->first();
            if ($existing) {
                return self::present($existing);
            }
            $code = bin2hex(random_bytes(16));
            $native = new InviteCode();
            $native->user_id = $adminId;
            $native->code = $code;
            $native->status = InviteCode::STATUS_UNUSED;
            $native->saveOrFail();
            $id = bin2hex(random_bytes(16));
            self::table()->insert(['id' => $id, 'native_id' => $native->id, 'code_hash' => hash('sha256', $code), 'code_cipher' => Store::pack(['code' => $code]),
                'plan_id' => $planId, 'duration' => $duration, 'expires_at' => now()->timestamp + $days * 86400,
                'created_by' => $adminId, 'created_at' => now()->timestamp, 'operation_key' => $key,
                'details' => Store::pack(['note' => mb_substr((string) ($input['note'] ?? ''), 0, 120), 'email' => $email, 'register_url' => $c['register_url']])]);
            Diagnostics::record('INVITATION_CREATED', ['plan_id' => $planId, 'duration' => $duration]);
            return self::present(self::table()->where('id', $id)->first());
        }, 3);
    }

    public static function present(object $row): array
    {
        $d = Store::unpack($row->details);
        $state = $row->used_by ? 'used' : ($row->revoked ? 'revoked' : ($row->expires_at <= now()->timestamp ? 'expired' : 'pending'));
        return ['id' => $row->id, 'plan_id' => $row->plan_id, 'plan_name' => Plan::where('id', $row->plan_id)->value('name') ?? '套餐已删除',
            'duration' => $row->duration, 'duration_label' => Duration::OPTIONS[$row->duration], 'state' => $state,
            'note' => $d['note'], 'email' => $d['email'], 'created_at' => $row->created_at, 'expires_at' => $row->expires_at,
            'used_email' => $row->used_by ? User::where('id', $row->used_by)->value('email') : null,
            'url' => $state === 'pending' ? str_replace('{code}', rawurlencode(Store::unpack($row->code_cipher)['code']), $d['register_url']) : null];
    }

    public static function revoke(string $id): void
    {
        DB::transaction(function () use ($id) {
            $row = self::table()->where('id', $id)->lockForUpdate()->first();
            if (!$row) {
                throw new Failure('邀请不存在', 404);
            }
            if ($row->used_by) {
                throw new Failure('已领取邀请不能撤销；请在 XBoard 用户管理调整账号');
            }
            self::table()->where('id', $id)->update(['revoked' => true]);
            InviteCode::where('id', $row->native_id)->update(['status' => InviteCode::STATUS_USED]);
            Diagnostics::record('INVITATION_REVOKED');
        }, 3);
    }

    public static function revokePending(): void
    {
        foreach (self::table()->whereNull('used_by')->where('revoked', false)->pluck('id') as $id) {
            self::revoke($id);
        }
    }
}
