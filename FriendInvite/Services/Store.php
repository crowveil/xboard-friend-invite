<?php

namespace Plugin\FriendInvite\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class Store
{
    public static function pack(array $value): string
    {
        return Crypt::encryptString(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public static function unpack(?string $value): array
    {
        return $value === null ? [] : json_decode(Crypt::decryptString($value), true, 64, JSON_THROW_ON_ERROR);
    }

    public static function read(string $key): array
    {
        return self::unpack(DB::table('friend_invite_state')->where('key', $key)->value('value'));
    }

    public static function put(string $key, array $value): void
    {
        DB::table('friend_invite_state')->updateOrInsert(['key' => $key], ['value' => self::pack($value), 'updated_at' => now()->timestamp]);
    }

    public static function locked(string $key, callable $fn): mixed
    {
        // A real row exists before locking; use the same connection as XBoard.
        DB::table('friend_invite_state')->insertOrIgnore(['key' => $key, 'value' => self::pack([]), 'updated_at' => now()->timestamp]);
        return DB::transaction(function () use ($key, $fn) {
            $row = DB::table('friend_invite_state')->where('key', $key)->lockForUpdate()->first();
            return $fn(self::unpack($row->value));
        }, 3);
    }
}
