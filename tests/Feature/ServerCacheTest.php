<?php

namespace Tests\Feature;

use App\Models\ServerTuic;
use App\Models\ServerVmess;
use App\Models\User;
use App\Services\ServerCacheService;
use App\Services\ServerService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ServerCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(now());
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.url' => null,
            'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array',
            'v2board.server_cache_enable' => 1,
            'v2board.server_cache_snapshot_ttl' => 300,
            'v2board.server_cache_probes_ttl' => 30,
            'v2board.preferred_ips_url' => '',
        ]);
        DB::purge('sqlite');
        Cache::flush();
        foreach (['anytls', 'hysteria', 'shadowsocks', 'trojan', 'tuic', 'v2node', 'vless', 'vmess'] as $type) {
            Schema::create('v2_server_' . $type, function (Blueprint $table) {
                $table->id();
                $table->string('group_id');
                $table->string('port')->default('443');
                $table->boolean('show')->default(true);
                $table->integer('sort')->default(0);
                $table->integer('parent_id')->nullable();
                $table->integer('created_at');
                $table->integer('updated_at');
            });
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testWarmCacheSkipsNodeQueriesAndRefreshesTheSameHeartbeatKeys(): void
    {
        $parent = ServerTuic::create(['group_id' => [2], 'show' => false]);
        $child = ServerTuic::create(['group_id' => [1], 'parent_id' => $parent->id]);
        $missingParent = ServerTuic::create(['group_id' => [1], 'parent_id' => 99, 'sort' => 1]);
        $vmess = ServerVmess::create(['group_id' => [1], 'parent_id' => 99, 'sort' => 2]);
        $heartbeat = time();
        Cache::put('SERVER_TUIC_LAST_CHECK_AT_' . $child->id, $heartbeat);
        Cache::put('SERVER_TUIC_LAST_CHECK_AT_' . $missingParent->id, $heartbeat);
        Cache::put('SERVER_VMESS_LAST_CHECK_AT_' . $vmess->id, $heartbeat);
        $service = new ServerService();
        $user = new User(['group_id' => 1]);

        DB::enableQueryLog();
        $cold = $service->getAvailableServers($user);
        $this->assertCount(8, DB::getQueryLog());
        $this->assertSame([null, $heartbeat, null], array_column($cold, 'last_check_at'));
        $this->assertSame([0, 1, 0], array_column($cold, 'is_online'));
        DB::flushQueryLog();
        $this->assertSame($cold, $service->getAvailableServers($user));
        $this->assertSame([], DB::getQueryLog());

        Cache::put('SERVER_TUIC_LAST_CHECK_AT_' . $parent->id, $heartbeat);
        Cache::put('SERVER_TUIC_LAST_CHECK_AT_' . $missingParent->id, $heartbeat - 301);
        Cache::put('SERVER_VMESS_LAST_CHECK_AT_99', $heartbeat);
        Carbon::setTestNow(now()->addSeconds(31));
        $warm = $service->getAvailableServers($user);
        $this->assertSame([], DB::getQueryLog());
        $this->assertSame([$heartbeat, $heartbeat - 301, $heartbeat], array_column($warm, 'last_check_at'));
        $this->assertSame([1, 0, 1], array_column($warm, 'is_online'));
        $this->assertNotSame($cold[0]['cache_key'], $warm[0]['cache_key']);
        $this->assertSame([$heartbeat, $heartbeat - 301], array_column($service->getAvailableTuic($user), 'last_check_at'));
    }

    public function testNodeChangesInvalidateAfterCommitAndRemainCachedAfterRollback(): void
    {
        $first = ServerVmess::create(['group_id' => [1], 'sort' => 1]);
        $second = ServerVmess::create(['group_id' => [1], 'sort' => 2]);
        $service = new ServerService();
        $user = new User(['group_id' => 1]);
        $before = $service->getAvailableServers($user);

        DB::beginTransaction();
        $first->update(['show' => false]);
        DB::rollBack();
        DB::enableQueryLog();
        $this->assertSame($before, $service->getAvailableServers($user));
        $this->assertSame([], DB::getQueryLog());

        DB::transaction(function () use ($first, $second, $service, $user) {
            $first->refresh()->update(['sort' => 2]);
            $second->update(['sort' => 1]);
            $this->assertSame([$second->id, $first->id], array_column($service->getAvailableServers($user), 'id'));
        });
        DB::flushQueryLog();
        $after = $service->getAvailableServers($user);
        $this->assertSame([$second->id, $first->id], array_column($after, 'id'));
        $this->assertCount(8, DB::getQueryLog());
        $this->assertSame(array_column($before, 'updated_at'), array_column($after, 'updated_at'));

        $second->update(['group_id' => [2]]);
        $this->assertSame([$first->id], array_column($service->getAvailableServers($user), 'id'));
        $this->assertSame([$second->id], array_column($service->getAvailableServers(new User(['group_id' => 2])), 'id'));
        $first->update(['show' => false]);
        $this->assertSame([], $service->getAvailableServers($user));
        $first->update(['show' => true]);
        $this->assertSame([$first->id], array_column($service->getAvailableServers($user), 'id'));
        $first->delete();
        $this->assertSame([], $service->getAvailableServers($user));
    }

    public function testAnInFlightBuildCannotPublishIntoANewGeneration(): void
    {
        $user = new User(['group_id' => 1]);
        ServerCacheService::remember($user, function () {
            ServerCacheService::forgetAll();
            return [];
        });

        $rebuilt = false;
        ServerCacheService::remember($user, function () use (&$rebuilt) {
            $rebuilt = true;
            return [];
        });
        $this->assertTrue($rebuilt);
    }
}
