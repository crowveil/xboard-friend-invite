<?php

namespace Plugin\FriendInvite\Services;

use App\Models\InviteCode;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Services\Auth\RegisterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class Registration extends RegisterService
{
    public function __construct(private readonly RegisterService $inner)
    {
    }

    public function register(Request $request): array
    {
        if (!Settings::enabled()) {
            return $this->inner->register($request);
        }
        $phase = 'validate_invitation';
        try {
            if (!Settings::get()['accepting'] || Settings::readiness()) {
                throw new Failure('好友邀请注册暂未开放');
            }
            return DB::transaction(function () use ($request, &$phase) {
                $code = $request->input('invite_code');
                if (!is_string($code) || !preg_match('/^[a-f0-9]{32}$/D', $code)) {
                    throw new Failure('请使用管理员发送的好友邀请链接');
                }
                $invite = Invitations::table()->where('code_hash', hash('sha256', $code))->lockForUpdate()->first();
                if (!$invite || $invite->revoked || $invite->used_by || $invite->expires_at <= now()->timestamp) {
                    throw new Failure('邀请已失效或已被领取');
                }
                $native = InviteCode::where('id', $invite->native_id)->lockForUpdate()->first();
                if (!$native || $native->status) {
                    throw new Failure('邀请已失效');
                }
                $detail = Store::unpack($invite->details);
                if ($detail['email'] !== '' && (!admin_setting('email_verify', false) || strcasecmp($detail['email'], (string) $request->input('email')) !== 0)) {
                    throw new Failure('请使用受邀邮箱，并完成邮箱验证');
                }
                $plan = Plan::where('id', $invite->plan_id)->lockForUpdate()->first();
                if (!$plan || !in_array((int) $plan->id, Settings::get()['plan_ids'], true) || !$plan->transfer_enable || !ServerGroup::where('id', $plan->group_id)->exists()) {
                    throw new Failure('邀请套餐暂不可用，请联系管理员');
                }
                if ($plan->capacity_limit !== null) {
                    $used = \App\Models\User::where('plan_id', $plan->id)->where(function ($q) {
                        $q->whereNull('expired_at')->orWhere('expired_at', '>', now()->timestamp);
                    })->count();
                    if ($used >= $plan->capacity_limit) {
                        throw new Failure('套餐人数已满，请联系管理员');
                    }
                }
                $phase = 'native_registration';
                $result = $this->inner->register($request);
                if (!$result[0]) {
                    throw new RegistrationRejected($result);
                }
                $phase = 'assign_plan';
                $user = $result[1];
                $user->plan_id = $plan->id;
                $user->group_id = $plan->group_id;
                $user->transfer_enable = $plan->transfer_enable * 1073741824;
                $user->speed_limit = $plan->speed_limit;
                $user->device_limit = $plan->device_limit;
                $user->expired_at = Duration::expires($invite->duration);
                $user->u = 0;
                $user->d = 0;
                $note = trim((string) ($detail['note'] ?? ''));
                if ($note !== '') {
                    $existing = (string) ($user->remarks ?? '');
                    $user->remarks = trim($existing) === '' ? $note : rtrim($existing)."\n".$note;
                }
                $user->saveOrFail();
                $phase = 'claim_invitation';
                InviteCode::where('id', $invite->native_id)->update(['status' => InviteCode::STATUS_USED]);
                $claimed = Invitations::table()->where('id', $invite->id)->whereNull('used_by')->where('revoked', false)->update(['used_by' => $user->id, 'used_at' => now()->timestamp]);
                if ($claimed !== 1) {
                    throw new Failure('邀请已被领取');
                }
                Bot::notifyClaim($invite, $user);
                Diagnostics::record('INVITATION_CLAIMED', ['plan_id' => (int) $plan->id, 'duration' => $invite->duration]);
                $phase = 'commit_and_native_observers';
                return $result;
            });
        } catch (RegistrationRejected $e) {
            return $e->result;
        } catch (Failure $e) {
            return [false, [422, $e->reason]];
        } catch (\App\Exceptions\ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Diagnostics::record('REGISTRATION_FAILED', ['phase' => $phase, 'error_type' => get_class($e)], true);
            return [false, [500, '开通未完成，请稍后重试；如邮箱已注册请直接登录，验证码可能需要重新获取']];
        }
    }

    public function validateRegister(Request $request): array
    {
        return $this->inner->validateRegister($request);
    }
    public function handleInviteCode(string $code): ?int
    {
        return $this->inner->handleInviteCode($code);
    }
}

final class RegistrationRejected extends \RuntimeException
{
    public function __construct(public readonly array $result)
    {
        parent::__construct('Registration rejected');
    }
}
