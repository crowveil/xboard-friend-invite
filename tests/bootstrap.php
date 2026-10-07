<?php

require getenv('FI_VENDOR_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
require __DIR__.'/HarnessModels.php';
spl_autoload_register(function ($class) {
    foreach (['App\\' => __DIR__.'/upstream/app/', 'Plugin\\FriendInvite\\' => dirname(__DIR__).'/FriendInvite/'] as $prefix => $root) {
        if (str_starts_with($class, $prefix)) {
            $path = $root.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
            if (is_file($path)) {
                require $path;
            }
        }
    }
});
function admin_setting($key, $default = null)
{
    return $GLOBALS['test_settings'][$key] ?? $default;
}

abstract class InviteTestCase extends PHPUnit\Framework\TestCase
{
    protected Illuminate\Foundation\Application $app;
    protected function setUp(): void
    {
        parent::setUp();
        $this->app = new Illuminate\Foundation\Application(dirname(__DIR__));
        $runtime = __DIR__.'/runtime/'.bin2hex(random_bytes(6));
        mkdir($runtime, 0700, true);
        $this->app->useStoragePath($runtime);
        $this->app->usePublicPath($runtime.'/public');
        Illuminate\Support\Facades\Facade::clearResolvedInstances();
        Illuminate\Support\Facades\Facade::setFacadeApplication($this->app);
        $this->app->instance('request', Illuminate\Http\Request::create('https://panel.example.test/'));
        $this->app->instance('config', new Illuminate\Config\Repository([
            'app' => ['key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'cipher' => 'AES-256-CBC', 'locale' => 'en', 'timezone' => 'UTC'],
            'database' => ['migrations' => ['table' => 'migrations'], 'default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]],
            'hashing' => ['driver' => 'bcrypt', 'bcrypt' => ['rounds' => 4]],
            'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]],
            'logging' => ['default' => 'null', 'channels' => ['null' => ['driver' => 'monolog', 'handler' => Monolog\Handler\NullHandler::class]]],
            'view' => ['paths' => [], 'compiled' => $runtime],
        ]));
        foreach ([Illuminate\Events\EventServiceProvider::class, Illuminate\Filesystem\FilesystemServiceProvider::class, Illuminate\Database\DatabaseServiceProvider::class,
            Illuminate\Database\MigrationServiceProvider::class, Illuminate\Cache\CacheServiceProvider::class, Illuminate\Encryption\EncryptionServiceProvider::class,
            Illuminate\Hashing\HashServiceProvider::class, Illuminate\Routing\RoutingServiceProvider::class, Illuminate\View\ViewServiceProvider::class,
            Illuminate\Translation\TranslationServiceProvider::class, Illuminate\Validation\ValidationServiceProvider::class] as $p) {
            $this->app->register($p);
        }
        $this->app->boot();
        $this->app->instance('db.transactions', new Illuminate\Database\DatabaseTransactionsManager());
        Illuminate\Http\Request::macro('validate', function ($rules) {
            return Illuminate\Support\Facades\Validator::make($this->all(), $rules)->validate();
        });
        $schema = Illuminate\Support\Facades\DB::connection()->getSchemaBuilder();
        $schema->create('plugins', function ($t) {
            $t->increments('id');
            $t->string('code');
            $t->boolean('is_enabled');
            $t->text('config');
            $t->string('name')->nullable();
            $t->string('version')->nullable();
            $t->string('type')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->timestamp('installed_at')->nullable();
        });
        $schema->create('users', function ($t) {
            $t->increments('id');
            $t->string('email')->unique();
            $t->text('remarks')->nullable();
            $t->string('password')->nullable();
            $t->string('uuid')->nullable();
            $t->string('token')->nullable();
            foreach (['group_id','plan_id','speed_limit','device_limit','expired_at','invite_user_id','last_login_at','next_reset_at'] as $c) {
                $t->bigInteger($c)->nullable();
            }
            foreach (['transfer_enable','u','d'] as $c) {
                $t->bigInteger($c)->default(0);
            }
            foreach (['is_admin','banned','remind_expire','remind_traffic'] as $c) {
                $t->boolean($c)->default(false);
            }
        });
        $schema->create('v2_server_group', function ($t) {
            $t->increments('id');
            $t->string('name');
        });
        $schema->create('v2_plan', function ($t) {
            $t->increments('id');
            $t->string('name');
            foreach (['group_id','transfer_enable','speed_limit','device_limit','reset_traffic_method','capacity_limit','created_at','updated_at'] as $c) {
                $t->bigInteger($c)->nullable();
            } $t->integer('sort')->default(0);
        });
        $schema->create('v2_invite_code', function ($t) {
            $t->increments('id');
            $t->bigInteger('user_id');
            $t->string('code', 32)->unique();
            $t->boolean('status');
            $t->bigInteger('created_at');
            $t->bigInteger('updated_at');
        });
        $GLOBALS['test_settings'] = ['invite_force' => true, 'try_out_plan_id' => 0, 'app_url' => 'https://panel.example.test'];
        $GLOBALS['captcha_result'] = [true,null];
        $this->app->instance('auth', new TestAuth());
        $manager = new class () extends App\Services\Plugin\PluginManager {
            public function __construct()
            {
                $this->pluginPath = dirname(__DIR__);
                $this->corePluginPath = __DIR__.'/no-core';
            }
        };
        $this->app->instance(App\Services\Plugin\PluginManager::class, $manager);
        app('migration.repository')->createRepository();
        $manager->install('friend_invite');
        App\Models\Plugin::where('code', 'friend_invite')->update(['is_enabled' => true]);
        App\Models\ServerGroup::create(['id' => 1,'name' => 'Friends']);
        App\Models\Plan::create(['id' => 1,'name' => '朋友套餐','group_id' => 1,'transfer_enable' => 100,'speed_limit' => 50,'device_limit' => 3,'reset_traffic_method' => 0]);
        App\Models\User::create(['id' => 1,'email' => 'admin@example.test','is_admin' => true]);
        app('auth')->admin = App\Models\User::find(1);
        Plugin\FriendInvite\Services\Settings::save(['accepting' => true,'plan_ids' => [1],'invite_days' => 7,'register_url' => 'https://panel.example.test/#/register?code={code}']);
        app('router')->middlewareGroup('api', []);
        app('router')->aliasMiddleware('admin', App\Http\Middleware\Admin::class);
        (new App\Http\Middleware\InitializePlugins($manager))->handle(request(), fn () => null);
        Illuminate\Support\Facades\Http::preventStrayRequests();
    }
    protected function tearDown(): void
    {
        Carbon\CarbonImmutable::setTestNow();
        Illuminate\Support\Carbon::setTestNow();
        App\Models\User::flushEventListeners();
        Mockery::close();
        Illuminate\Support\Facades\File::deleteDirectory($this->app->storagePath());
        Illuminate\Support\Facades\Facade::clearResolvedInstances();
        parent::tearDown();
    }
    protected function invite(string $term = 'month', array $extra = []): array
    {
        return Plugin\FriendInvite\Services\Invitations::create(1, array_replace(['plan_id' => 1,'duration' => $term], $extra), bin2hex(random_bytes(16)));
    }
    protected function code(array $p): string
    {
        parse_str(parse_url($p['url'], PHP_URL_FRAGMENT) ? explode('?', parse_url($p['url'], PHP_URL_FRAGMENT), 2)[1] : '', $q);
        return $q['code'];
    }
    protected function register(array $p, string $email = 'friend@example.test', array $extra = []): array
    {
        return app(App\Services\Auth\RegisterService::class)->register(Illuminate\Http\Request::create('/api/v1/passport/auth/register', 'POST', array_replace(['email' => $email,'password' => 'test-password-123','invite_code' => $this->code($p)], $extra)));
    }
    protected function route(string $path, ?array $body = null, array $headers = []): Symfony\Component\HttpFoundation\Response
    {
        $r = Illuminate\Http\Request::create('https://panel.example.test/api/v1/friend-invite/'.$path, $body === null ? 'GET' : 'POST', $body ?? []);
        foreach ($headers as $k => $v) {
            $r->headers->set($k, $v);
        } $this->app->instance('request', $r);
        return app('router')->dispatch($r);
    }
    protected function bot(bool $paired = true): void
    {
        Plugin\FriendInvite\Services\Store::put('bot', ['connected' => true,'token' => '123456:'.str_repeat('A', 32),'secret' => 'private-secret','epoch' => 'testepoch','username' => 'invite_test_bot','webhook_url' => 'https://panel.example.test/api/v1/friend-invite/telegram','owner_id' => $paired ? '12345' : null,'admin_id' => $paired ? 1 : null]);
    }
    protected function update(int $n, string $text, bool $callback = false, string $owner = '12345'): void
    {
        $m = ['chat' => ['id' => (int)$owner,'type' => 'private'],'from' => ['id' => (int)$owner,'is_bot' => false],'text' => $text];
        Plugin\FriendInvite\Services\Bot::receive(['update_id' => $n, $callback ? 'callback_query' : 'message' => $callback ? ['id' => 'cb-'.$n,'from' => $m['from'],'message' => $m,'data' => $text] : $m]);
    }
    protected function messages(): array
    {
        return Illuminate\Support\Facades\DB::table('friend_invite_outbox')->get()->map(fn ($r) => Plugin\FriendInvite\Services\Store::unpack($r->payload))->all();
    }
}
final class TestAuth
{
    public mixed $admin = null;
    public function guard($name)
    {
        return new class ($this->admin) {
            public function __construct(private $u)
            {
            } public function user()
            {
                return $this->u;
            }
        };
    }
}
