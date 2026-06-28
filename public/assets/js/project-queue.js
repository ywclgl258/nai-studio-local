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
let _rows = [];   // [{ presetId, label, posePrompt, count, interval }]

function escapeHtml(s) {
    return (s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function open() {
    if (_els.modal) _els.modal.classList.remove('hidden');
    // 打开时拉一次最新预设
    loadPresetsAndRender();
}

function close() {
    if (_els.modal) _els.modal.classList.add('hidden');
}

function totalCount() {
    return _rows.reduce((s, r) => s + (parseInt(r.count) || 0), 0);
}

function renderRows() {
    const wrap = _els.rows;
    if (!wrap) return;
    if (_rows.length === 0) {
        wrap.innerHTML = '<p style="font-size:11px;color:var(--text-muted);padding:12px;text-align:center">点下方「+ 添加姿势预设」开始</p>';
    } else {
        wrap.innerHTML = '';
        for (let i = 0; i < _rows.length; i++) {
            const r = _rows[i];
            const row = document.createElement('div');
            row.className = 'project-row';
            row.style.cssText = 'display:grid;grid-template-columns:1fr 90px 60px 24px;gap:6px;align-items:center;padding:6px;background:var(--bg);border:1px solid var(--border);border-radius:var(--r);margin-bottom:4px';
            row.innerHTML = `
                <select class="project-row-preset" data-i="${i}" style="min-width:0"></select>
                <input class="project-row-count" type="number" min="1" max="50" value="${r.count}" data-i="${i}" title="张数">
                <input class="project-row-interval" type="number" min="0" max="60" value="${r.interval ?? 5}" data-i="${i}" title="间隔秒数">
                <button class="icon-button small ghost project-row-del" data-i="${i}" title="删除">
                    <svg viewBox="0 0 24 24" width="12" height="12"><path d="M6 7h12m-10 0 .7 13h6.6L16 7M10 7V5h4v2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                </button>
            `;
            // 填 preset 选项
            const sel = row.querySelector('.project-row-preset');
            for (const p of _allPresets) {
                const opt = document.createElement('option');
                opt.value = String(p.id);
                opt.textContent = (p.is_favorite ? '★ ' : '') + p.name + (p.prompt ? ` (${p.prompt.length > 30 ? p.prompt.slice(0, 30) + '…' : p.prompt})` : '');
                if (p.id === r.presetId) opt.selected = true;
                sel.appendChild(opt);
            }
            sel.addEventListener('change', (e) => {
                const idx = parseInt(e.target.dataset.i);
                const preset = _allPresets.find(p => p.id === parseInt(e.target.value));
                if (preset) {
                    _rows[idx].presetId = preset.id;
                    _rows[idx].label = preset.name;
                    _rows[idx].posePrompt = preset.prompt || '';
                }
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

let _allPresets = [];

async function loadPresetsAndRender() {
    try {
        const r = await api.listPosePresets({ per_page: 200 });
        _allPresets = (r.rows || []).slice().sort((a, b) => (b.is_favorite ? 1 : 0) - (a.is_favorite ? 1 : 0));
        renderRows();
    } catch (e) {
        toast('加载姿势预设失败: ' + e.message, { type: 'error' });
    }
}

function addRow(preset) {
    const row = {
        presetId: preset ? preset.id : null,
        label: preset ? preset.name : '',
        posePrompt: preset ? (preset.prompt || '') : '',
        count: 4,
        interval: 5,
    };
    _rows.push(row);
    renderRows();
}

function startProject() {
    if (_rows.length === 0) {
        toast('先添加至少一个姿势预设', { type: 'warning' });
        return;
    }
    // 同步每个 row 的 preset prompt（防止用户改了 select 但用旧数据）
    const validRows = [];
    for (const r of _rows) {
        if (!r.presetId) continue;
        const p = _allPresets.find(x => x.id === r.presetId);
        if (!p) continue;
        if (r.count <= 0) continue;
        validRows.push({
            label: p.name,
            posePrompt: p.prompt || '',
            count: r.count,
            interval: r.interval ?? 5,
        });
    }
    if (validRows.length === 0) {
        toast('没有有效的预设行', { type: 'warning' });
        return;
    }
    const total = validRows.reduce((s, r) => s + r.count, 0);
    const summary = validRows.map(r => `${r.label}×${r.count}`).join(' + ');
    if (!confirm(`开始工程队列？\n\n${summary}\n合计 ${total} 张\n\n会按现有主提示词 + 角色 + 各姿势依次跑（每张随机 seed）。可中途停止。`)) return;
    enqueueProject(validRows, { randomSeed: true, autoRetry: false });
    close();
}

export function initProjectQueue() {
    _els = {
        modal:        document.getElementById('projectQueueModal'),
        rows:         document.getElementById('projectQueueRows'),
        total:        document.getElementById('projectQueueTotal'),
        startBtn:     document.getElementById('projectQueueStartBtn'),
        addBtn:       document.getElementById('projectQueueAddBtn'),
        cancelBtn:    document.getElementById('projectQueueCancelBtn'),
        openBtn:      document.getElementById('openProjectQueueBtn'),
    };

    _els.openBtn?.addEventListener('click', open);
    _els.cancelBtn?.addEventListener('click', close);
    _els.addBtn?.addEventListener('click', () => {
        if (_allPresets.length === 0) {
            toast('请先在姿势 tab 里保存至少一个姿势预设', { type: 'warning' });
            return;
        }
        // 默认挑第一个预设
        addRow(_allPresets[0]);
    });
    _els.startBtn?.addEventListener('click', startProject);

    _els.modal?.addEventListener('click', (e) => {
        if (e.target === _els.modal) close();
    });
}

export function openProjectQueue() { open(); }