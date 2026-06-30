/**
 * NAI Studio - 角色提示词（多框设计，最多 3 个）
 *
 * 行为：
 *  - 始终保持 1-3 个 textarea
 *  - 每个 textarea 独立保存
 *  - 预设库载入会替换所有角色提示词
 *  - 兼容旧 characterPrompt string（首次启动自动迁移）
 *
 * 最终生成：主提示词 + 角色1 + 角色2 + 角色3 + 姿势 拼接
 */

import { getState, setState } from './state.js';
import { api } from './api.js';
import { toast } from './toast.js';
import { saveLocal } from './storage.js';
import { openPresetSave } from './preset-modal.js';
import { createPresetCombobox } from './preset-combobox.js';

const MAX = 3;
let _els = {};
let _combobox = null;

function escapeHtml(s) {
    return (s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function renderPresets() {
    const list = _els.presetList;
    const presets = (getState().characterPresets || []).slice();
    // 收藏排前面
    presets.sort((a, b) => (b.is_favorite ? 1 : 0) - (a.is_favorite ? 1 : 0));

    // 自定义 combobox 替换原生 <select>
    if (_combobox) {
        _combobox.refresh();
    } else if (_els.presetSelect && !_combobox) {
        _combobox = window.createPresetCombobox({
            getItems: () => (getState().characterPresets || []).slice().sort((a, b) => (b.is_favorite ? 1 : 0) - (a.is_favorite ? 1 : 0)),
            onSelect: (p) => loadPreset(p),
            onOpenManage: () => togglePresetPanel(),
            onToggleFav: async (p) => {
                try {
                    await api.updateCharacterPreset(p.id, { is_favorite: p.is_favorite ? 0 : 1 });
                    await loadPresets();
                } catch (err) { toast(err.message, { type: 'error' }); }
            },
            onDelete: async (p) => {
                try {
                    await api.deleteCharacterPreset(p.id);
                    await loadPresets();
                    toast('已删除', { type: 'success' });
                } catch (err) { toast(err.message, { type: 'error' }); }
            },
            placeholder: '— 角色预设 —',
        });
        _els.presetSelect.appendChild(_combobox.el);
    }

    // 详细管理列表（toggle 出来的弹窗）
    if (!list) return;
    if (presets.length === 0) {
        list.innerHTML = '<p style="font-size:11px;color:var(--text-muted);padding:12px;text-align:center">还没有角色预设</p>';
        return;
    }
    list.innerHTML = '';
    for (const p of presets) {
        const row = document.createElement('div');
        row.className = 'char-preset-row';
        row.style.cssText = 'display:flex;align-items:center;gap:6px;padding:8px 10px;background:var(--bg);border:1px solid var(--border);border-radius:var(--r);margin-bottom:4px;cursor:pointer;transition:all var(--t-fast) var(--ease)';
        const promptSummary = p.prompts
            ? (Array.isArray(p.prompts) ? p.prompts.filter(Boolean).join(' | ') : p.prompts)
            : (p.prompt || '');
        row.innerHTML = `
            <span style="flex:1;min-width:0;font-size:12px;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${escapeHtml(promptSummary)}">
                ${escapeHtml(p.name)}
                ${p.is_favorite ? '<span style="color:#fbbf24;margin-left:4px">★</span>' : ''}
            </span>
            <button class="icon-button small ghost" data-action="fav" title="收藏" style="color:${p.is_favorite ? '#fbbf24' : 'var(--text-muted)'};width:24px;height:24px">${p.is_favorite ? '★' : '☆'}</button>
            <button class="icon-button small ghost" data-action="del" title="删除" style="color:var(--text-muted);width:24px;height:24px">
                <svg viewBox="0 0 24 24" width="12" height="12"><path d="M6 7h12m-10 0 .7 13h6.6L16 7M10 7V5h4v2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            </button>
        `;
        row.addEventListener('click', (e) => {
            if (e.target.closest('[data-action]')) return;
            loadPreset(p);
        });
        row.querySelector('[data-action="fav"]')?.addEventListener('click', async (e) => {
            e.stopPropagation();
            try {
                await api.updateCharacterPreset(p.id, { is_favorite: p.is_favorite ? 0 : 1 });
                await loadPresets();
            } catch (err) { toast(err.message, { type: 'error' }); }
        });
        row.querySelector('[data-action="del"]')?.addEventListener('click', async (e) => {
            e.stopPropagation();
            if (!confirm('删除该角色预设？')) return;
            try {
                await api.deleteCharacterPreset(p.id);
                await loadPresets();
                toast('已删除', { type: 'success' });
            } catch (err) { toast(err.message, { type: 'error' }); }
        });
        list.appendChild(row);
    }
}

function loadPreset(p) {
    const prompts = p.prompts
        ? (Array.isArray(p.prompts) ? p.prompts : [p.prompts])
        : (p.prompt ? [p.prompt] : []);
    setPrompts(prompts);
    render();
    toast(`已载入：${p.name}`, { type: 'success' });
}

async function loadPresets() {
    try {
        const r = await api.listCharacterPresets({ per_page: 100 });
        setState({ characterPresets: r.rows || [] });
        saveLocal();
        renderPresets();
    } catch (e) { /* ignore */ }
}

/** 获取当前生效的角色提示词数组（过滤空，限制 MAX） */
function getPrompts() {
    const arr = getState().characterPrompts || [''];
    if (!Array.isArray(arr) || arr.length === 0) return [''];
    return arr.slice(0, MAX);
}

function setPrompts(arr) {
    const cleaned = (arr || []).filter(v => v != null).map(v => String(v));
    setState({ characterPrompts: cleaned.length > 0 ? cleaned : [''] });
    saveLocal();
}

function render() {
    const list = _els.list;
    if (!list) return;
    const prompts = getPrompts();
    list.innerHTML = '';
    prompts.forEach((p, i) => {
        const row = document.createElement('div');
        row.className = 'character-prompt-row';
        row.innerHTML = `
            <div class="cp-index">${i + 1}</div>
            <textarea data-idx="${i}" spellcheck="false"
                placeholder="例：1girl, long pink hair, blue eyes, sailor uniform, ..."
                aria-label="角色 ${i + 1} 提示词"></textarea>
            <button class="cp-remove" data-action="remove" data-idx="${i}" title="删除这个角色" aria-label="删除角色 ${i + 1}">×</button>
        `;
        list.appendChild(row);
    });
    // 同步值
    list.querySelectorAll('textarea').forEach(ta => {
        const idx = parseInt(ta.dataset.idx);
        ta.value = prompts[idx] || '';
        ta.addEventListener('input', () => {
            const arr = getPrompts().slice();
            arr[idx] = ta.value;
            setState({ characterPrompts: arr });
            saveLocal();
        });
    });
    list.querySelectorAll('[data-action="remove"]').forEach(btn => {
        btn.addEventListener('click', () => {
            const idx = parseInt(btn.dataset.idx);
            const arr = getPrompts().filter((_, i) => i !== idx);
            setPrompts(arr);
            render();
        });
    });
    // 更新计数 + 添加按钮
    if (_els.count) _els.count.textContent = String(prompts.length);
    if (_els.addBtn) _els.addBtn.disabled = prompts.length >= MAX;
    if (_els.addBtn) _els.addBtn.style.opacity = prompts.length >= MAX ? '0.4' : '1';
    if (_els.addBtn) _els.addBtn.style.pointerEvents = prompts.length >= MAX ? 'none' : 'auto';
}

function addPrompt() {
    const arr = getPrompts();
    if (arr.length >= MAX) {
        toast(`最多 ${MAX} 个角色`, { type: 'warning' });
        return;
    }
    arr.push('');
    setPrompts(arr);
    render();
    // 自动 focus 新增的
    setTimeout(() => {
        const tas = _els.list?.querySelectorAll('textarea');
        if (tas && tas.length) tas[tas.length - 1]?.focus();
    }, 50);
}

function saveCurrent() {
    const prompts = getPrompts().map(s => (s || '').trim()).filter(Boolean);
    if (prompts.length === 0) { toast('角色提示词为空', { type: 'warning' }); return; }
    const first = prompts[0];
    const defaultName = first.split(',')[0].trim().slice(0, 30) || '未命名';
    const hint = prompts.length === 1
        ? `当前内容：${first.length > 50 ? first.slice(0, 50) + '…' : first}`
        : `共 ${prompts.length} 个角色 · 总长 ${prompts.join(' | ').length} 字`;
    openPresetSave({
        title: '保存角色预设',
        hint,
        defaultName,
        showCategory: false,
        onSave: async ({ name, isFavorite }) => {
            try {
                await api.createCharacterPreset({
                    name,
                    prompts,                                    // 新字段
                    prompt: prompts.join(' | '),                // 兼容老字段
                    is_favorite: isFavorite ? 1 : 0,
                });
                await loadPresets();
                toast('已保存', { type: 'success' });
            } catch (e) {
                toast('保存失败: ' + e.message, { type: 'error' });
            }
        },
    });
}

function togglePresetPanel() {
    if (!_els.panel) return;
    const willShow = _els.panel.classList.contains('hidden');
    _els.panel.classList.toggle('hidden', !willShow);
    if (willShow) loadPresets();
}

/** 兼容老 data：旧 characterPrompt 字符串 → 数组 */
function migrateLegacyState() {
    const legacy = getState().characterPrompt;
    const cur = getState().characterPrompts;
    if (typeof legacy === 'string' && legacy && (!Array.isArray(cur) || cur.length === 0 || (cur.length === 1 && cur[0] === ''))) {
        setState({ characterPrompts: [legacy], characterPrompt: undefined });
        saveLocal();
    } else if (!Array.isArray(cur) || cur.length === 0) {
        setState({ characterPrompts: [''] });
        saveLocal();
    }
}

export function initCharacters() {
    _els = {
        list:         document.getElementById('characterPromptsList'),
        addBtn:       document.getElementById('characterAddBtn'),
        count:        document.getElementById('characterCount'),
        saveBtn:      document.getElementById('characterSavePresetBtn'),
        loadBtn:      document.getElementById('characterLoadPresetBtn'),
        manageBtn:    document.getElementById('characterPresetManageBtn'),
        presetSelect: document.getElementById('characterPresetSelect'),
        panel:        document.getElementById('characterPresetPanel'),
        presetList:   document.getElementById('characterPresetList'),
    };

    if (!_els.list) return;

    migrateLegacyState();
    render();

    _els.addBtn?.addEventListener('click', addPrompt);
    _els.saveBtn?.addEventListener('click', saveCurrent);
    _els.loadBtn?.addEventListener('click', togglePresetPanel);
    _els.manageBtn?.addEventListener('click', togglePresetPanel);
    // presetSelect 现在是容器，combobox 自己处理 onSelect

    loadPresets();
}