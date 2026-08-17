<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>优选IP管理 - {{ $version }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif;
            background: #f4f6f9; color: #333; padding: 24px;
        }
        .wrap { max-width: 900px; margin: 0 auto; }
        h1 { font-size: 20px; margin-bottom: 4px; }
        .sub { color: #888; font-size: 13px; margin-bottom: 20px; }
        .card {
            background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,.08);
            padding: 20px; margin-bottom: 20px;
        }
        .card h2 { font-size: 15px; margin-bottom: 12px; }
        .btn {
            display: inline-block; border: 1px solid #d9dde3; background: #fff; color: #333;
            padding: 6px 14px; border-radius: 5px; cursor: pointer; font-size: 13px;
        }
        .btn:hover { background: #f6f8fa; }
        .btn-primary { background: #409eff; border-color: #409eff; color: #fff; }
        .btn-primary:hover { background: #3588d1; }
        .btn-danger { color: #d9534f; }
        .btn-danger:hover { background: #fdf0ef; }
        .btn:disabled { opacity: .5; cursor: not-allowed; }
        input[type=text] {
            width: 100%; border: 1px solid #d9dde3; border-radius: 5px; padding: 6px 10px; font-size: 13px;
        }
        input[type=text]:focus { outline: none; border-color: #409eff; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #f0f2f5; }
        th { background: #fafbfc; color: #666; font-weight: 600; white-space: nowrap; }
        tr:hover td { background: #fafcff; }
        .tag { display: inline-block; background: #ecf5ff; color: #409eff; border-radius: 3px; padding: 1px 8px; font-size: 12px; }
        .muted { color: #999; }
        .empty { padding: 24px; text-align: center; color: #999; }
        .form-row { display: flex; gap: 10px; margin-top: 10px; }
        .form-row .btn { white-space: nowrap; }
        .hint { color: #888; font-size: 12px; margin-top: 10px; line-height: 1.8; }
        .hint code { background: #f4f4f4; border-radius: 3px; padding: 0 5px; }
        .msg {
            position: fixed; top: 20px; right: 20px; padding: 10px 16px; border-radius: 6px;
            color: #fff; font-size: 13px; opacity: 0; transition: opacity .25s; z-index: 99; max-width: 70vw;
        }
        .msg.show { opacity: 1; }
        .msg.ok { background: #67c23a; }
        .msg.err { background: #f56c6c; }
        .status { font-size: 13px; color: #888; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>优选IP管理</h1>
    <div class="sub">配置全局优选IP来源接口；给节点 tags 加"优选"后，订阅时自动追加该节点的优选IP克隆节点（原节点保留）。</div>

    <div class="card">
        <h2>优选IP来源接口</h2>
        <input type="text" id="url" placeholder="https://xxx.workers.dev/xxx/api/preferred-ips 或任意 ip[:port][#名称] 列表URL" autocomplete="off">
        <div class="form-row">
            <button class="btn btn-primary" id="btn-save" onclick="saveUrl()">保存</button>
            <button class="btn" id="btn-test" onclick="testUrl()">测试解析</button>
            <button class="btn btn-danger" onclick="purgeCache()">清空缓存</button>
            <span class="status" id="pool-status" style="align-self:center"></span>
        </div>
        <div class="hint">
            支持两种返回格式：CFnew 的 <code>/api/preferred-ips</code> 返回 JSON <code>{"data":[{ip,port,name}]}</code>，或每行一个 <code>ip[:port][#名称]</code> 的纯文本列表。<br>
            池按接口缓存 15 分钟；接口不可用时订阅自动回退为原节点（不影响订阅）。改完 URL 会自动清缓存。
        </div>
    </div>

    <div class="card">
        <h2>已打"优选"标签的节点 <span class="status" id="tagged-count"></span></h2>
        <table>
            <thead>
            <tr>
                <th style="width:80px">类型</th>
                <th style="width:60px">ID</th>
                <th>名称</th>
                <th>主机</th>
            </tr>
            </thead>
            <tbody id="tbody">
            <tr><td colspan="4" class="empty">加载中…</td></tr>
            </tbody>
        </table>
        <div class="hint">给节点打标签：后台「节点管理」编辑对应节点，在"标签"里加 <code>优选</code> 即可。订阅时该节点会追加最多 10 个换地址的克隆。</div>
    </div>
</div>

<div class="msg" id="msg"></div>

<script>
    const securePath = {!! json_encode($secure_path) !!};
    const apiBase = '/api/v1/' + securePath + '/preferred-ip';

    function token() {
        return localStorage.getItem('authorization') || '';
    }

    function toast(text, ok) {
        const el = document.getElementById('msg');
        el.textContent = text;
        el.className = 'msg show ' + (ok ? 'ok' : 'err');
        clearTimeout(el._t);
        el._t = setTimeout(() => { el.className = 'msg'; }, 3200);
    }

    async function api(path, opts) {
        opts = opts || {};
        const headers = opts.headers || {};
        headers['Authorization'] = token();
        if (opts.body) headers['Content-Type'] = 'application/x-www-form-urlencoded';
        const res = await fetch(apiBase + path, {
            method: opts.method || 'GET',
            headers: headers,
            body: opts.body ? new URLSearchParams(opts.body).toString() : undefined
        });
        if (res.status === 401 || res.status === 403) {
            toast('未登录或会话过期，请先在后台登录', false);
            setTimeout(() => { location.href = '/' + securePath + '#/login'; }, 1200);
            throw new Error('auth');
        }
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
            const msg = data && (data.message || data.errors) ? JSON.stringify(data.message || data.errors) : ('HTTP ' + res.status);
            throw new Error(msg);
        }
        return data.data;
    }

    async function load() {
        try {
            const d = await api('/show');
            document.getElementById('url').value = d.url || '';
            const tbody = document.getElementById('tbody');
            const nodes = d.taggedNodes || [];
            document.getElementById('tagged-count').textContent = '（' + nodes.length + ' 个）';
            if (!nodes.length) {
                tbody.innerHTML = '<tr><td colspan="4" class="empty">暂无带"优选"标签的节点</td></tr>';
                return;
            }
            tbody.innerHTML = nodes.map(n =>
                '<tr><td><span class="tag">' + (n.type === 'v2node' ? 'v2node' : n.type) + '</span></td>' +
                '<td>' + n.id + '</td>' +
                '<td>' + escapeHtml(n.name || '') + '</td>' +
                '<td class="muted">' + escapeHtml(n.host || '') + '</td></tr>'
            ).join('');
        } catch (e) {
            if (e.message !== 'auth') toast('加载失败：' + e.message, false);
        }
    }

    function escapeHtml(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    async function saveUrl() {
        const url = document.getElementById('url').value.trim();
        const btn = document.getElementById('btn-save');
        btn.disabled = true;
        try {
            await api('/setUrl', { method: 'POST', body: { url: url } });
            toast(url ? '已保存优选IP接口' + (url ? '，缓存已清' : '') : '已清除优选IP接口', true);
        } catch (e) {
            toast('保存失败：' + e.message, false);
        } finally {
            btn.disabled = false;
        }
    }

    async function testUrl() {
        const url = document.getElementById('url').value.trim();
        if (!url) { toast('请先填写 URL', false); return; }
        const btn = document.getElementById('btn-test');
        btn.disabled = true;
        try {
            const r = await api('/test', { method: 'POST', body: { url: url } });
            const status = document.getElementById('pool-status');
            status.textContent = '解析出 ' + r.count + ' 个IP' + (r.samples && r.samples.length ? '，示例：' + r.samples.join(' / ') : '');
            toast('解析出 ' + r.count + ' 个IP', true);
        } catch (e) {
            toast('测试失败：' + e.message, false);
        } finally {
            btn.disabled = false;
        }
    }

    async function purgeCache() {
        try {
            const r = await api('/purge', { method: 'POST' });
            toast('已清空池缓存（' + r.purged + ' 个URL）', true);
        } catch (e) {
            toast('清缓存失败：' + e.message, false);
        }
    }

    load();
</script>
</body>
</html>
