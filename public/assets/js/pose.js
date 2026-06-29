/**
 * NAI Studio - 姿势提示词（简单 textarea + 预设）
 *
 * 跟主提示词/负面/角色一样的输入框，额外多：
 *  - 保存当前为预设（自定义名称）
 *  - 预设下拉菜单（收藏的排前面，点击载入）
 *  - 管理按钮 → 打开 设置 → 预设 tab（统一管理）
 *
 * 姿势/动作词库改放在「标签库」modal 左侧分类里，不在这里占空间。
 *
 * 最终生成时：主提示词 + 角色提示词 + 姿势提示词 拼接
 */

import { getState, setState } from './state.js?v=104';
import { api } from './api.js?v=104';
import { toast } from './toast.js?v=104';
import { saveLocal } from './storage.js?v=104';
import { openPresetSave } from './preset-modal.js?v=104';
import { openPresetManager } from './settings.js?v=104';

let _els = {};

function escapeHtml(s) {
    return (s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function renderPresets() {
    const list = _els.presetList;
    const select = _els.presetSelect;
    const presets = (getState().posePresets || []).slice();
    presets.sort((a, b) => (b.is_favorite ? 1 : 0) - (a.is_favorite ? 1 : 0));

    // 填充下拉
    if (select) {
        const current = select.value;
        select.innerHTML = '<option value="">— 姿势预设 —</option>';
        for (const p of presets) {
            const opt = document.createElement('option');
            opt.value = String(p.id);
            opt.textContent = (p.is_favorite ? '★ ' : '') + p.name;
            select.appendChild(opt);
        }
        if (current && presets.some(p => String(p.id) === current)) select.value = current;
    }

    // 详细列表（管理弹窗用，目前默认隐藏）
    if (!list) return;
    if (presets.length === 0) {
        list.innerHTML = '<p style="font-size:11px;color:var(--text-muted);padding:12px;text-align:center">还没有姿势预设</p>';
        return;
    }
    list.innerHTML = '';
    for (const p of presets) {
        const row = document.createElement('div');
        row.className = 'pose-preset-row';
        row.style.cssText = 'display:flex;align-items:center;gap:6px;padding:8px 10px;background:var(--bg);border:1px solid var(--border);border-radius:var(--r);margin-bottom:4px;cursor:pointer;transition:all var(--t-fast) var(--ease)';
        row.innerHTML = `
            <span style="flex:1;min-width:0;font-size:12px;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${escapeHtml(p.prompt)}">
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
                await api.updatePosePreset(p.id, { is_favorite: p.is_favorite ? 0 : 1 });
                await loadPresets();
            } catch (err) { toast(err.message, { type: 'error' }); }
        });
        row.querySelector('[data-action="del"]')?.addEventListener('click', async (e) => {
            e.stopPropagation();
            if (!confirm('删除该姿势预设？')) return;
            try {
                await api.deletePosePreset(p.id);
                await loadPresets();
                toast('已删除', { type: 'success' });
            } catch (err) { toast(err.message, { type: 'error' }); }
        });
        list.appendChild(row);
    }
}

function loadPreset(p) {
    if (!_els.input) return;
    _els.input.value = p.prompt;
    _els.input.dispatchEvent(new Event('input', { bubbles: true }));
    toast(`已载入：${p.name}`, { type: 'success' });
}

async function loadPresets() {
    try {
        const r = await api.listPosePresets({ per_page: 100 });
        setState({ posePresets: r.rows || [] });
        saveLocal();
        renderPresets();
    } catch (e) { /* ignore */ }
}

function saveCurrent() {
    const prompt = (_els.input?.value || '').trim();
    if (!prompt) { toast('姿势提示词为空', { type: 'warning' }); return; }
    const defaultName = prompt.split(',')[0].trim().slice(0, 30) || '未命名';
    openPresetSave({
        title: '保存姿势预设',
        hint: `当前内容：${prompt.length > 50 ? prompt.slice(0, 50) + '…' : prompt}`,
        defaultName,
        defaultCategory: 'custom',
        showCategory: true,
        onSave: async ({ name, category, isFavorite }) => {
            try {
                await api.createPosePreset({
                    name,
                    prompt,
                    category,
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

export function initPose() {
    _els = {
        input:         document.getElementById('poseInput'),
        saveBtn:       document.getElementById('poseSavePresetBtn'),
        presetSelect:  document.getElementById('posePresetSelect'),
        presetList:    document.getElementById('posePresetList'),
        manageBtn:     document.getElementById('posePresetManageBtn'),
    };
    if (!_els.input) return;

    // Initial sync
    _els.input.value = getState().posePrompt || '';
    _els.input.addEventListener('input', () => {
        setState({ posePrompt: _els.input.value });
        saveLocal();
    });

    _els.saveBtn?.addEventListener('click', saveCurrent);
    _els.presetSelect?.addEventListener('change', (e) => {
        const id = parseInt(e.target.value);
        if (!id) return;
        const p = (getState().posePresets || []).find(x => x.id === id);
        if (p) loadPreset(p);
        e.target.value = '';
    });
    _els.manageBtn?.addEventListener('click', () => {
        openPresetManager();
    });

    loadPresets();
}