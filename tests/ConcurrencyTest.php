<?php

use Illuminate\Support\Facades\DB;

final class ConcurrencyTest extends InviteTestCase
{
    public function testTwoProcessesCannotClaimSameInvitation(): void
    {
        $p = $this->invite();
        $db = $this->app->storagePath('claims.sqlite');
        DB::statement('VACUUM INTO '.DB::connection()->getPdo()->quote($db));
        $processes = [];
        $start = microtime(true) + 0.4;
        for ($i = 0;$i < 2;$i++) {
            $command = [PHP_BINARY,'-c',php_ini_loaded_file(),__DIR__.'/concurrent_worker.php',$db,$this->code($p),'claim'.$i.'@example.test',(string)$start];
            $proc = proc_open($command, [1 => ['pipe','w'],2 => ['pipe','w']], $pipes);
            $processes[] = [$proc,$pipes];
        }
        $success = 0;
        foreach ($processes as [$proc,$pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($proc), $out.$err);
            $success += (int)json_decode($out, true, 512, JSON_THROW_ON_ERROR)['success'];
        }
        self::assertSame(1, $success);
        $pdo = new PDO('sqlite:'.$db);
        self::assertSame(2, (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        self::assertSame(1, (int)$pdo->query('SELECT COUNT(*) FROM friend_invite_invitations WHERE used_by IS NOT NULL')->fetchColumn());
    }
}
