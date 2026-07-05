/**
 * NAI Studio - 一键操作 tab
 *  - 一键启动: 启动 Apache + MySQL + 打开浏览器
 *  - 一键停止: 关闭后端服务
 *  - 一键清理: 清理画廊 + 缓存 + 孤立文件 + 日志
 */

import { api } from './api.js';
import { getState, setState } from './state.js';
import { toast } from './toast.js';
import { enqueueBatch } from './queue.js';
import { reloadGallery } from './gallery.js';

let _els = {};
let _statusTimer = null;

/** Batch modal (used by main panel custom link) */
function openBatchModal() {
    const modal = document.getElementById('batchModal');
    if (!modal) return;
    const s = getState();
    const nSel = document.getElementById('batchN');
    if (nSel) nSel.value = String(s.nSamples || 1);
    updateBatchEstimate();
    modal.classList.remove('hidden');
}
function closeBatchModal() {
    document.getElementById('batchModal')?.classList.add('hidden');
}
function updateBatchEstimate() {
    const count = parseInt(document.getElementById('batchCount')?.value || 1);
    const n = parseInt(document.getElementById('batchN')?.value || 1);
    const total = count * n;
    const el = document.getElementById('batchEstimate');
    if (el) el.textContent = `~${total} 次 NAI 请求 · ${count} 批次 · ${n} 张/次`;
}
async function confirmBatch() {
    const count = Math.max(1, Math.min(20, parseInt(document.getElementById('batchCount')?.value || 1)));
    const interval = Math.max(0, parseFloat(document.getElementById('batchInterval')?.value || 0));
    const randomSeed = document.getElementById('batchRandomSeed')?.checked ?? true;
    const autoRetry = document.getElementById('batchAutoRetry')?.checked ?? false;
    const n = Math.max(1, Math.min(4, parseInt(document.getElementById('batchN')?.value || 1)));

    const s = getState();
    if (s.nSamples !== n) setState({ nSamples: n });
    if (randomSeed) setState({ seed: 0 });

    toast(`开始批量：${count} 批 × ${n} 张/次${autoRetry ? '（失败自动重试）' : ''}`, { type: 'info' });
    enqueueBatch(count, interval, randomSeed, autoRetry);
    closeBatchModal();
}

/** Main panel quick queue button — default 4 张 / 20s */
function queueDefault() {
    const s = getState();
    if (!s.prompt) { toast('提示词为空', { type: 'warning' }); return; }
    if (!s.apiKeyPresent) { toast('请先设置 API Key', { type: 'warning' }); return; }
    enqueueBatch(4, 20);
    const btn = document.getElementById('queueGenerateBtn');
    btn?.classList.add('active');
    setTimeout(() => btn?.classList.remove('active'), 1500);
    toast('已加入队列：4 张 · 每 20s', { type: 'success' });
}

function closeSettingsModal() {
    document.getElementById('settingsModal')?.classList.add('hidden');
}

function setButtonState(btn, state) {
    if (!btn) return;
    btn.disabled = state === 'loading';
    btn.classList.toggle('loading', state === 'loading');
    btn.classList.toggle('success', state === 'success');
    btn.classList.toggle('error', state === 'error');
}

function renderStatus(s) {
    if (!_els.statusServer || !s) return;
    const okS = !!s.server;          // PHP server running
    const okD = !!s.db_ok;           // SQLite db ok
    _els.statusServer.classList.toggle('on', okS);
    _els.statusServer.classList.toggle('off', !okS);
    _els.statusServer.querySelector('.dot').classList.toggle('on', okS);
    _els.statusServer.querySelector('.dot').classList.toggle('off', !okS);
    const pid = s.pid ? ` (PID ${s.pid})` : '';
    _els.statusServer.querySelector('.label').textContent = okS
        ? `PHP 内置服务器 · 运行中${pid}`
        : 'PHP 内置服务器 · 未启动';

    _els.statusDb.classList.toggle('on', okD);
    _els.statusDb.classList.toggle('off', !okD);
    _els.statusDb.querySelector('.dot').classList.toggle('on', okD);
    _els.statusDb.querySelector('.dot').classList.toggle('off', !okD);
    _els.statusDb.querySelector('.label').textContent = okD
        ? `SQLite 数据库 · ${s.db_size_kb || 0} KB`
        : 'SQLite 数据库 · 缺失';

    // Overall status
    const allOn = okS && okD;
    const allOff = !okS && !okD;
    if (_els.statusOverall) {
        _els.statusOverall.classList.toggle('all-on', allOn);
        _els.statusOverall.classList.toggle('all-off', allOff);
        _els.statusOverall.classList.toggle('partial', !allOn && !allOff);
        _els.statusOverall.querySelector('.label').textContent =
            allOn ? '● 后端服务运行中' :
            allOff ? '● 后端服务未启动' :
            '● 部分就绪';
    }

    // Show/hide start/stop buttons
    // - 服务跑着 → 隐藏启动按钮（按了也无效），显示停止
    // - 服务没跑 → 启动按钮变为"打开 start.bat 提示"，隐藏停止
    if (_els.btnStart) {
        _els.btnStart.style.display = okS ? 'none' : '';
    }
    if (_els.btnStop) {
        _els.btnStop.style.display = okS ? '' : 'none';
    }
}

async function refreshStatus() {
    try {
        const r = await api.backendStatus();
        renderStatus(r);
        return r;
    } catch (e) {
        console.warn('status check failed', e);
        return null;
    }
}

async function backendStart() {
    // 启动必须从本地触发（服务要起才能连到这里），所以提示用户跑 start.bat
    // 显示 bat 路径
    const pathEl = document.getElementById('statusStartBatPath');
    const batPath = pathEl?.textContent && pathEl.textContent !== '—' ? pathEl.textContent : 'start.bat';
    toast('请运行 ' + batPath + ' 启动服务', { type: 'info', duration: 4000 });
    // 复制路径到剪贴板方便用户去资源管理器粘
    if (navigator.clipboard) {
        try { await navigator.clipboard.writeText(batPath); toast('路径已复制到剪贴板', { type: 'success', duration: 2000 }); } catch (e) {}
    }
}

async function backendStop() {
    if (!confirm('确定停止 NAI Studio 的 PHP 内置服务器吗？\n\n停止后网站将无法访问（直到重新启动）。')) return;
    setButtonState(_els.btnStop, 'loading');
    toast('正在停止服务…', { type: 'info' });
    try {
        const r = await api.backendStop();
        renderStatus(r);
        toast('✓ 服务已停止', { type: 'success' });
        setButtonState(_els.btnStop, 'success');
    } catch (e) {
        toast('停止失败: ' + e.message, { type: 'error' });
        setButtonState(_els.btnStop, 'error');
    }
    setTimeout(() => setButtonState(_els.btnStop, null), 2000);
}

function openCleanupModal() {
    const modal = document.getElementById('cleanupModal');
    if (!modal) return;
    api.listGallery({ per_page: 1 }).then(r => {
        const el = document.getElementById('cleanupRows');
        if (el) el.textContent = r.total || 0;
    });
    modal.classList.remove('hidden');
}
function closeCleanupModal() {
    document.getElementById('cleanupModal')?.classList.add('hidden');
}
async function confirmCleanup() {
    const keepFav = document.getElementById('cleanupKeepFavModal')?.checked ?? true;
    closeCleanupModal();
    toast('清理中…', { type: 'info' });
    try {
        const r = await api.cleanup('all', keepFav);
        const c = r.counts || {};
        const parts = [];
        if (c.rows) parts.push(`${c.rows} 条历史`);
        if (c.files) parts.push(`${c.files} 个文件`);
        if (c.cache) parts.push(`${c.cache} 缓存`);
        if (c.orphans) parts.push(`${c.orphans} 孤立文件`);
        if (c.logs) parts.push(`${c.logs} 日志`);
        toast('已清理: ' + (parts.join(' / ') || '无内容'), { type: 'success' });
        reloadGallery();
        document.getElementById('galleryMainImage')?.classList.add('hidden');
        document.getElementById('emptyGalleryMessage')?.classList.remove('hidden');
        refreshStatus();
    } catch (e) {
        toast('清理失败: ' + e.message, { type: 'error' });
    }
}

export function initActions() {
    _els = {
        btnStart:        document.getElementById('actionBackendStart'),
        btnStop:         document.getElementById('actionBackendStop'),
        btnRefresh:      document.getElementById('actionBackendRefresh'),
        btnCleanup:      document.getElementById('actionCleanup'),
        statusServer:    document.getElementById('statusServer'),
        statusDb:        document.getElementById('statusDb'),
        statusOverall:   document.getElementById('statusOverall'),
    };

    // 渲染 start.bat 路径（绝对路径，方便用户去资源管理器找）
    const pathEl = document.getElementById('statusStartBatPath');
    if (pathEl) {
        // 优先用 PHP 给的根目录（更准确），否则 fallback 到 nai-studio 相对路径
        // 通过 fetch /api/backend.php 拿不到这个（API 没暴露），所以前端自己构造
        pathEl.textContent = window.location.origin.replace(/\/+$/, '').includes('localhost') || window.location.port === '8080'
            ? 'D:\\anima\\nai-studio\\start.bat'        // 开发环境（绝对路径）
            : 'start.bat';                              // 相对路径
    }

    // Settings tab navigation (scoped to settings modal)
    document.querySelectorAll('#settingsModal .settings-tabs button').forEach(b => {
        b.addEventListener('click', () => {
            const target = b.dataset.settingsTab;
            document.querySelectorAll('#settingsModal .settings-tabs button').forEach(x => x.classList.toggle('active', x === b));
            document.querySelectorAll('#settingsModal .settings-pane').forEach(p => p.classList.toggle('hidden', p.dataset.pane !== target));
            // When switching to actions tab, refresh status
            if (target === 'actions') refreshStatus();
        });
    });

    // Backend buttons
    _els.btnStart?.addEventListener('click', backendStart);
    _els.btnStop?.addEventListener('click', backendStop);
    _els.btnRefresh?.addEventListener('click', () => { refreshStatus(); toast('已刷新状态', { type: 'info' }); });
    _els.btnCleanup?.addEventListener('click', openCleanupModal);

    // Main panel queue buttons (independent of settings modal)
    document.getElementById('queueGenerateBtn')?.addEventListener('click', queueDefault);
    document.getElementById('queueCustomBtn')?.addEventListener('click', openBatchModal);

    // Batch modal handlers
    document.getElementById('closeBatchBtn')?.addEventListener('click', closeBatchModal);
    document.getElementById('cancelBatchBtn')?.addEventListener('click', closeBatchModal);
    document.getElementById('confirmBatchBtn')?.addEventListener('click', confirmBatch);
    document.getElementById('batchCount')?.addEventListener('input', updateBatchEstimate);
    document.getElementById('batchN')?.addEventListener('change', updateBatchEstimate);

    // Keyboard: Ctrl+Shift+B opens batch modal
    window.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && e.shiftKey && (e.key === 'B' || e.key === 'b')) {
            e.preventDefault();
            openBatchModal();
        }
    });

    // Cleanup modal
    document.getElementById('closeCleanupBtn')?.addEventListener('click', closeCleanupModal);
    document.getElementById('cancelCleanupBtn')?.addEventListener('click', closeCleanupModal);
    document.getElementById('confirmCleanupBtn')?.addEventListener('click', confirmCleanup);

    // Cleanup toggle sync
    document.getElementById('cleanupKeepFav')?.addEventListener('change', (e) => {
        const m = document.getElementById('cleanupKeepFavModal');
        if (m) m.checked = e.target.checked;
    });
    document.getElementById('cleanupKeepFavModal')?.addEventListener('change', (e) => {
        const m = document.getElementById('cleanupKeepFav');
        if (m) m.checked = e.target.checked;
    });

    // 标签库扩充（仿 tags.novelai.dev）
    initExpandTags();

    // 全量 Danbooru 标签导入
    initImportAll();

    // 标签示例图抓取（仿 tags.novelai.dev：构建时预下载）
    initFetchImg();

    // Initial status check + auto-poll every 30s while modal is open
    refreshStatus();
}

/* ===== 标签库扩充 ===== */
let _expandTimer = null;
function initExpandTags() {
    const btnStart = document.getElementById('actionExpandTags');
    const btnStop  = document.getElementById('actionStopExpand');
    if (!btnStart) return;

    btnStart.addEventListener('click', async () => {
        const params = {
            min_posts:    parseInt(document.getElementById('expandMinPosts')?.value || '100'),
            max_pages:    parseInt(document.getElementById('expandMaxPages')?.value || '20'),
            with_images:  document.getElementById('expandWithImages')?.checked ?? true,
        };
        btnStart.disabled = true;
        btnStop.disabled = false;
        try {
            const r = await api.expandStart(params);
            if (r.ok) {
                toast('已启动标签扩充（可关浏览器继续跑）', { type: 'info' });
                pollExpandStatus();
            }
        } catch (e) {
            toast('启动失败: ' + e.message, { type: 'error' });
        } finally {
            btnStart.disabled = false;
        }
    });

    btnStop.addEventListener('click', async () => {
        try {
            await api.expandStop();
            toast('已请求停止（处理完当前 tag 后会停）', { type: 'warning' });
        } catch (e) {
            toast('停止失败: ' + e.message, { type: 'error' });
        }
    });

    // Page load: 显示当前状态
    refreshExpandStatus();
}

async function refreshExpandStatus() {
    try {
        const r = await api.expandStatus();
        renderExpandStatus(r);
    } catch {}
}

function renderExpandStatus(s) {
    const el = document.getElementById('expandProgress');
    const fill = document.getElementById('expandBarFill');
    if (!el || !fill || !s) return;
    if (s.status === 'idle') {
        el.textContent = '尚未运行';
        fill.style.width = '0%';
        return;
    }
    const pct = s.total > 0 ? Math.min(100, (s.progress / s.total) * 100) : 0;
    fill.style.width = pct + '%';
    const statusText = {
        running: '⏳ 扩充中',
        done:    '✅ 完成',
        stopped: '⏹ 已停止',
    }[s.status] || s.status;
    el.innerHTML = `
        <div>${statusText} · ${s.message || ''}</div>
        <div style="font-size:10px;color:var(--text-muted);margin-top:2px">
            处理 ${s.progress}/${s.total} · 新增 ${s.added} · 翻译 ${s.translated} · 图 ${s.images} · 跳过 ${s.skipped}
            ${s.errors ? '· 错 ' + s.errors : ''}
        </div>
        ${s.current_tag ? '<div style="font-size:10px;color:var(--text-muted);margin-top:2px">当前：' + s.current_tag + '</div>' : ''}
    `;
}

function pollExpandStatus() {
    clearInterval(_expandTimer);
    _expandTimer = setInterval(async () => {
        const s = await api.expandStatus();
        renderExpandStatus(s);
        if (s.status === 'done' || s.status === 'stopped' || s.status === 'idle') {
            clearInterval(_expandTimer);
            if (s.status === 'done') {
                toast('标签库扩充完成！', { type: 'success' });
            }
        }
    }, 2000);
}

// ===== 导入 Danbooru 全部标签 =====
let _importAllTimer = null;
function initImportAll() {
    const btnStart = document.getElementById('actionImportAll');
    const btnStop  = document.getElementById('actionStopImportAll');
    if (!btnStart) return;

    btnStart.addEventListener('click', async () => {
        const minPosts = parseInt(document.getElementById('importAllMinPosts')?.value || '1');
        const maxPages = parseInt(document.getElementById('importAllMaxPages')?.value || '500');
        if (!confirm(`启动后会从 Danbooru 拉 ${maxPages * 1000} 条 tag 入库，约 1-2 小时。\n建议先把「最低 post 数」调到 5 以上，跳过低频 tag。\n\n确定继续？`)) return;
        btnStart.disabled = true;
        try {
            const r = await api.importAllStart({ min_posts: minPosts, max_pages: maxPages });
            if (r.method === 'manual_cli') {
                // mod_php 不能 spawn, 显示命令让用户复制
                prompt('复制下面命令到 PowerShell / cmd 跑（可关闭浏览器, 后台继续）：', r.command);
                toast('已复制命令到剪贴板，粘贴到终端运行', { type: 'info', duration: 6000 });
            } else if (r.ok) {
                toast('已启动全量导入（可关浏览器，后台继续）', { type: 'info' });
                pollImportAll();
            }
        } catch (e) {
            toast('启动失败: ' + e.message, { type: 'error' });
        } finally {
            btnStart.disabled = false;
            btnStop.disabled = true;
        }
    });

    btnStop.addEventListener('click', async () => {
        try {
            await api.importAllStop();
            toast('已请求停止', { type: 'warning' });
        } catch (e) {
            toast(e.message, { type: 'error' });
        }
    });

    refreshImportAllStatus();
}

async function refreshImportAllStatus() {
    try {
        const r = await api.importAllStatus();
        renderImportAllStatus(r);
    } catch {}
}

function renderImportAllStatus(s) {
    const el = document.getElementById('importAllProgress');
    const fill = document.getElementById('importAllBarFill');
    if (!el || !fill || !s) return;
    if (s.status === 'idle') {
        el.textContent = '尚未运行';
        fill.style.width = '0%';
        return;
    }
    const total = s.pages_total * 1000;
    const pct = total > 0 ? Math.min(100, (s.fetched / total) * 100) : 0;
    fill.style.width = pct + '%';
    const statusText = {
        running: '⏳ 导入中',
        done:    '✅ 完成',
        stopped: '⏹ 已停止',
    }[s.status] || s.status;
    el.innerHTML = `
        <div>${statusText} · 第 ${s.current_page || 0}/${s.pages_total} 页 · ${s.message || ''}</div>
        <div style="font-size:10px;color:var(--text-muted);margin-top:2px">
            已处理 ${s.fetched} 条 · 速度 ${s.rate_per_sec || 0} 条/s · 跳过 ${s.skipped} · 错误 ${s.errors}
            ${s.last_error ? ' · 上次错: ' + s.last_error : ''}
        </div>
    `;
}

function pollImportAll() {
    clearInterval(_importAllTimer);
    _importAllTimer = setInterval(async () => {
        const s = await api.importAllStatus();
        renderImportAllStatus(s);
        if (s.status === 'done' || s.status === 'stopped' || s.status === 'idle') {
            clearInterval(_importAllTimer);
            if (s.status === 'done') {
                toast('全量导入完成！', { type: 'success', duration: 8000 });
                document.getElementById('actionImportAll').disabled = false;
            }
        }
    }, 3000);
}

// ===== 抓取标签示例图（SSE 流式，仿 tags.novelai.dev 预下载） =====
let _fetchImgES = null;

function initFetchImg() {
    const btnStart = document.getElementById('actionFetchImg');
    const btnStop  = document.getElementById('actionStopFetchImg');
    if (!btnStart) return;

    // 首次进入显示覆盖率
    refreshFetchImgCoverage();

    btnStart.addEventListener('click', () => {
        const limit = parseInt(document.getElementById('fetchImgLimit')?.value || '500');
        if (!confirm(`启动后会从 Danbooru 抓 ${limit} 张标签示例图存到本地 storage/tag-previews/。\n抓图后无需 JS 状态机，标签超市直接显示预览。\n\n预计耗时：${Math.ceil(limit / 40)} 分钟\n\n确定继续？`)) return;

        startFetchImg(limit);
    });

    btnStop.addEventListener('click', () => {
        if (_fetchImgES) {
            _fetchImgES.close();
            _fetchImgES = null;
            toast('已停止抓图', { type: 'warning' });
            btnStart.disabled = false;
            btnStop.disabled = true;
        }
    });
}

async function refreshFetchImgCoverage() {
    const el = document.getElementById('fetchImgCoverage');
    if (!el) return;
    try {
        const r = await api.fetchImgStats();
        el.innerHTML = `📊 当前 <strong>${r.have}</strong>/${r.total} 有图（${r.coverage}%）· 缺 <strong>${r.missing}</strong>`;
    } catch (e) {
        el.textContent = '查询失败: ' + e.message;
    }
}

function startFetchImg(limit) {
    const btnStart = document.getElementById('actionFetchImg');
    const btnStop  = document.getElementById('actionStopFetchImg');
    const el       = document.getElementById('fetchImgProgress');
    const fill     = document.getElementById('fetchImgBarFill');

    btnStart.disabled = true;
    btnStop.disabled = false;
    fill.style.width = '0%';
    el.innerHTML = '⏳ 启动中...';

    if (_fetchImgES) _fetchImgES.close();
    _fetchImgES = new EventSource(api.fetchImgStart(limit));

    let lastMsg = '';
    _fetchImgES.onmessage = (e) => {
        try {
            const d = JSON.parse(e.data);
            if (d.stage === 'start') {
                el.innerHTML = `📋 共 <strong>${d.total}</strong> 个待抓（limit=${d.limit}）`;
            } else if (d.stage === 'progress') {
                const pct = d.total > 0 ? (d.index / d.total * 100) : 0;
                fill.style.width = pct.toFixed(1) + '%';
                const icon = { ok: '✅', fail: '❌', noPosts: '·', skip: '⏭' }[d.status] || '?';
                el.innerHTML = `${icon} ${d.index}/${d.total} · ${d.name} · 累计 ✅${d.ok} ⏭${d.skip} ·${d.noPosts} ❌${d.fail} · ${d.elapsed}s`;
            } else if (d.stage === 'done') {
                el.innerHTML = `🎉 完成！本次抓 ${d.ok} 成功 · ${d.fail} 失败 · ${d.noPosts} 无 post · ${d.skip} 已存在 · 用时 ${d.elapsed}s · 全局覆盖率 <strong>${d.coverage}%</strong>（${d.global.have}/${d.global.total}）`;
                fill.style.width = '100%';
                btnStart.disabled = false;
                btnStop.disabled = true;
                _fetchImgES.close();
                _fetchImgES = null;
                toast(`🎉 抓图完成，全局覆盖率 ${d.coverage}%`, { type: 'success', duration: 5000 });
                refreshFetchImgCoverage();
            }
        } catch (err) {
            // ignore parse errors
        }
    };

    _fetchImgES.onerror = (e) => {
        if (_fetchImgES && _fetchImgES.readyState === EventSource.CLOSED) {
            // 正常关闭
            return;
        }
        el.innerHTML = '❌ 连接出错，已中断';
        btnStart.disabled = false;
        btnStop.disabled = true;
        if (_fetchImgES) _fetchImgES.close();
        _fetchImgES = null;
    };
}