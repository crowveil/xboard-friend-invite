<?php

use App\Models\User;
use Illuminate\Support\Facades\{DB,Http};
use Plugin\FriendInvite\Services\{Bot, ConsoleAccess, Failure, Invitations, Outbox, Store, Telegram};

final class TelegramTest extends InviteTestCase
{
    public function testConnectUsesDedicatedBotAndSecretWebhookWithoutEchoingToken(): void
    {
        Http::fake(['*getMe' => Http::response(['ok' => true,'result' => ['is_bot' => true,'username' => 'friend_test_bot']]), '*getWebhookInfo' => Http::response(['ok' => true,'result' => ['url' => '']]), '*setWebhook' => Http::response(['ok' => true,'result' => true])]);
        $token = '123456:'.str_repeat('T', 32);
        $s = Telegram::connect($token, 'https://panel.example.test');
        self::assertTrue($s['connected']);
        self::assertStringNotContainsString($token, json_encode($s));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/setWebhook') && strlen($r['secret_token']) === 64 && $r['allowed_updates'] === ['message','callback_query']);
        self::assertStringNotContainsString($token, DB::table('friend_invite_state')->where('key', 'bot')->value('value'));
    }
    public function testExistingUnrelatedWebhookIsNotOverwritten(): void
    {
        Http::fake(['*getMe' => Http::response(['ok' => true,'result' => ['is_bot' => true,'username' => 'bot']]), '*getWebhookInfo' => Http::response(['ok' => true,'result' => ['url' => 'https://other.example.test/hook']])]);
        try {
            Telegram::connect('123456:'.str_repeat('T', 32), 'https://panel.example.test');
            self::fail('must reject');
        } catch (Failure $e) {
            self::assertStringContainsString('其他 Webhook', $e->reason);
        }
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/setWebhook'));
    }
    public function testNativeBotCannotBeReusedAndHttpIsRejected(): void
    {
        $token = '123456:'.str_repeat('T', 32);
        $GLOBALS['test_settings']['telegram_bot_token'] = $token;
        try {
            Telegram::connect($token, 'https://panel.example.test');
            self::fail();
        } catch (Failure $e) {
            self::assertStringContainsString('独立机器人', $e->reason);
        }
        $GLOBALS['test_settings']['telegram_bot_token'] = '';
        try {
            Telegram::connect($token, 'http://panel.example.test');
            self::fail();
        } catch (Failure $e) {
            self::assertStringContainsString('HTTPS', $e->reason);
        }
        Http::assertNothingSent();
    }
    public function testPairingIsOneUseBoundToAdminAndPrivateSender(): void
    {
        $this->bot(false);
        $command = Telegram::pair(1);
        $this->update(1, '/bind wrong');
        self::assertNull(Telegram::summary()['owner_id']);
        $this->update(2, $command);
        self::assertSame('12345', Telegram::summary()['owner_id']);
        $this->update(3, $command, false, '22222');
        self::assertSame('12345', Telegram::summary()['owner_id']);
        $before = DB::table('friend_invite_outbox')->count();
        $this->update(4, '/new', false, '22222');
        self::assertSame($before, DB::table('friend_invite_outbox')->count());
        Bot::receive(['update_id' => 5,'message' => ['from' => ['id' => 12345],'chat' => ['id' => 12345,'type' => 'group'],'text' => '/new']]);
        Bot::receive(['update_id' => 6,'message' => ['from' => ['id' => 12345],'chat' => ['id' => 22222,'type' => 'private'],'text' => '/new']]);
        self::assertSame($before, DB::table('friend_invite_outbox')->count());
    }
    public function testExpiredPairAndRevokedAdminCannotControlBot(): void
    {
        $this->bot(false);
        $command = Telegram::pair(1);
        $pair = Store::read('pair');
        $pair['expires_at'] = time() - 1;
        Store::put('pair', $pair);
        $this->update(1, $command);
        self::assertNull(Telegram::summary()['owner_id']);
        $this->bot();
        User::where('id', 1)->update(['is_admin' => false]);
        $this->update(2, '/new');
        self::assertSame(0, DB::table('friend_invite_outbox')->count());
    }
    public function testFullButtonFlowFiveTermsAndDoublePressCreatesOneInvitation(): void
    {
        $this->bot();
        ConsoleAccess::close();
        $this->update(1, '/new 小林');
        $m = $this->messages();
        $choose = $m[0]['data']['reply_markup']['inline_keyboard'][0][0]['callback_data'];
        $draft = explode(':', $choose)[1];
        $this->update(2, $choose, true);
        $messages = $this->messages();
        $terms = array_values(array_filter($messages, fn ($p) => count($p['data']['reply_markup']['inline_keyboard'] ?? []) === 5));
        self::assertCount(1, $terms);
        self::assertSame(['1 天','1 个月','1 年','3 年','永久'], array_map(fn ($b) => $b[0]['text'], $terms[0]['data']['reply_markup']['inline_keyboard']));
        $this->update(3, 't:'.$draft.':year', true);
        $this->update(4, 'make:'.$draft, true);
        $this->update(4, 'make:'.$draft, true);
        $this->update(5, 'make:'.$draft, true);
        self::assertSame(1, Invitations::table()->count());
        self::assertSame('year', Invitations::table()->first()->duration);
        $this->update(6, 't:'.$draft.':forever', true);
        self::assertSame('year', Invitations::table()->first()->duration);
        $messages = $this->messages();
        self::assertTrue((bool)array_filter($messages, fn ($p) => str_contains($p['data']['text'] ?? '', "小林\n")));
    }
    public function testWrongWebhookSecretNeverCreatesAnything(): void
    {
        $this->bot();
        $r = $this->route('telegram', ['update_id' => 1,'message' => []], ['X-Telegram-Bot-Api-Secret-Token' => 'wrong']);
        self::assertSame(403, $r->getStatusCode());
        self::assertSame(0, Invitations::table()->count());
        $r = $this->route('telegram', ['update_id' => 1,'message' => []], ['X-Telegram-Bot-Api-Secret-Token' => 'private-secret']);
        self::assertSame(200, $r->getStatusCode());
    }
    public function testNotificationIsQueuedAfterSuccessfulClaimAndRetriesWithoutNewGrants(): void
    {
        $this->bot();
        $p = $this->invite();
        self::assertTrue($this->register($p)[0]);
        self::assertSame(1, DB::table('friend_invite_outbox')->count());
        Http::fake(['*' => Http::sequence()->push(['ok' => false], 500)->push(['ok' => true,'result' => ['message_id' => 1]], 200)]);
        Outbox::flush(1);
        $row = DB::table('friend_invite_outbox')->first();
        self::assertSame(1, $row->attempts);
        self::assertNull($row->sent_at);
        DB::table('friend_invite_outbox')->update(['available_at' => time() - 1]);
        Outbox::flush(1);
        self::assertNotNull(DB::table('friend_invite_outbox')->first()->sent_at);
        self::assertSame(2, User::count());
        self::assertFalse($this->register($p, 'another@example.test')[0]);
    }
    public function testUnpairedOwnerDropsPendingMessagesWithoutSending(): void
    {
        $this->bot();
        $this->update(1, '/new');
        self::assertGreaterThan(0, DB::table('friend_invite_outbox')->count());
        Telegram::unpair();
        Outbox::flush(10);
        Http::assertNothingSent();
        self::assertSame(0, DB::table('friend_invite_outbox')->whereNull('sent_at')->count());
    }
    public function testRealJsonWebhookDispatchesAuthorizedCommand(): void
    {
        $this->bot();
        Http::fake(['*' => Http::response(['ok' => true,'result' => ['message_id' => 1]])]);
        $u = ['update_id' => 900,'message' => ['from' => ['id' => 12345],'chat' => ['id' => 12345,'type' => 'private'],'text' => '/new']];
        $request = Illuminate\Http\Request::create('https://panel.example.test/api/v1/friend-invite/telegram', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json','HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => 'private-secret'], json_encode($u));
        $this->app->instance('request', $request);
        self::assertSame(200, app('router')->dispatch($request)->getStatusCode());
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage') && $r['chat_id'] === '12345' && isset($r['reply_markup']['inline_keyboard']));
        self::assertSame(1, DB::table('friend_invite_updates')->count());
    }

    public function testChangingBotRequiresDisconnectFirst(): void
    {
        $this->bot();
        try {
            Telegram::connect('987654:'.str_repeat('X', 32), 'https://panel.example.test');
            self::fail();
        } catch (Failure $e) {
            self::assertStringContainsString('先断开', $e->reason);
        }
        Http::assertNothingSent();
    }
}
