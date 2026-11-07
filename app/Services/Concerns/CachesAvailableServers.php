<?php

namespace App\Services\Concerns;

use App\Models\User;
use App\Services\PreferredNodeService;
use App\Services\ServerCacheService;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;

trait CachesAvailableServers
{
    private $subscriptionHeartbeatKeys;

    private function rememberAvailableServers(User $user): array
    {
        $servers = ServerCacheService::remember($user, function (array &$heartbeatKeys) use ($user) {
            $previousKeys = $this->subscriptionHeartbeatKeys;
            $this->subscriptionHeartbeatKeys = [];
            try {
                $servers = $this->loadAvailableServers($user);
                $heartbeatKeys = $this->subscriptionHeartbeatKeys;
                return $servers;
            } finally {
                $this->subscriptionHeartbeatKeys = $previousKeys;
            }
        });

        // 优选 IP 池可独立更新，每次请求都重新展开。
        PreferredNodeService::expand($servers);
        return $servers;
    }

    private function serverHeartbeat(string $prefix, $server, $sourceId)
    {
        $key = CacheKey::get($prefix, $sourceId);
        if ($this->subscriptionHeartbeatKeys === null) {
            return Cache::get($key);
        }

        // 如果 getter 改用父节点心跳，后一次读取会覆盖自身的键。
        $this->subscriptionHeartbeatKeys[$server['type'] . '-' . $server['id']] = $key;
        return null;
    }

    private function loadServerHeartbeats(array $servers): array
    {
        $visibleKeys = [];
        foreach ($servers as $server) {
            $id = $server['type'] . '-' . $server['id'];
            if (isset($this->subscriptionHeartbeatKeys[$id])) {
                $visibleKeys[$id] = $this->subscriptionHeartbeatKeys[$id];
            }
        }
        $this->subscriptionHeartbeatKeys = $visibleKeys;

        if ($visibleKeys === []) {
            return $servers;
        }
        $keys = array_values(array_unique($visibleKeys));
        $probes = Cache::many($keys);
        foreach ($servers as &$server) {
            $id = $server['type'] . '-' . $server['id'];
            if (isset($visibleKeys[$id])) {
                $server['last_check_at'] = $probes[$visibleKeys[$id]] ?? null;
            }
        }
        unset($server);
        return $servers;
    }
}
