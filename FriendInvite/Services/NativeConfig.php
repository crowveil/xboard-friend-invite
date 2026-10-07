<?php

namespace Plugin\FriendInvite\Services;

use App\Services\Plugin\PluginConfigService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class NativeConfig extends PluginConfigService
{
    public function __construct(private readonly PluginConfigService $inner)
    {
    }

    public static function origin(Request $request): string
    {
        $root = $request->getSchemeAndHttpHost();
        if ((bool) admin_setting('force_https', false)) {
            $root = preg_replace('/^http:/', 'https:', $root);
        }
        return $root.rtrim($request->getBasePath(), '/');
    }

    public function getConfig(string $code): array
    {
        $c = $this->inner->getConfig($code);
        if ($code === Settings::CODE && Settings::enabled() && isset($c['console_open'])) {
            $c['console_open']['value'] = ConsoleAccess::status()['open'];
            $c['console_open']['description'] = '控制台地址：'.self::origin(request()).'/plugins/friend_invite/console.html 。开启并保存后复制打开；需要同域管理员登录。关闭网页管理不影响 TG 或已发邀请。';
        }
        return $c;
    }

    public function updateConfig(string $code, array $config): bool
    {
        if ($code !== Settings::CODE) {
            return $this->inner->updateConfig($code, $config);
        }
        return ConsoleAccess::serialize(fn () => DB::transaction(function () use ($code, $config) {
            $ok = $this->inner->updateConfig($code, $config);
            if ($ok && $code === Settings::CODE && Settings::enabled()) {
                ConsoleAccess::setOpen(filter_var($config['console_open'] ?? false, FILTER_VALIDATE_BOOLEAN));
            }
            return $ok;
        }));
    }

    public function getDbConfig(string $code): array
    {
        return $this->inner->getDbConfig($code);
    }
}
