<?php

require __DIR__.'/bootstrap.php';
final class ConcurrentHarness extends InviteTestCase
{
    public function runClaim(string $db, string $code, string $email, float $start): void
    {
        $this->setUp();
        config(['database.connections.sqlite.database' => $db]);
        Illuminate\Support\Facades\DB::purge('sqlite');
        Illuminate\Support\Facades\DB::statement('PRAGMA busy_timeout=4000');
        while (microtime(true) < $start) {
            usleep(1000);
        }
        $r = app(App\Services\Auth\RegisterService::class)->register(Illuminate\Http\Request::create('/register', 'POST', ['email' => $email,'password' => 'testing-12345','invite_code' => $code]));
        echo json_encode(['success' => $r[0]]);
        $this->tearDown();
    }
}
(new ConcurrentHarness('worker'))->runClaim($argv[1], $argv[2], $argv[3], (float)$argv[4]);
