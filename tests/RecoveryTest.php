<?php

use Illuminate\Support\Facades\{DB, File, Http, Schema};
use Plugin\FriendInvite\Services\{Assets, ConsoleAccess, Diagnostics, Failure, Outbox, Runtime, Settings, Store, Telegram};

final class RecoveryTest extends InviteTestCase
{
    public function testBootRepairsMissingPublicDirectoryAndExplicitRepairUpdatesPartialDirectory(): void
    {
        $plugin = new Plugin\FriendInvite\Plugin('friend_invite');
        File::deleteDirectory(public_path('plugins/friend_invite'));
        $plugin->boot();
        self::assertNotContains(false, Assets::inspect());
        unlink(public_path('plugins/friend_invite/console.js'));
        $plugin->boot();
        self::assertFalse(Assets::inspect()['console.js']);
        Assets::publish(true);
        self::assertNotContains(false, Assets::inspect());
        self::assertSame(1, count(Settings::get()['plan_ids']));
    }
    public function testStaleSettingsCannotOverwriteAnotherSaveOrDebug(): void
    {
        ConsoleAccess::change(true);
        $c = Settings::get();
        $revision = Settings::revision();
        Settings::debug(true);
        $r = $this->route('admin/settings', $c + ['revision' => $revision]);
        self::assertSame(409, $r->getStatusCode());
        self::assertGreaterThan(time(), Settings::get()['debug_until']);
        $r = $this->route('admin/settings', array_replace($c, ['invite_days' => 12, 'revision' => Settings::revision()]));
        self::assertSame(200, $r->getStatusCode());
        self::assertSame(12, Settings::get()['invite_days']);
        self::assertGreaterThan(time(), Settings::get()['debug_until']);
    }
    public function testFailedDatabaseDiagnosticsStillWriteSafeFileAndReport(): void
    {
        Schema::drop('friend_invite_events');
        $data = Diagnostics::failure('TEST_FAILURE', new RuntimeException('SECRET-TOKEN'));
        $log = file_get_contents(storage_path('app/friend-invite/diagnostics.jsonl'));
        self::assertStringContainsString($data['trace'], $log);
        self::assertStringNotContainsString('SECRET-TOKEN', $log);
        self::assertNull(Diagnostics::report()['events']);
        self::assertFalse(Runtime::check()['tables']['events']);
    }
    public function testAdminErrorIncludesTraceWithoutExceptionText(): void
    {
        ConsoleAccess::change(true);
        DB::table('friend_invite_state')->where('key', 'settings')->update(['value' => 'bad-encrypted-secret']);
        $r = $this->route('admin/state');
        self::assertSame(500, $r->getStatusCode());
        $body = json_decode($r->getContent(), true);
        self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $body['trace']);
        self::assertArrayHasKey('error_file', $body);
        self::assertStringNotContainsString('bad-encrypted-secret', $r->getContent());
        self::assertStringContainsString($body['trace'], file_get_contents(storage_path('app/friend-invite/diagnostics.jsonl')));
    }
    public function testFailedWebhookChangePersistsIntentThroughAdminEndpointAndRecoversSameSecret(): void
    {
        ConsoleAccess::change(true);
        $attempts = 0;
        $secrets = [];
        Http::fake(function ($request) use (&$attempts, &$secrets) {
            if (str_ends_with($request->url(), '/getMe')) {
                return Http::response(['ok' => true, 'result' => ['is_bot' => true, 'username' => 'synthetic_bot']]);
            }
            if (str_ends_with($request->url(), '/getWebhookInfo')) {
                return Http::response(['ok' => true, 'result' => ['url' => '']]);
            }
            $secrets[] = $request['secret_token'];
            return ++$attempts === 1 ? Http::response(['ok' => false], 500) : Http::response(['ok' => true, 'result' => true]);
        });
        $r = $this->route('admin/telegram/connect', ['token' => '123456:'.str_repeat('X', 32)]);
        self::assertSame(422, $r->getStatusCode());
        self::assertSame('connect', Store::read('bot')['pending']);
        self::assertFalse(Telegram::summary()['connected']);
        self::assertSame(200, $this->route('admin/telegram/recover', [])->getStatusCode());
        self::assertTrue(Telegram::summary()['connected']);
        self::assertSame($secrets[0], $secrets[1]);
    }
    public function testDisconnectFailureSuspendsBotUntilRecovered(): void
    {
        $this->bot();
        ConsoleAccess::change(true);
        $fail = true;
        Http::fake(function ($r) use (&$fail) {
            if (str_ends_with($r->url(), '/getWebhookInfo')) {
                return Http::response(['ok' => true, 'result' => ['url' => '']]);
            }
            return $fail ? Http::response(['ok' => false], 500) : Http::response(['ok' => true, 'result' => true]);
        });
        self::assertSame(422, $this->route('admin/telegram/disconnect', [])->getStatusCode());
        self::assertSame('disconnect', Telegram::summary()['pending']);
        $this->update(101, '/new');
        self::assertSame(0, DB::table('friend_invite_outbox')->count());
        $fail = false;
        self::assertSame(200, $this->route('admin/telegram/recover', [])->getStatusCode());
        self::assertSame([], Store::read('bot'));
    }
    public function testOutboxRetryRejectsOldBindingAndDoesNotCreateInvites(): void
    {
        $this->bot();
        $this->update(100, '/new');
        $row = DB::table('friend_invite_outbox')->first();
        DB::table('friend_invite_outbox')->update(['attempts' => 6]);
        Outbox::manage('retry', $row->id);
        self::assertSame(0, DB::table('friend_invite_outbox')->first()->attempts);
        self::assertSame(0, DB::table('friend_invite_invitations')->count());
        DB::table('friend_invite_outbox')->update(['attempts' => 6]);
        Telegram::unpair();
        try {
            Outbox::manage('retry', $row->id);
            self::fail();
        } catch (Failure $e) {
            self::assertSame(409, $e->status);
        }
        Outbox::manage('discard', $row->id);
        self::assertSame([], Outbox::manage('list')['messages']);
    }
    public function testForgetRequiresPendingOperationAndNeverCallsTelegram(): void
    {
        $this->bot();
        try {
            Telegram::forget();
            self::fail();
        } catch (Failure $e) {
            self::assertSame(409, $e->status);
        }
        $s = Store::read('bot');
        $s['pending'] = 'connect';
        $s['connected'] = false;
        Store::put('bot', $s);
        Telegram::forget();
        self::assertSame([], Store::read('bot'));
        Http::assertNothingSent();
    }
    public function testPendingBotOperationRetainsQueuedMessagesWithoutConsumingAttempts(): void
    {
        $this->bot();
        $this->update(108, '/new');
        $state = Store::read('bot');
        $state['pending'] = 'connect';
        $state['connected'] = false;
        Store::put('bot', $state);
        Outbox::flush();
        $row = DB::table('friend_invite_outbox')->first();
        self::assertNull($row->sent_at);
        self::assertSame(0, $row->attempts);
        Http::assertNothingSent();
    }
    public function testManagementGateDoesNotWrapRemoteOperationsInDatabaseTransaction(): void
    {
        ConsoleAccess::change(true);
        ConsoleAccess::withOpen(function () {
            self::assertSame(0, DB::transactionLevel());
        });
        ConsoleAccess::close();
        $this->expectException(Failure::class);
        ConsoleAccess::withOpen(fn () => self::fail('Closed gate must reject work'));
    }
    public function testUnpairInvalidatesOldDraftEvenWhenSameOwnerPairsAgain(): void
    {
        $this->bot();
        $this->update(100, '/new');
        $oldEpoch = Store::read('bot')['epoch'];
        Telegram::unpair();
        self::assertNotSame($oldEpoch, Store::read('bot')['epoch']);
    }
}
