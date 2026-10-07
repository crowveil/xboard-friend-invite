<?php

namespace Plugin\FriendInvite;

use App\Services\Plugin\AbstractPlugin;
use Illuminate\Console\Scheduling\Schedule;
use Plugin\FriendInvite\Services\{Assets, ConsoleAccess, Invitations, Maintenance, Settings};

final class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        if (Settings::enabled()) {
            Assets::publish();
        }
    }

    public function cleanup(): void
    {
        ConsoleAccess::close();
        Invitations::revokePending();
        Assets::remove();
    }

    public function update(string $oldVersion, string $newVersion): void
    {
        Assets::publish(true);
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->call([Maintenance::class, 'run'])->name('friend-invite-maintenance')->everyMinute()->withoutOverlapping(5);
    }
}
