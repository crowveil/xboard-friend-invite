<?php

namespace Plugin\FriendInvite\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Plugin\FriendInvite\Services\{ConsoleAccess, Diagnostics, Duration, Failure, Invitations, NativeConfig, Settings, Store, Telegram};

final class AdminController
{
    private function action(callable $fn, bool $gated = true)
    {
        try {
            $admin = Auth::guard('sanctum')->user();
            if (!$admin || !$admin->is_admin || $admin->banned) {
                throw new Failure('需要有效的 XBoard 管理员登录', 403);
            }
            if (!Settings::enabled()) {
                throw new Failure('插件未启用', 403);
            }
            $data = $gated ? ConsoleAccess::withOpen($fn) : $fn();
            return response()->json($data)->header('Cache-Control', 'private, no-store');
        } catch (Failure $e) {
            return response()->json(['error' => $e->reason], $e->status)->header('Cache-Control', 'no-store');
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Diagnostics::record('ADMIN_OPERATION_FAILED', ['error_type' => get_class($e)], true);
            return response()->json(['error' => '操作失败，请导出脱敏诊断检查'], 500)->header('Cache-Control', 'no-store');
        }
    }

    public function session()
    {
        return $this->action(fn () => ['access' => ConsoleAccess::status(), 'version' => Settings::version(), 'summary' => ['accepting' => Settings::get()['accepting'], 'invitations' => Invitations::table()->count(), 'telegram_connected' => Telegram::summary()['connected']]], false);
    }

    public function state(Request $request)
    {
        return $this->action(function () use ($request) {
            $c = Settings::get();
            if (!$c['register_url']) {
                $root = rtrim((string) admin_setting('app_url', ''), '/') ?: NativeConfig::origin($request);
                $c['register_url'] = $root.'/#/register?code={code}';
            }
            $page = max(1, min(10000, (int) $request->input('page', 1)));
            return ['config' => $c, 'access' => ConsoleAccess::status(), 'durations' => Duration::OPTIONS, 'plans' => Settings::plans(),
                'readiness' => Settings::readiness(), 'telegram' => Telegram::summary(), 'page' => $page, 'total' => Invitations::table()->count(),
                'invitations' => Invitations::table()->orderByDesc('created_at')->orderByDesc('id')->skip(($page - 1) * 20)->limit(20)->get()->map(fn ($r) => Invitations::present($r))->all()];
        });
    }

    public function save(Request $request)
    {
        return $this->action(function () use ($request) {
            $data = $request->validate(['accepting' => 'required|boolean', 'plan_ids' => 'present|array', 'plan_ids.*' => 'integer|min:1', 'invite_days' => 'required|integer|min:1|max:90', 'register_url' => 'required|string|max:1000']);
            Settings::save($data);
            return ['ok' => true];
        });
    }

    public function create(Request $request)
    {
        return $this->action(function () use ($request) {
            $v = $request->validate(['plan_id' => 'required|integer', 'duration' => 'required|in:'.implode(',', array_keys(Duration::OPTIONS)), 'note' => 'nullable|string|max:120', 'email' => 'nullable|email|max:254', 'invite_days' => 'required|integer|min:1|max:90', 'request_id' => 'required|uuid']);
            return ['invitation' => Invitations::create((int) Auth::guard('sanctum')->user()->id, $v, 'web:'.$v['request_id'])];
        });
    }

    public function revoke(Request $request)
    {
        return $this->action(function () use ($request) {
            $v = $request->validate(['id' => 'required|string|size:32']);
            Invitations::revoke($v['id']);
            return ['ok' => true];
        });
    }

    public function renew()
    {
        return $this->action(function () {
            ConsoleAccess::change(true, true);
            return ['access' => ConsoleAccess::status()];
        });
    }
    public function close()
    {
        return $this->action(function () {
            ConsoleAccess::close();
            return ['ok' => true];
        }, false);
    }

    public function debug(Request $request)
    {
        return $this->action(function () use ($request) {
            $v = $request->validate(['enabled' => 'required|boolean']);
            $c = Settings::get();
            $c['debug_until'] = $v['enabled'] ? now()->timestamp + 3600 : 0;
            Store::put('settings', $c);
            return ['ok' => true, 'until' => $c['debug_until']];
        });
    }

    public function export()
    {
        return $this->action(fn () => Diagnostics::report());
    }

    public function connect(Request $request)
    {
        return $this->action(function () use ($request) {
            $v = $request->validate(['token' => 'required|string|max:240']);
            return ['telegram' => Telegram::connect(trim($v['token']), NativeConfig::origin($request))];
        });
    }

    public function pair()
    {
        return $this->action(fn () => ['command' => Telegram::pair((int) Auth::guard('sanctum')->user()->id)]);
    }
    public function unpair()
    {
        return $this->action(function () {
            Telegram::unpair();
            return ['ok' => true];
        });
    }
    public function disconnect()
    {
        return $this->action(function () {
            Telegram::disconnect();
            return ['ok' => true];
        });
    }
}
