/**
 * NAI Studio - 工程队列模式
 *
 * 指定一套提示词+角色，搭配多种姿势预设 × 不同张数，按队列跑。
 * 例：微笑×4 + 大笑×8 = 12 张，全部共享主提示词/角色，每张用对应姿势。
 *
 * 复用 runQueue：把 rows 展开为 N 个 queue items，每 item 自带 overrides.pose_prompt
 */

import { getState } from './state.js';
import { api } from './api.js';
import { toast } from './toast.js';
import { enqueueProject } from './queue.js';

let _els = {};
let _rows = [];          // [{ label, posePrompt, count, interval, presetId? }]
let _allPresets = [];    // 缓存所有姿势预设
let _poseDictCache = null; // 缓存姿势词库（中文→英文）

function escapeHtml(s) {
    return (s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function open() {
    if (_els.modal) _els.modal.classList.remove('hidden');
    loadAll();
}

function close() {
    if (_els.modal) _els.modal.classList.add('hidden');
}

function totalCount() {
    return _rows.reduce((s, r) => s + (parseInt(r.count) || 0), 0);
}

async function loadAll() {
    await Promise.all([loadPresets(), loadPoseDict()]);
    renderRows();
}

async function loadPresets() {
    try {
        const r = await api.listPosePresets({ per_page: 200 });
        _allPresets = (r.rows || []).slice().sort((a, b) => (b.is_favorite ? 1 : 0) - (a.is_favorite ? 1 : 0));
    } catch (e) { /* ignore */ }
}

async function loadPoseDict() {
    if (_poseDictCache) return;
    try {
        const r = await fetch('api/pose-dict.php', { credentials: 'same-origin' });
        const j = await r.json();
        if (j.ok) _poseDictCache = j.categories || {};
    } catch (e) { /* ignore */ }
}

/**
 * 把姿势名（中文/英文）解析成 { label, posePrompt }
 * - 英文直接当 prompt
 * - 中文查 pose-dict 翻译成英文（取第一个匹配）
 * - 找不到翻译就用原文（用户可能输的是自定义词）
 */
function resolvePoseName(name) {
    const raw = (name || '').trim();
    if (!raw) return { label: '', posePrompt: '' };
    // 已经有英文逗号（多 tag）：直接用
    if (/[a-z_]{2,}.*,/i.test(raw) || raw.includes(',')) {
        return { label: raw.length > 20 ? raw.slice(0, 20) + '…' : raw, posePrompt: raw };
    }
    // 查 pose-dict
    if (_poseDictCache) {
        const q = raw.toLowerCase();
        for (const [cat, items] of Object.entries(_poseDictCache)) {
            const hit = items.find(it => it.cn === raw || it.cn.toLowerCase() === q || it.en === raw || it.en.toLowerCase() === q);
            if (hit) return { label: raw, posePrompt: hit.en };
        }
    }
    // fallback：原文
    return { label: raw, posePrompt: raw };
}

function renderRows() {
    const wrap = _els.rows;
    if (!wrap) return;
    if (_rows.length === 0) {
        wrap.innerHTML = `
            <div style="padding:20px 12px;text-align:center;color:var(--text-muted);font-size:12px;line-height:1.6">
                <div style="font-size:24px;margin-bottom:6px">🎨</div>
                点下方按钮加行：
                <div style="margin-top:4px"><b>空行</b> · <b>常用姿势</b> · <b>从预设选</b></div>
            </div>`;
    } else {
        wrap.innerHTML = '';
        for (let i = 0; i < _rows.length; i++) {
            const r = _rows[i];
            const row = document.createElement('div');
            row.className = 'project-row';
            row.style.cssText = 'display:grid;grid-template-columns:1fr 70px 50px 24px;gap:6px;align-items:center;padding:6px;background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--r);margin-bottom:4px';
            row.innerHTML = `
                <input class="project-row-name" type="text" value="${escapeHtml(r.label || '')}" data-i="${i}" placeholder="姿势名（中文/英文，如：微笑 / smile）" title="输入中文自动翻译成英文">
                <input class="project-row-count" type="number" min="1" max="50" value="${r.count}" data-i="${i}" title="张数">
                <input class="project-row-interval" type="number" min="0" max="60" value="${r.interval ?? 0}" data-i="${i}" title="间隔秒">
                <button class="icon-button small ghost project-row-del" data-i="${i}" title="删除">
                    <svg viewBox="0 0 24 24" width="12" height="12"><path d="M6 7h12m-10 0 .7 13h6.6L16 7M10 7V5h4v2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                </button>
            `;
            const nameInput = row.querySelector('.project-row-name');
            nameInput.addEventListener('input', (e) => {
                const idx = parseInt(e.target.dataset.i);
                const resolved = resolvePoseName(e.target.value);
                _rows[idx].label = resolved.label;
                _rows[idx].posePrompt = resolved.posePrompt;
                _rows[idx].presetId = null;
            });
            row.querySelector('.project-row-count').addEventListener('change', (e) => {
                const idx = parseInt(e.target.dataset.i);
                _rows[idx].count = Math.max(1, Math.min(50, parseInt(e.target.value) || 1));
                e.target.value = _rows[idx].count;
                updateTotal();
            });
            row.querySelector('.project-row-interval').addEventListener('change', (e) => {
                const idx = parseInt(e.target.dataset.i);
                _rows[idx].interval = Math.max(0, Math.min(60, parseInt(e.target.value) || 0));
                e.target.value = _rows[idx].interval;
            });
            row.querySelector('.project-row-del').addEventListener('click', (e) => {
                const idx = parseInt(e.currentTarget.dataset.i);
                _rows.splice(idx, 1);
                renderRows();
                updateTotal();
            });
            wrap.appendChild(row);
        }
    }
    updateTotal();
}

function updateTotal() {
    if (_els.total) _els.total.textContent = String(totalCount());
    if (_els.startBtn) _els.startBtn.disabled = totalCount() === 0;
}

function addRow(pose) {
    // pose: { label, posePrompt, count?, interval?, presetId? }
    const row = {
        label: pose.label || '',
        posePrompt: pose.posePrompt || '',
        count: pose.count ?? 4,
        interval: pose.interval ?? 0,
        presetId: pose.presetId || null,
    };
    _rows.push(row);
    renderRows();
}

function addBlankRow() { addRow({ label: '', posePrompt: '', count: 4, interval: 0 }); }

/** 一键加常用 6 件套：站/坐/蹲/躺/看/笑，每行 4 张 */
function addQuickSet() {
    const quick = ['站立', '坐', '蹲', '躺着', '看观众', '微笑'];
    for (const cn of quick) {
        const resolved = resolvePoseName(cn);
        addRow({ ...resolved, count: 4, interval: 0 });
    }
    toast(`已加常用 6 件套（每行 4 张，合计 24 张）`, { type: 'success', duration: 3000 });
}

/** 从已有姿势预设选：弹一个轻量 picker */
function addFromPreset() {
    if (_allPresets.length === 0) {
        toast('还没有姿势预设，先去姿势 tab 保存一个', { type: 'warning' });
        return;
    }
    // 移除已有 picker
    document.getElementById('projectPresetPicker')?.remove();
    const picker = document.createElement('div');
    picker.id = 'projectPresetPicker';
    picker.className = 'project-preset-picker';
    picker.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:1000;display:flex;align-items:center;justify-content:center';
    const inner = document.createElement('div');
    inner.style.cssText = 'background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--r-lg);padding:16px;max-width:480px;max-height:70vh;overflow:auto;display:flex;flex-direction:column;gap:10px';
    inner.innerHTML = `
        <div style="display:flex;align-items:center;gap:8px">
            <h3 style="margin:0;font-size:14px;flex:1">从姿势预设选（点击加入）</h3>
            <button class="icon-button small ghost" id="closePicker">×</button>
        </div>
        <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:6px" id="pickerList"></div>
    `;
    picker.appendChild(inner);
    document.body.appendChild(picker);

    const list = inner.querySelector('#pickerList');
    for (const p of _allPresets) {
        const btn = document.createElement('button');
        btn.className = 'picker-preset-btn';
        btn.style.cssText = 'padding:8px 10px;background:var(--bg);border:1px solid var(--border);border-radius:var(--r);text-align:left;cursor:pointer;font-size:12px;transition:all 0.15s';
        btn.innerHTML = `
            <div style="font-weight:600;color:var(--text)">${(p.is_favorite ? '★ ' : '') + escapeHtml(p.name)}</div>
            <div style="font-size:10px;color:var(--text-muted);margin-top:2px;font-family:var(--font-mono);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escapeHtml((p.prompt || '').slice(0, 60))}</div>
        `;
        btn.addEventListener('mouseenter', () => { btn.style.borderColor = 'var(--accent)'; btn.style.background = 'var(--bg-elevated-2)'; });
        btn.addEventListener('mouseleave', () => { btn.style.borderColor = 'var(--border)'; btn.style.background = 'var(--bg)'; });
        btn.addEventListener('click', () => {
            addRow({ label: p.name, posePrompt: p.prompt || '', presetId: p.id });
            picker.remove();
        });
        list.appendChild(btn);
    }
    inner.querySelector('#closePicker').addEventListener('click', () => picker.remove());
    picker.addEventListener('click', (e) => { if (e.target === picker) picker.remove(); });
}

function startProject() {
    if (_rows.length === 0) {
        toast('先添加至少一行', { type: 'warning' });
        return;
    }
    const validRows = [];
    for (const r of _rows) {
        const posePrompt = (r.posePrompt || '').trim();
        if (!posePrompt) continue;
        if (r.count <= 0) continue;
        validRows.push({
            label: (r.label || posePrompt).slice(0, 40),
            posePrompt,
            count: r.count,
            interval: r.interval ?? 0,
        });
    }
    if (validRows.length === 0) {
        toast('没有有效的行（姿势名不能空）', { type: 'warning' });
        return;
    }
    const total = validRows.reduce((s, r) => s + r.count, 0);
    const summary = validRows.map(r => `${r.label}×${r.count}`).join(' + ');
    if (!confirm(`开始工程队列？\n\n${summary}\n合计 ${total} 张\n\n按当前主提示词+角色跑，每张随机 seed。\n小图模式可关浏览器后台跑（CLI 命令见 设置）。`)) return;
    enqueueProject(validRows, { randomSeed: true, autoRetry: false });
    close();
}

export function initProjectQueue() {
    _els = {
        modal:        document.getElementById('projectQueueModal'),
        rows:         document.getElementById('projectQueueRows'),
        total:        document.getElementById('projectQueueTotal'),
        startBtn:     document.getElementById('projectQueueStartBtn'),
        cancelBtn:    document.getElementById('projectQueueCancelBtn'),
        openBtn:      document.getElementById('openProjectQueueBtn'),
        addBtn:       document.getElementById('projectQueueAddBtn'),
        quickBtn:     document.getElementById('projectQueueQuickBtn'),
        presetBtn:    document.getElementById('projectQueuePresetBtn'),
    };

    _els.openBtn?.addEventListener('click', open);
    _els.cancelBtn?.addEventListener('click', close);
    _els.addBtn?.addEventListener('click', addBlankRow);
    _els.quickBtn?.addEventListener('click', addQuickSet);
    _els.presetBtn?.addEventListener('click', addFromPreset);
    _els.startBtn?.addEventListener('click', startProject);

    _els.modal?.addEventListener('click', (e) => {
        if (e.target === _els.modal) close();
    });
}

export function openProjectQueue() { open(); }