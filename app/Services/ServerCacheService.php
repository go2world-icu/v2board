<?php

namespace App\Services;

use App\Models\ServerAnytls;
use App\Models\ServerHysteria;
use App\Models\ServerShadowsocks;
use App\Models\ServerTrojan;
use App\Models\ServerTuic;
use App\Models\ServerV2node;
use App\Models\ServerVless;
use App\Models\ServerVmess;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ServerCacheService
{
    private const GENERATION_KEY = 'SERVER_LIST_GENERATION_v2';

    public static function registerInvalidation(): void
    {
        $models = [
            ServerAnytls::class,
            ServerHysteria::class,
            ServerShadowsocks::class,
            ServerTrojan::class,
            ServerTuic::class,
            ServerV2node::class,
            ServerVless::class,
            ServerVmess::class,
        ];
        foreach ($models as $model) {
            $model::saved([self::class, 'invalidateAfterSave']);
            $model::deleted([self::class, 'invalidateAfterSave']);
        }
    }

    public static function remember(User $user, callable $build): array
    {
        $heartbeatKeys = [];
        $defaultConnection = config('database.default');
        if (!self::enabled()
            || (int)$user->group_id <= 0
            || ($user->getConnectionName() ?? $defaultConnection) !== $defaultConnection
            || DB::connection()->transactionLevel() > 0
        ) {
            return $build($heartbeatKeys);
        }

        $key = null;
        try {
            $generation = Cache::get(self::GENERATION_KEY);
            if ($generation === null) {
                Cache::add(self::GENERATION_KEY, bin2hex(random_bytes(16)), 86400);
                $generation = Cache::get(self::GENERATION_KEY);
            }
            if (is_string($generation)) {
                $key = 'SERVER_LIST_v2_' . $generation . '_' . (int)$user->group_id;
                $snapshot = Cache::get($key);
                if (isset($snapshot['servers'], $snapshot['heartbeat_keys'], $snapshot['probes_key'])) {
                    return self::restore($snapshot);
                }
            }
        } catch (Throwable $e) {
            self::warn($e);
            $key = null;
        }

        $servers = $build($heartbeatKeys);
        if ($key !== null) {
            try {
                // A concurrent invalidation changes the generation, so this build
                // can only publish to the generation it originally read.
                self::store($key, $servers, $heartbeatKeys);
            } catch (Throwable $e) {
                self::warn($e);
            }
        }
        return $servers;
    }

    public static function invalidateAfterSave(Model $model): void
    {
        $connection = $model->getConnection();
        $invalidate = static function (): void {
            try {
                self::forgetAll();
            } catch (Throwable $e) {
                self::warn($e);
            }
        };
        if ($connection->transactionLevel() > 0) {
            $connection->afterCommit($invalidate);
        } else {
            $invalidate();
        }
    }

    public static function forgetAll(): void
    {
        if (!Cache::forever(self::GENERATION_KEY, bin2hex(random_bytes(16)))) {
            throw new RuntimeException('Could not invalidate the server list cache.');
        }
    }

    public static function withHeartbeatStatus(array $server): array
    {
        $server['is_online'] = (time() - 300 > $server['last_check_at']) ? 0 : 1;
        $server['cache_key'] = "{$server['type']}-{$server['id']}-{$server['updated_at']}-{$server['is_online']}";
        return $server;
    }

    private static function store(string $key, array $servers, array $heartbeatKeys): void
    {
        $probesKey = $key . '_PROBES_' . bin2hex(random_bytes(8));
        $probes = [];
        foreach ($servers as &$server) {
            $heartbeatKey = $heartbeatKeys[$server['type'] . '-' . $server['id']] ?? null;
            if ($heartbeatKey === null) {
                // An upstream getter without a heartbeat hook cannot be restored safely.
                return;
            }
            $probes[$heartbeatKey] = $server['last_check_at'];
            $server['last_check_at'] = null;
            unset($server['is_online'], $server['cache_key']);
        }
        unset($server);

        if ($servers !== []) {
            Cache::put($probesKey, $probes, self::probesTtl());
        }
        Cache::put($key, [
            'servers' => $servers,
            'heartbeat_keys' => $heartbeatKeys,
            'probes_key' => $probesKey,
        ], self::snapshotTtl());
    }

    private static function restore(array $snapshot): array
    {
        $servers = $snapshot['servers'];
        if ($servers === []) {
            return [];
        }

        $probes = Cache::get($snapshot['probes_key']);
        if (!is_array($probes)) {
            $keys = array_values(array_unique($snapshot['heartbeat_keys']));
            $probes = Cache::many($keys);
            try {
                Cache::put($snapshot['probes_key'], $probes, self::probesTtl());
            } catch (Throwable $e) {
                self::warn($e);
            }
        }

        foreach ($servers as &$server) {
            $key = $snapshot['heartbeat_keys'][$server['type'] . '-' . $server['id']];
            $server['last_check_at'] = $probes[$key] ?? null;
            $server = self::withHeartbeatStatus($server);
        }
        unset($server);
        return $servers;
    }

    private static function enabled(): bool
    {
        return (int)config('v2board.server_cache_enable', 1) === 1;
    }

    private static function snapshotTtl(): int
    {
        $ttl = (int)config('v2board.server_cache_snapshot_ttl', 300);
        return $ttl > 0 ? $ttl : 300;
    }

    private static function probesTtl(): int
    {
        $ttl = (int)config('v2board.server_cache_probes_ttl', 30);
        return min($ttl > 0 ? $ttl : 30, self::snapshotTtl());
    }

    private static function warn(Throwable $e): void
    {
        try {
            Log::warning('[server-cache] ' . $e->getMessage());
        } catch (Throwable $ignored) {
        }
    }
}
