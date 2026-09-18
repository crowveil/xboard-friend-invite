<?php

namespace Plugin\FriendInvite\Services;

use Illuminate\Support\Facades\DB;

final class Maintenance
{
    public static function run(): void
    {
        if (!Settings::enabled()) {
            return;
        }
        Outbox::flush(5);
        DB::table('friend_invite_updates')->where('created_at', '<', now()->timestamp - 14 * 86400)->delete();
        DB::table('friend_invite_outbox')->whereNotNull('sent_at')->where('sent_at', '<', now()->timestamp - 7 * 86400)->delete();
        DB::table('friend_invite_state')->where('key', 'like', 'draft:%')->where('updated_at', '<', now()->timestamp - 86400)->delete();
        foreach (Invitations::table()->whereNull('used_by')->where('revoked', false)->where('expires_at', '<=', now()->timestamp)->pluck('native_id') as $id) {
            \App\Models\InviteCode::where('id', $id)->update(['status' => \App\Models\InviteCode::STATUS_USED]);
        }
    }
}
