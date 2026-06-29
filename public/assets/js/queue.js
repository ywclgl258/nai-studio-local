/**
 * NAI Studio - Batch generation queue
 * N images with interval seconds, random seeds, auto-save
 */

import { api } from './api.js?v=104';
import { getState } from './state.js?v=104';
import { buildGeneratePayload } from './generate-payload.js?v=104';
import { toast } from './toast.js?v=104';
import { reloadGallery } from './gallery.js?v=104';

let _queue = [];
let _running = false;
let _els = {};
let _randomSeed = true;   // 由 enqueueBatch 决定
let _autoRetry = false;   // 失败时自动进入下一轮
let _maxRounds = 3;       // 最多重试轮次
let _aborted = false;     // 用户中途取消标志

function newBatchId() {
    return 'b_' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6);
}

async function runQueue() {
    if (_running) return;
    _running = true;
    _aborted = false;
    showTray(true);
    const s = getState();
    const originalCount = _queue.length;
    let round = 1;
    let lastError = null;

    while (round <= _maxRounds && _queue.some(it => it.status !== 'done')) {
        const pendingItems = _queue.filter(it => it.status !== 'done');
        const totalRounds = _autoRetry ? _maxRounds : 1;
        if (round > 1) {
            // 把失败的 item 重置为 pending 再跑
            for (const it of pendingItems) {
                it.status = 'pending';
                it.progress = 0;
                it.error = null;
                it.round = round;
                renderItem(it);
            }
            toast(`第 ${round} 轮重试：${pendingItems.length} 张`, { type: 'info' });
        } else {
            for (const it of pendingItems) it.round = round;
        }
        const batchId = newBatchId();
        const roundInterval = round === 1 ? (pendingItems[0]?.interval || 0) : 5;  // 重试轮间隔 5s
        for (let i = 0; i < pendingItems.length; i++) {
            if (_aborted) break;
            const item = pendingItems[i];
            item.status = 'running';
            item.progress = 0.1;
            renderItem(item);
            try {
                const payload = buildGeneratePayload(s, batchId, item.overrides || {});
                if (pendingItems.length > 1 && _randomSeed) {
                    payload.seed = Math.floor(Math.random() * 4294967295);
                }
                item.progress = 0.3;
                renderItem(item);
                const r = await api.generate(payload);
                item.progress = 1;
                item.status = 'done';
                item.result = r;
                item.preview = '/storage/thumbs/' + r.items[0].image_path.split('/').slice(-2).join('/');
                renderItem(item);
            } catch (e) {
                item.status = 'error';
                item.error = e.message;
                lastError = e.message;
                renderItem(item);
            }
            // 间隔
            if (i < pendingItems.length - 1) {
                const wait = Math.max(0, (roundInterval || 0)) * 1000;
                if (wait > 0) await new Promise(r => setTimeout(r, wait));
            }
        }
        if (_aborted) break;
        const failed = _queue.filter(it => it.status === 'error').length;
        if (failed === 0) break;     // 全成功了
        if (round >= _maxRounds) break;
        // 退避 30s
        toast(`${failed} 张失败，30s 后自动重试 (${round}/${_maxRounds - 1})`, { type: 'warning' });
        await new Promise(r => setTimeout(r, 30000));
        round++;
    }
    _running = false;
    const ok = _queue.filter(it => it.status === 'done').length;
    const fail = _queue.filter(it => it.status === 'error').length;
    reloadGallery();
    if (_aborted) {
        toast(`队列已取消：${ok} 成功，${fail} 失败`, { type: 'warning' });
    } else if (fail > 0) {
        toast(`队列完成：${ok}/${_queue.length} 成功，${fail} 失败`, { type: 'error', duration: 8000 });
    } else {
        toast(`队列完成：${ok} 张全成功`, { type: 'success' });
    }
    setTimeout(() => { if (!_running) showTray(false); }, 6000);
}

export function abortQueue() {
    if (!_running) return;
    _aborted = true;
    toast('正在取消队列...', { type: 'info' });
}

function renderItem(item) {
    const el = _els.list.querySelector(`[data-qid="${item.id}"]`);
    if (!el) return;
    el.classList.toggle('done', item.status === 'done');
    el.classList.toggle('error', item.status === 'error');
    const bar = el.querySelector('.progress-bar');
    if (bar) bar.style.width = (item.progress * 100) + '%';
    const preview = el.querySelector('.preview');
    if (preview && item.preview) preview.src = item.preview;
}

function showTray(show) {
    if (!_els.tray) return;
    _els.tray.classList.toggle('hidden', !show);
}

function clearTray() {
    _queue = [];
    _els.list.innerHTML = '';
    showTray(false);
}

function addQueueItem(item) {
    _queue.push(item);
    const el = document.createElement('div');
    el.className = 'queue-item';
    el.dataset.qid = item.id;
    el.innerHTML = `
        <img class="preview" alt="" style="background:#000">
        <div class="info" style="flex:1">
            <div style="font-size:11px;line-height:1.3;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escapeHtml(item.label)}</div>
            <div class="progress"><div class="progress-bar" style="width:0%"></div></div>
        </div>
    `;
    _els.list.appendChild(el);
}

function escapeHtml(s) { return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

export function enqueueBatch(count, intervalSec, randomSeed = true, autoRetry = false) {
    _queue = [];
    _els.list.innerHTML = '';
    _randomSeed = randomSeed;
    _autoRetry = autoRetry;
    _maxRounds = autoRetry ? 3 : 1;
    for (let i = 0; i < count; i++) {
        const id = 'q_' + Date.now().toString(36) + i;
        const item = { id, label: `任务 ${i + 1} / ${count}`, progress: 0, status: 'pending', interval: intervalSec, round: 1 };
        addQueueItem(item);
    }
    if (autoRetry) toast(`队列开始：${count} 张（自动重试模式）`, { type: 'info', duration: 4000 });
    runQueue();
}

/**
 * 工程队列：每组一个姿势预设 + 张数，内部展开为 N 个 queue items，
 * 共享主提示词/角色，但每张用各自的 pose_prompt（覆盖 state.posePrompt）
 *
 * rows: [{ label, posePrompt, count, interval }, ...]
 * opts: { randomSeed, autoRetry }
 */
export function enqueueProject(rows, opts = {}) {
    _queue = [];
    _els.list.innerHTML = '';
    _randomSeed = opts.randomSeed !== false;
    _autoRetry = !!opts.autoRetry;
    _maxRounds = _autoRetry ? 3 : 1;
    let total = 0;
    const groups = [];
    for (const row of rows) {
        const n = Math.max(1, parseInt(row.count) || 1);
        groups.push({ label: row.label, n });
        for (let i = 0; i < n; i++) {
            const id = 'q_' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6);
            const item = {
                id,
                label: `${row.label} · ${i + 1}/${n}`,
                progress: 0,
                status: 'pending',
                interval: row.interval ?? 5,
                round: 1,
                overrides: { pose_prompt: row.posePrompt || '' },
            };
            addQueueItem(item);
            total++;
        }
    }
    const summary = groups.map(g => `${g.label}×${g.n}`).join(' + ');
    toast(`工程队列：${summary} = ${total} 张`, { type: 'info', duration: 5000 });
    runQueue();
}

export function initQueue() {
    _els = {
        tray:   document.getElementById('queueTray'),
        list:   document.getElementById('queueList'),
    };
    if (!_els.tray) return;
    document.getElementById('queueClearBtn')?.addEventListener('click', clearTray);
    document.getElementById('queueAbortBtn')?.addEventListener('click', abortQueue);
}
