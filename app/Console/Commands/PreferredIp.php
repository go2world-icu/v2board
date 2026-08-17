<?php

namespace App\Console\Commands;

use App\Models\ServerTrojan;
use App\Models\ServerV2node;
use App\Models\ServerVless;
use App\Services\PreferredNodeService;
use Illuminate\Console\Command;

class PreferredIp extends Command
{
    const MODELS = [
        'vless'  => ServerVless::class,
        'trojan' => ServerTrojan::class,
        'v2node' => ServerV2node::class,
    ];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'v2board:preferred-ip
        {--url= : 设置全局优选IP接口URL(留空=清除)}
        {--show : 显示当前URL及带"优选"标签的节点}
        {--test= : 测试URL并输出可解析IP数}
        {--purge : 清空池缓存}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '优选IP管理（配置全局优选IP来源URL，节点打"优选"标签后订阅自动展开）';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        if ($this->option('url') !== null) {
            return $this->setUrl();
        }
        if ($testUrl = $this->option('test')) {
            try {
                $r = PreferredNodeService::test($testUrl);
            } catch (\Throwable $e) {
                $this->error('拉取/解析失败：' . $e->getMessage());
                return 1;
            }
            $this->info('解析出 ' . $r['count'] . ' 个IP');
            foreach ($r['samples'] as $s) {
                $this->line('  - ' . $s);
            }
            return 0;
        }
        if ($this->option('purge')) {
            $count = PreferredNodeService::purge();
            $this->info('已清空池缓存（' . $count . ' 个URL）');
            return 0;
        }
        $this->show();
        return 0;
    }

    protected function setUrl(): int
    {
        $url = trim((string)$this->option('url'));
        try {
            PreferredNodeService::updateConfig([
                'preferred_ips_url' => $url !== '' ? $url : '',
            ]);
        } catch (\Throwable $e) {
            $this->error('写配置失败：' . $e->getMessage());
            return 1;
        }
        if ($url !== '') {
            PreferredNodeService::forget($url);
        }
        $this->info($url !== '' ? "已设置优选IP接口URL：" . $url : '已清除优选IP接口URL');
        return 0;
    }

    protected function show(): void
    {
        $url = trim((string)config('v2board.preferred_ips_url', ''));
        $this->line('=== 优选IP配置 ===');
        $this->line('接口URL: ' . ($url !== '' ? $url : '（未设置）'));
        $this->line('');
        $this->line('=== 带"优选"标签的节点 ===');
        $total = 0;
        foreach (self::MODELS as $type => $model) {
            $rows = $model::orderBy('sort', 'ASC')->get(['id', 'name', 'host', 'tags']);
            $count = 0;
            foreach ($rows as $row) {
                if (!self::hasPreferredTag($row->tags)) {
                    continue;
                }
                $count++;
                $total++;
                $this->line(sprintf('%-7s #%-4d %-20s %s', $type, $row->id, $row->name, $row->host));
            }
            if ($count === 0) {
                $this->line($type . ': （无）');
            }
        }
        if ($total === 0) {
            $this->line('提示：给要展开的节点 tags 加"优选"后，订阅时会自动追加优选IP克隆节点。');
        }
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
