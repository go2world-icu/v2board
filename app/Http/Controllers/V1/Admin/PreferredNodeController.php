<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ServerTrojan;
use App\Models\ServerV2node;
use App\Models\ServerVless;
use App\Services\PreferredNodeService;
use Illuminate\Http\Request;

class PreferredNodeController extends Controller
{
    public const MODELS = [
        'vless'  => ServerVless::class,
        'trojan' => ServerTrojan::class,
        'v2node' => ServerV2node::class,
    ];

    /** GET show：当前优选IP接口URL + 每节点克隆数上限 + 带"优选"标签的节点 */
    public function show(Request $request)
    {
        $url = trim((string)config('v2board.preferred_ips_url', ''));
        $maxPerNode = (int)config('v2board.preferred_ips_max_per_node', 0);
        $taggedNodes = [];
        foreach (self::MODELS as $type => $model) {
            $rows = $model::orderBy('sort', 'ASC')->get(['id', 'name', 'host', 'tags']);
            foreach ($rows as $row) {
                if (!self::hasPreferredTag($row->tags)) {
                    continue;
                }
                $taggedNodes[] = [
                    'type' => $type,
                    'id'   => $row->id,
                    'name' => $row->name,
                    'host' => $row->host,
                ];
            }
        }
        return response([
            'data' => [
                'url'          => $url,
                'max_per_node' => $maxPerNode,
                'taggedNodes'  => $taggedNodes,
            ]
        ]);
    }

    /** POST setUrl：设置/清除全局优选IP接口URL + 每节点克隆数上限（0=不限） */
    public function setUrl(Request $request)
    {
        $params = $request->validate([
            'url'          => 'nullable|string|max:500',
            'max_per_node' => 'nullable|integer|min:0|max:500',
        ]);
        $url = trim($params['url'] ?? '');
        try {
            PreferredNodeService::updateConfig([
                'preferred_ips_url'            => $url,
                'preferred_ips_max_per_node'   => isset($params['max_per_node']) ? (int)$params['max_per_node'] : (int)config('v2board.preferred_ips_max_per_node', 0),
            ]);
        } catch (\Throwable $e) {
            abort(500, '写配置失败：' . $e->getMessage());
        }
        if ($url !== '') {
            PreferredNodeService::forget($url);
        }
        return response([
            'data' => true
        ]);
    }

    /** POST test：拉取+解析一个URL，返回IP数（不写配置） */
    public function test(Request $request)
    {
        $params = $request->validate([
            'url' => 'required|string|max:500',
        ]);
        $url = trim($params['url']);
        if ($url === '') {
            abort(500, 'URL不能为空');
        }
        try {
            $result = PreferredNodeService::test($url);
        } catch (\Throwable $e) {
            abort(500, '拉取/解析失败：' . $e->getMessage());
        }
        return response([
            'data' => $result
        ]);
    }

    /** POST purge：清空池缓存 */
    public function purge(Request $request)
    {
        $count = PreferredNodeService::purge();
        return response([
            'data' => ['purged' => $count]
        ]);
    }

    protected function hasPreferredTag($tags): bool
    {
        $tags = is_array($tags) ? $tags : json_decode((string)$tags, true);
        if (!is_array($tags)) {
            $tags = [];
        }
        foreach ($tags as $tag) {
            $t = strtolower((string)$tag);
            if (strpos($t, '优选') !== false || $t === 'preferred') {
                return true;
            }
        }
        return false;
    }
}
