<?php

namespace Plugin\FriendInvite\Controllers;

use Illuminate\Http\Request;
use Plugin\FriendInvite\Services\{Bot, Diagnostics, Outbox, Settings, Store};

final class TelegramController
{
    public function webhook(Request $request)
    {
        if (!Settings::enabled()) {
            return response()->json(['ok' => false], 404);
        }
        $s = Store::read('bot');
        $secret = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');
        if (empty($s['secret']) || empty($s['connected']) || !hash_equals($s['secret'], $secret)) {
            return response()->json(['ok' => false], 403);
        }
        if (strlen($request->getContent()) > 65536) {
            return response()->json(['ok' => false], 413);
        }
        try {
            Bot::receive($request->json()->all());
            Outbox::flush(3);
            return response()->json(['ok' => true]);
        } catch (\Throwable) {
            Diagnostics::record('TELEGRAM_UPDATE_FAILED', [], true);
            return response()->json(['ok' => false], 503);
        }
    }
}
