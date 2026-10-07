<?php

namespace Plugin\FriendInvite\Commands;

use Illuminate\Console\Command;
use Plugin\FriendInvite\Services\{Assets, Diagnostics, Runtime};

final class ManageCommand extends Command
{
    protected $signature = 'friend-invite:manage {action : selfcheck, repair-assets or diagnose}';
    protected $description = 'Inspect FriendInvite deployment and repair public console assets';
    public function handle(): int
    {
        try {
            $action = $this->argument('action');
            if ($action === 'repair-assets') {
                Assets::publish(true);
                $result = Assets::inspect();
            } elseif ($action === 'selfcheck') {
                $result = Runtime::check();
            } elseif ($action === 'diagnose') {
                $result = Diagnostics::report();
            } else {
                $this->error('请选择 selfcheck、repair-assets 或 diagnose');
                return 1;
            }
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return 0;
        } catch (\Throwable $error) {
            $this->error(json_encode(Diagnostics::failure('CLI_FAILED', $error), JSON_UNESCAPED_UNICODE));
            return 1;
        }
    }
}
