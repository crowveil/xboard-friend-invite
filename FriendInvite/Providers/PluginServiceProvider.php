<?php

namespace Plugin\FriendInvite\Providers;

use App\Services\Auth\RegisterService;
use App\Services\Plugin\PluginConfigService;
use Illuminate\Support\ServiceProvider;
use Plugin\FriendInvite\Services\NativeConfig;
use Plugin\FriendInvite\Services\Registration;

final class PluginServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->extend(PluginConfigService::class, static fn (PluginConfigService $s) => $s instanceof NativeConfig ? $s : new NativeConfig($s));
        $this->app->extend(RegisterService::class, static fn (RegisterService $s) => $s instanceof Registration ? $s : new Registration($s));
    }
}
