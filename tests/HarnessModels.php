<?php

// Test-only native models/services not under test. RegisterService, UserService,
// Plan, InviteCode, middleware, plugin manager and config service are upstream.

namespace App\Models {
    class Plugin extends \Illuminate\Database\Eloquent\Model
    {
        protected $table = 'plugins';
        public $timestamps = false;
        protected $guarded = [];
    }
    class User extends \Illuminate\Database\Eloquent\Model
    {
        protected $table = 'users';
        public $timestamps = false;
        protected $guarded = [];
        public function plan()
        {
            return $this->belongsTo(Plan::class, 'plan_id');
        }
        public function scopeByEmail($q, $email)
        {
            return $q->whereRaw('lower(email) = ?', [strtolower($email)]);
        }
    }
    class ServerGroup extends \Illuminate\Database\Eloquent\Model
    {
        protected $table = 'v2_server_group';
        public $timestamps = false;
        protected $guarded = [];
    }
}

namespace App\Exceptions { class ApiException extends \RuntimeException
{
} }

namespace App\Services { class CaptchaService
{
    public function verify($request): array
    {
        return $GLOBALS['captcha_result'] ?? [true, null];
    }
} }

namespace App\Jobs {
    class NodeUserSyncJob
    {
        public static array $dispatched = [];
        public static function dispatch(...$args)
        {
            self::$dispatched[] = $args;
        }
    }
}
