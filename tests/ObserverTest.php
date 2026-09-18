<?php

use App\Jobs\NodeUserSyncJob;
use App\Models\User;
use App\Observers\UserObserver;
use App\Services\Plugin\HookManager;

final class ObserverTest extends InviteTestCase
{
    public function testNativeAfterCommitObserverSchedulesTrafficResetAndNodeSync(): void
    {
        NodeUserSyncJob::$dispatched = [];
        User::observe(UserObserver::class);
        $p = $this->invite('year');
        $r = $this->register($p);
        self::assertTrue($r[0], json_encode($r));
        self::assertGreaterThan(time(), $r[1]->fresh()->next_reset_at);
        self::assertNotEmpty(NodeUserSyncJob::$dispatched);
        $p = $this->invite('forever');
        $r = $this->register($p, 'forever@example.test');
        self::assertTrue($r[0]);
        self::assertNull($r[1]->fresh()->next_reset_at);
    }
    public function testRollbackPreventsNativeAfterCommitNodeSync(): void
    {
        NodeUserSyncJob::$dispatched = [];
        User::observe(UserObserver::class);
        HookManager::register('user.register.after', function () {
            throw new RuntimeException('abort');
        });
        self::assertFalse($this->register($this->invite())[0]);
        self::assertSame([], NodeUserSyncJob::$dispatched);
    }
}
