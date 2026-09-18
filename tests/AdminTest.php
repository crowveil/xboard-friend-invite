<?php

use App\Models\{User};
use App\Services\Plugin\PluginConfigService;
use Plugin\FriendInvite\Services\{ConsoleAccess, Diagnostics, Invitations, Settings, Store};

final class AdminTest extends InviteTestCase
{
    public function testNativeConfigIncludesCurrentOriginAndControlsAccessExpiry(): void
    {
        $service = app(PluginConfigService::class);
        $config = $service->getConfig('friend_invite');
        self::assertStringContainsString('https://panel.example.test/plugins/friend_invite/console.html', $config['console_open']['description']);
        self::assertFalse($config['console_open']['value']);
        $service->updateConfig('friend_invite', ['console_open' => true]);
        self::assertTrue(ConsoleAccess::status()['open']);
        self::assertEqualsWithDelta(time() + 3600, ConsoleAccess::status()['expires_at'], 2);
        Store::put('console', ['expires_at' => time() - 1]);
        self::assertFalse($service->getConfig('friend_invite')['console_open']['value']);
    }
    public function testAllAdminRoutesRejectAnonymousAndRegularUserEvenWhenConsoleOpen(): void
    {
        ConsoleAccess::change(true);
        foreach ([null,User::create(['email' => 'ordinary@example.test'])] as $user) {
            app('auth')->admin = $user;
            foreach (['session','state','export'] as $path) {
                self::assertSame(403, $this->route('admin/'.$path)->getStatusCode(), $path);
            }
            foreach (['settings','create','revoke','close','renew','debug','telegram/connect','telegram/pair','telegram/unpair','telegram/disconnect'] as $path) {
                self::assertSame(403, $this->route('admin/'.$path, [])->getStatusCode(), $path);
            }
        }
    }
    public function testClosedConsoleHasOnlySummaryAndCloseIsIdempotent(): void
    {
        $summary = json_decode($this->route('admin/session')->getContent(), true);
        self::assertArrayHasKey('summary', $summary);
        self::assertArrayNotHasKey('config', $summary);
        self::assertSame(403, $this->route('admin/state')->getStatusCode());
        self::assertSame(403, $this->route('admin/create', [])->getStatusCode());
        ConsoleAccess::change(true);
        self::assertSame(200, $this->route('admin/state')->getStatusCode());
        self::assertSame(200, $this->route('admin/close', [])->getStatusCode());
        self::assertFalse(ConsoleAccess::status()['open']);
        self::assertSame(200, $this->route('admin/close', [])->getStatusCode());
        self::assertSame(403, $this->route('admin/export')->getStatusCode());
    }
    public function testBrowserCreateAndRevokeUseOriginalAdminAuthentication(): void
    {
        ConsoleAccess::change(true);
        $v = ['plan_id' => 1,'duration' => 'three_years','note' => '朋友','invite_days' => 7,'request_id' => 'e985d540-e1d2-4b20-9f53-1fa8d660a258'];
        $r = $this->route('admin/create', $v);
        self::assertSame(200, $r->getStatusCode(), $r->getContent());
        $p = json_decode($r->getContent(), true)['invitation'];
        self::assertSame('3 年', $p['duration_label']);
        self::assertSame(200, $this->route('admin/create', $v)->getStatusCode());
        self::assertSame(1, Invitations::table()->count());
        self::assertSame(200, $this->route('admin/revoke', ['id' => $p['id']])->getStatusCode());
        self::assertFalse($this->register($p)[0]);
    }
    public function testSettingsCannotOpenUnsafeRegistrationAndDoesNotModifyNativeConfig(): void
    {
        ConsoleAccess::change(true);
        $GLOBALS['test_settings']['invite_force'] = false;
        $r = $this->route('admin/settings', ['accepting' => true,'plan_ids' => [1],'invite_days' => 7,'register_url' => 'https://panel.example.test/#/register?code={code}']);
        self::assertSame(422, $r->getStatusCode());
        self::assertFalse(admin_setting('invite_force'));
    }
    public function testDiagnosticsRedactAndDebugExpires(): void
    {
        Store::put('settings', array_replace(Settings::get(), ['debug_until' => time() + 600]));
        Diagnostics::record('TEST', ['token' => 'SECRET','url' => 'SECRET','email' => 'SECRET','plan_id' => 1]);
        $report = Diagnostics::report();
        self::assertCount(1, $report['events']);
        self::assertStringNotContainsString('SECRET', json_encode($report));
        Store::put('settings', array_replace(Settings::get(), ['debug_until' => time() - 1]));
        Diagnostics::record('IGNORED');
        self::assertCount(1, Diagnostics::report()['events']);
    }
    public function testReinstallMigrationRetainsExistingInvitationData(): void
    {
        $p = $this->invite();
        $migration = require dirname(__DIR__).'/FriendInvite/database/migrations/2026_09_17_000001_create_friend_invite_tables.php';
        $migration->down();
        $migration->up();
        self::assertSame(1, Invitations::table()->count());
        self::assertSame($p['id'], Invitations::table()->first()->id);
    }
    public function testPausedInvitesAllowEmptyPlanSelection(): void
    {
        ConsoleAccess::change(true);
        $r = $this->route('admin/settings', ['accepting' => false,'plan_ids' => [],'invite_days' => 7,'register_url' => 'https://panel.example.test/#/register?code={code}']);
        self::assertSame(200, $r->getStatusCode(), $r->getContent());
        self::assertSame([], Settings::get()['plan_ids']);
    }
}
