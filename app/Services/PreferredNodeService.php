<?php

namespace App\Services;

use App\Utils\CacheKey;
use Curl\Curl;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 优选节点服务（极简版）：维护一个全局"优选IP池"，订阅时把带"优选"标签的节点
 * 克隆为 N 份，仅替换 host（必要时 port），SNI/WS-Host/传输等全部保留。
 *
 * 优选IP只是 CF 边缘地址，与节点类型/传输/TLS 无关；生成优选节点 = 克隆节点换地址。
 * 池来源：config('v2board.preferred_ips_url') 指向的接口（CFnew /api/preferred-ips
 * 的 JSON 或任意 ip[:port][#name] 文本列表），按 URL 缓存 15 分钟。
 */
class PreferredNodeService
{
    /** 池缓存时长（秒），与 CFnew 15 分钟自动优选周期对齐 */
    const CACHE_TTL = 900;

    /** 每个带标签节点最多展开的克隆数，避免订阅被刷爆 */
    const MAX_PER_NODE = 10;

    /**
     * 展开：保留原节点，对 tags 含"优选"的节点追加 N 个换地址的克隆。
     * 在 ServerService::getAvailableServers() 末尾调用。
     */
    public static function expand(array &$servers): void
    {
        try {
            $pool = self::getPool();
        } catch (\Throwable $e) {
            // 接口不可用/超时：静默降级，原节点照常输出，不阻塞订阅
            Log::warning('[preferred-ip] 优选IP池拉取失败：' . $e->getMessage());
            return;
        }
        if (empty($pool)) {
            return;
        }
        $out = [];
        $usedNames = [];
        foreach ($servers as $server) {
            $out[] = $server; // 原节点永远保留
            if (!self::isPreferred($server)) {
                continue;
            }
            $idx = 0;
            foreach ($pool as $entry) {
                if ($idx >= self::MAX_PER_NODE) {
                    break;
                }
                $clone = $server; // 继承一切：SNI/WS-Host/tls_settings/rate/group/id…
                $clone['host'] = self::formatHost($entry['ip']); // IPv6 加括号
                $clone['port'] = !empty($entry['port']) ? (int)$entry['port'] : (int)$server['port'];
                unset($clone['mport']); // 端口已具体化
                $suffix = $entry['name'] ?: str_pad($idx + 1, 2, '0', STR_PAD_LEFT);
                $clone['name'] = ($server['name'] ?? '节点') . '-优选-' . $suffix;
                // 重名去重（Clash 等按 name 引用节点，必须唯一）
                if (isset($usedNames[$clone['name']])) {
                    $clone['name'] .= '-' . $server['id'];
                }
                $usedNames[$clone['name']] = 1;
                $clone['preferred_derived'] = true;
                $clone['cache_key'] = ($server['cache_key'] ?? 'preferred') . '-' . md5($clone['host'] . ':' . $clone['port']);
                $out[] = $clone;
                $idx++;
            }
        }
        $servers = $out;
    }

    /** 测试一个 URL：拉取+解析，返回节点数（不写配置、不落库） */
    public static function test(string $url): array
    {
        $pool = self::fetchPool($url, false);
        $samples = [];
        foreach (array_slice($pool, 0, 3) as $entry) {
            $samples[] = $entry['ip'] . ':' . ($entry['port'] ?? '') . ($entry['name'] ? '#' . $entry['name'] : '');
        }
        return ['count' => count($pool), 'samples' => $samples];
    }

    /** 清空指定 URL 的池缓存 */
    public static function forget(string $url): void
    {
        Cache::forget(self::cacheKey($url));
    }

    /** 清空当前配置 URL 的池缓存 */
    public static function purge(): int
    {
        $url = trim((string)config('v2board.preferred_ips_url', ''));
        if ($url === '') {
            return 0;
        }
        self::forget($url);
        return 1;
    }

    /**
     * 写全局配置（复刻 ConfigController::save 的文件写入机制）。
     * 键：preferred_ips_url。
     */
    public static function updateConfig(array $patch): bool
    {
        $config = config('v2board');
        if (!is_array($config)) {
            $config = [];
        }
        foreach ($patch as $k => $v) {
            $config[$k] = $v;
        }
        $data = var_export($config, 1);
        if (!\File::put(base_path() . '/config/v2board.php', "<?php\n return $data ;")) {
            throw new \RuntimeException('写入配置失败');
        }
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        try {
            \Artisan::call('config:cache');
        } catch (\Throwable $e) {
            Log::warning('[preferred-ip] config:cache 失败：' . $e->getMessage());
        }
        if (function_exists('posix_kill') && Cache::has('WEBMANPID')) {
            try {
                posix_kill(Cache::pull('WEBMANPID'), 15);
            } catch (\Throwable $e) {
                // 重启 webman 失败不影响配置已写入
            }
        }
        return true;
    }

    /** 节点 tags 是否含"优选"/"preferred" */
    private static function isPreferred(array $server): bool
    {
        $tags = $server['tags'] ?? [];
        if (!is_array($tags)) {
            $tags = (array)$tags;
        }
        foreach ($tags as $tag) {
            $t = strtolower((string)$tag);
            if (strpos($t, '优选') !== false || $t === 'preferred') {
                return true;
            }
        }
        return false;
    }

    /** IPv6 加方括号（URI 与 Clash 原始 YAML 均需） */
    private static function formatHost(string $host): string
    {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '[' . $host . ']' : $host;
    }

    /** 拉取并解析全局优选IP池（带缓存） */
    private static function getPool(): array
    {
        $url = trim((string)config('v2board.preferred_ips_url', ''));
        if ($url === '') {
            return [];
        }
        return self::fetchPool($url, true);
    }

    private static function fetchPool(string $url, bool $useCache): array
    {
        $key = self::cacheKey($url);
        if ($useCache) {
            $cached = Cache::get($key);
            if ($cached !== null) {
                return $cached;
            }
        }

        $curl = new Curl();
        $curl->setTimeout(10);
        $curl->setConnectTimeout(5);
        $curl->get($url);
        $status = $curl->getHttpStatusCode();
        // 用 rawResponse 拿原始字符串：$curl->response 对 JSON 响应会解码成 stdClass，
        // 导致 !is_string($body) 判断失败；rawResponse 才是原始 body。
        $body = isset($curl->rawResponse) ? $curl->rawResponse : $curl->response;
        if (!is_string($body)) {
            $body = is_object($body) || is_array($body) ? json_encode($body) : (string)$body;
        }
        $curl->close();

        if ($status === 0 || $status >= 400 || !is_string($body)) {
            throw new \RuntimeException('HTTP ' . $status . (is_string($body) ? ' body=' . substr($body, 0, 120) : ''));
        }

        $pool = self::parsePoolBody($body);
        if ($useCache) {
            Cache::put($key, $pool, self::CACHE_TTL);
        }
        return $pool;
    }

    private static function cacheKey(string $url): string
    {
        return CacheKey::get('PREFERRED_IP_POOL', md5($url));
    }

    /**
     * 解析池内容：兼容两种格式
     *  - JSON：CFnew /api/preferred-ips 的 {"data":[{"ip","port","name"}]} 或裸数组
     *  - 文本：每行 ip[:port][#name]
     */
    private static function parsePoolBody(string $body): array
    {
        $trimmed = trim($body);
        $pool = [];
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $decoded = json_decode($trimmed, true);
            $items = null;
            if (is_array($decoded)) {
                $items = isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : $decoded;
            }
            if (is_array($items)) {
                foreach ($items as $item) {
                    if (!is_array($item) || empty($item['ip'])) {
                        continue;
                    }
                    $entry = [
                        'ip'   => trim((string)$item['ip']),
                        'port' => !empty($item['port']) ? (int)$item['port'] : null,
                        'name' => !empty($item['name']) ? (string)$item['name'] : null,
                    ];
                    if ($entry['ip'] !== '') {
                        $pool[] = $entry;
                    }
                }
            }
            if ($pool) {
                return $pool;
            }
        }
        // 文本行
        foreach (preg_split('/\r\n|\r|\n/', $trimmed) as $line) {
            $entry = self::parseLine($line);
            if ($entry !== null) {
                $pool[] = $entry;
            }
        }
        return $pool;
    }

    /** 解析单行 ip[:port][#name]，支持裸 IPv6 与 [IPv6]:port */
    private static function parseLine(string $line): ?array
    {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '//') === 0) {
            return null;
        }
        // 拆 name
        $name = null;
        if (($pos = strpos($line, '#')) !== false) {
            $name = trim(substr($line, $pos + 1));
            $line = trim(substr($line, 0, $pos));
        }
        $ip = $line;
        $port = null;
        if (str_starts_with($line, '[')) {
            // [IPv6]:port
            if (($pos = strpos($line, ']')) !== false) {
                $ip = substr($line, 1, $pos - 1);
                $after = substr($line, $pos + 1);
                if (str_starts_with($after, ':')) {
                    $port = (int)substr($after, 1);
                }
            }
        } else {
            if (substr_count($line, ':') > 1) {
                // 裸 IPv6：多个冒号，整体当地址（避免误拆端口）
                $ip = $line;
            } else {
                $colonPos = strrpos($line, ':');
                if ($colonPos !== false && is_numeric(substr($line, $colonPos + 1))) {
                    $ip = substr($line, 0, $colonPos);
                    $port = (int)substr($line, $colonPos + 1);
                }
            }
        }
        if ($ip === '') {
            return null;
        }
        return [
            'ip'   => $ip,
            'port' => ($port !== null && $port > 0) ? $port : null,
            'name' => ($name !== '') ? $name : null,
        ];
    }
}
