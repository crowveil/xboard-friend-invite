<?php

use App\Models\{InviteCode, Plan, User};
use App\Services\Auth\RegisterService;
use App\Services\Plugin\HookManager;
use Illuminate\Support\Facades\{Cache, DB};
use Plugin\FriendInvite\Services\{Duration, Invitations, Registration, Settings, Store};

final class RegistrationTest extends InviteTestCase
{
    public function testRealPluginBootWrapsRegisterAndNativeAssetsPublish(): void
    {
        self::assertInstanceOf(Registration::class, app(RegisterService::class));
        self::assertFileExists(public_path('plugins/friend_invite/console.html'));
    }
    public function testFiveDurationsAndCalendarEdges(): void
    {
        $now = Carbon\CarbonImmutable::parse('2028-01-31 12:00:00', 'UTC');
        Carbon\CarbonImmutable::setTestNow($now);
        self::assertCount(5, Duration::OPTIONS);
        $expected = ['day' => '2028-02-01','month' => '2028-02-29','year' => '2029-01-31','three_years' => '2031-01-31'];
        foreach ($expected as $term => $date) {
            self::assertSame($date.' 12:00:00', date('Y-m-d H:i:s', Duration::expires($term)));
        }
        self::assertNull(Duration::expires('forever'));
        Carbon\CarbonImmutable::setTestNow('2028-02-29 12:00:00');
        self::assertSame('2029-02-28', date('Y-m-d', Duration::expires('year')));
    }
    public function testAllFivePeriodsGrantNativePlanAndIgnoreRequestTampering(): void
    {
        foreach (array_keys(Duration::OPTIONS) as $term) {
            $p = $this->invite($term);
            $r = $this->register($p, $term.'@example.test', ['plan_id' => 999,'transfer_enable' => 999999,'expired_at' => null]);
            self::assertTrue($r[0], json_encode($r));
            $u = $r[1]->fresh();
            self::assertSame(1, $u->plan_id);
            self::assertSame(1, $u->group_id);
            self::assertSame(100 * 1073741824, $u->transfer_enable);
            self::assertSame(50, $u->speed_limit);
            self::assertSame(3, $u->device_limit);
            self::assertSame(0, $u->u + $u->d);
            self::assertSame(1, $u->invite_user_id);
            self::assertNotSame('test-password-123', $u->password);
            if ($term === 'forever') {
                self::assertNull($u->expired_at);
            } else {
                self::assertEqualsWithDelta(Duration::expires($term), $u->expired_at, 2);
            }
            self::assertSame($u->id, Invitations::table()->where('id', $p['id'])->value('used_by'));
        }
    }
    public function testInvitationCanOnlyBeClaimedOnceEvenWithNeverExpireNativeSetting(): void
    {
        $GLOBALS['test_settings']['invite_never_expire'] = true;
        $p = $this->invite();
        self::assertTrue($this->register($p)[0]);
        self::assertFalse($this->register($p, 'second@example.test')[0]);
        self::assertSame(2, User::count());
        self::assertTrue(InviteCode::first()->status);
    }
    public function testNativeCaptchaAndEmailErrorsLeaveInvitationUsable(): void
    {
        $p = $this->invite();
        $GLOBALS['captcha_result'] = [false,[422,'captcha failed']];
        self::assertSame([false,[422,'captcha failed']], $this->register($p));
        self::assertFalse(InviteCode::first()->status);
        $GLOBALS['captcha_result'] = [true,null];
        $GLOBALS['test_settings']['email_verify'] = true;
        self::assertFalse($this->register($p)[0]);
        self::assertSame(1, User::count());
        Cache::put(App\Utils\CacheKey::get('EMAIL_VERIFY_CODE', 'friend@example.test'), '123456', 600);
        self::assertTrue($this->register($p, 'friend@example.test', ['email_code' => '123456'])[0]);
    }
    public function testAnyFailureAfterNativeUserSaveRollsBackUserAndNativeInvite(): void
    {
        $p = $this->invite();
        HookManager::register('user.register.after', function () {
            throw new RuntimeException('SECRET_failure');
        });
        $r = $this->register($p);
        self::assertFalse($r[0]);
        self::assertStringNotContainsString('SECRET', json_encode($r));
        self::assertSame(1, User::count());
        self::assertFalse(InviteCode::first()->status);
        self::assertNull(Invitations::table()->first()->used_by);
        HookManager::remove('user.register.after');
        self::assertTrue($this->register($p)[0]);
    }
    public function testPlanAssignmentFailureAlsoRollsBackAndNoClaimNotificationEscapes(): void
    {
        $this->bot();
        $p = $this->invite();
        User::saving(function ($u) {
            if ($u->email === 'friend@example.test' && $u->plan_id) {
                throw new RuntimeException('assignment');
            }
        });
        self::assertFalse($this->register($p)[0]);
        self::assertSame(1, User::count());
        self::assertFalse(InviteCode::first()->status);
        self::assertSame(0, DB::table('friend_invite_outbox')->count());
    }
    public function testExpiredRevokedWrongAndExistingUserCannotClaim(): void
    {
        $p = $this->invite();
        Invitations::table()->where('id', $p['id'])->update(['expires_at' => time() - 1]);
        self::assertFalse($this->register($p)[0]);
        $p = $this->invite();
        Invitations::revoke($p['id']);
        self::assertFalse($this->register($p)[0]);
        $p = $this->invite();
        self::assertFalse($this->register($p, 'admin@example.test')[0]);
        self::assertFalse($this->register($p, 'friend@example.test', ['invite_code' => 'wrong'])[0]);
        self::assertTrue($this->register($p)[0]);
    }
    public function testBoundEmailRequiresMatchingVerifiedAddress(): void
    {
        $GLOBALS['test_settings']['email_verify'] = true;
        $p = $this->invite('month', ['email' => 'friend@example.test']);
        self::assertFalse($this->register($p, 'other@example.test')[0]);
        Cache::put(App\Utils\CacheKey::get('EMAIL_VERIFY_CODE', 'friend@example.test'), '123456', 600);
        self::assertTrue($this->register($p, 'friend@example.test', ['email_code' => '123456'])[0]);
    }
    public function testCapacityAndRemovedPlanBlockGrant(): void
    {
        $p = $this->invite();
        Plan::where('id', 1)->update(['capacity_limit' => 0]);
        self::assertFalse($this->register($p)[0]);
        Plan::where('id', 1)->update(['capacity_limit' => 1]);
        self::assertTrue($this->register($p)[0]);
        $q = $this->invite();
        self::assertFalse($this->register($q, 'second@example.test')[0]);
        Plan::where('id', 1)->delete();
        self::assertFalse($this->register($q, 'second@example.test')[0]);
    }
    public function testDisabledAcceptingAndUnsafeNativeSettingsBlockRegistration(): void
    {
        $p = $this->invite();
        Store::put('settings', array_replace(Settings::get(), ['accepting' => false]));
        self::assertFalse($this->register($p)[0]);
        Store::put('settings', array_replace(Settings::get(), ['accepting' => true]));
        $GLOBALS['test_settings']['try_out_plan_id'] = 1;
        self::assertFalse($this->register($p)[0]);
    }
    public function testCreateRetriesUseSameOperationAndDisableRevokesNativeCodes(): void
    {
        $input = ['plan_id' => 1,'duration' => 'year'];
        $a = Invitations::create(1, $input, 'stable');
        $b = Invitations::create(1, $input, 'stable');
        self::assertSame($a['id'], $b['id']);
        self::assertSame(1, InviteCode::count());
        (new Plugin\FriendInvite\Plugin('friend_invite'))->cleanup();
        self::assertTrue(InviteCode::first()->status);
        self::assertFalse($this->register($a)[0]);
    }
}
