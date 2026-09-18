<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        self::create('friend_invite_state', function (Blueprint $t) {
            $t->string('key', 100)->primary();
            $t->text('value');
            $t->bigInteger('updated_at');
        });
        self::create('friend_invite_invitations', function (Blueprint $t) {
            $t->string('id', 32)->primary();
            $t->string('code_hash', 64)->unique();
            $t->text('code_cipher');
            $t->bigInteger('native_id')->unique();
            $t->bigInteger('plan_id');
            $t->string('duration', 16);
            $t->bigInteger('expires_at');
            $t->bigInteger('created_by');
            $t->bigInteger('created_at');
            $t->bigInteger('used_by')->nullable();
            $t->bigInteger('used_at')->nullable();
            $t->boolean('revoked')->default(false);
            $t->string('operation_key', 64)->unique();
            $t->text('details');
        });
        self::create('friend_invite_updates', function (Blueprint $t) {
            $t->string('id', 100)->primary();
            $t->bigInteger('created_at')->index();
        });
        self::create('friend_invite_outbox', function (Blueprint $t) {
            $t->string('id', 64)->primary();
            $t->string('epoch', 32);
            $t->text('payload');
            $t->integer('attempts')->default(0);
            $t->bigInteger('available_at');
            $t->bigInteger('sent_at')->nullable();
            $t->string('lease', 32)->nullable();
            $t->bigInteger('created_at')->index();
        });
        self::create('friend_invite_events', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('event', 60);
            $t->text('context');
            $t->bigInteger('created_at');
        });
    }

    private static function create(string $name, \Closure $callback): void
    {
        if (!Schema::hasTable($name)) {
            Schema::create($name, $callback);
        }
    }

    public function down(): void
    {
        // Keep claimed users and audit data on uninstall. Data removal is explicit.
        // Reinstall can reuse these tables (see plugin install migrations note).
    }
};
