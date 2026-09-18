<?php

namespace Plugin\FriendInvite;

use App\Services\Plugin\AbstractPlugin;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;
use Plugin\FriendInvite\Services\{ConsoleAccess, Invitations, Maintenance, Settings};

final class Plugin extends AbstractPlugin
{
    public function cleanup(): void
    {
        ConsoleAccess::close();
        Invitations::revokePending();
    }

    public function update(string $oldVersion, string $newVersion): void
    {
        File::ensureDirectoryExists(public_path('plugins/'.Settings::CODE));
        File::copyDirectory(__DIR__.'/resources/assets', public_path('plugins/'.Settings::CODE));
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->call([Maintenance::class, 'run'])->name('friend-invite-maintenance')->everyMinute()->withoutOverlapping(5);
    }
}
