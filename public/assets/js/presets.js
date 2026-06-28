/**
 * NAI Studio - Prompt presets + snippets (saved prompts)
 */

import { api } from './api.js';
import { getState, setState, subscribe } from './state.js';
import { toast } from './toast.js';
import { saveLocal } from './storage.js';

let _els = {};

async function loadPresets() {
    try {
        const r = await api.listPrompts({ per_page: 100 });
        setState({ presets: r.rows || [] });
        saveLocal();
        renderPresets();
    } catch (e) {
        // ignore
    }
}

function renderPresets() {
    const presets = getState().presets || [];
    // 1) 填充下拉选单（永远不超长）
    if (_els.presetSelect) {
        const current = _els.presetSelect.value;
        _els.presetSelect.innerHTML = '<option value="">— 选择预设载入 —</option>';
        for (const p of presets) {
            const opt = document.createElement('option');
            opt.value = String(p.id);
            opt.textContent = p.title + (p.is_favorite ? ' ★' : '');
            _els.presetSelect.appendChild(opt);
        }
        if (current && presets.some(p => String(p.id) === current)) _els.presetSelect.value = current;
    }
    // 2) 详细管理列表（折叠在 <details> 里，默认不展开）
    if (!_els.presetsList) return;
    _els.presetsList.innerHTML = '';
    if (presets.length === 0) {
        _els.presetsList.innerHTML = '<p style="color:var(--text-muted);font-size:12px;padding:8px 0">还没有保存预设</p>';
        return;
    }
    for (const p of presets) {
        const div = document.createElement('div');
        div.className = 'preset-item';
        div.style.cssText = 'display:flex;align-items:center;gap:8px;padding:8px;background:var(--bg);border-radius:var(--r-sm);margin-bottom:4px;cursor:pointer;';
        div.innerHTML = `
            <span style="flex:1;font-size:12px;color:var(--text)"></span>
            <button class="icon-button small ghost" data-action="apply" title="应用">
                <svg viewBox="0 0 24 24"><path d="m5 12 5 5 9-9"/></svg>
            </button>
            <button class="icon-button small ghost" data-action="delete" title="删除">
                <svg viewBox="0 0 24 24"><path d="M6 7h12m-10 0 .7 13h6.6L16 7M10 7V5h4v2"/></svg>
            </button>
        `;
        const name = div.querySelector('span');
        name.textContent = p.title + (p.is_favorite ? ' ★' : '');
        div.addEventListener('click', () => applyPreset(p));
        div.querySelector('[data-action="delete"]').addEventListener('click', async (e) => {
            e.stopPropagation();
            if (!confirm('删除预设？')) return;
            await api.deletePrompt(p.id);
            await loadPresets();
        });
        _els.presetsList.appendChild(div);
    }
}

function applyPreset(preset) {
    const s = getState();
    if (preset.positive) {
        s.prompt = preset.positive;
        const el = document.getElementById('promptInput');
        if (el) { el.value = preset.positive; el.dispatchEvent(new Event('input', { bubbles: true })); }
    }
    if (preset.negative) {
        s.negativePrompt = preset.negative;
        const el = document.getElementById('negativeInput');
        if (el) { el.value = preset.negative; el.dispatchEvent(new Event('input', { bubbles: true })); }
    }
    if (preset.model) {
        s.model = preset.model;
        window.dispatchEvent(new CustomEvent('nai:refresh-ui', { detail: { key: 'model' } }));
    }
    toast(`已应用：${preset.title}`, { type: 'success' });
}

async function saveCurrentPreset() {
    const s = getState();
    const title = prompt('预设名称：');
    if (!title) return;
    try {
        await api.createPrompt({
            title,
            positive: s.prompt,
            negative: s.negativePrompt,
            model: s.model,
            size: s.size,
            uc_preset: s.ucPreset,
        });
        toast('已保存预设', { type: 'success' });
        await loadPresets();
    } catch (e) {
        toast('保存失败: ' + e.message, { type: 'error' });
    }
}

// Snippets management
function loadSnippets() {
    const s = getState();
    const list = s.snippets || [];
    if (_els.snippetsArea) {
        _els.snippetsArea.value = list.map(s => `${s.title} | ${s.content}`).join('\n');
    }
}

function saveSnippets() {
    const text = _els.snippetsArea?.value || '';
    const lines = text.split('\n').map(l => l.trim()).filter(Boolean);
    const snippets = [];
    for (const line of lines) {
        const idx = line.indexOf('|');
        if (idx > 0) {
            snippets.push({ title: line.slice(0, idx).trim(), content: line.slice(idx + 1).trim() });
        } else {
            snippets.push({ title: line, content: line });
        }
    }
    setState({ snippets });
    saveLocal();
    toast('片段已保存', { type: 'success' });
}

function insertSnippet() {
    const sel = _els.snippetsArea?.value.substring(_els.snippetsArea.selectionStart, _els.snippetsArea.selectionEnd);
    if (!sel) {
        toast('请先选中片段', { type: 'warning' });
        return;
    }
    const idx = sel.indexOf('|');
    const content = idx > 0 ? sel.slice(idx + 1).trim() : sel;
    const promptInput = document.getElementById('promptInput');
    if (!promptInput) return;
    const start = promptInput.selectionStart || 0;
    const end = promptInput.selectionEnd || 0;
    const before = promptInput.value.slice(0, start);
    const after = promptInput.value.slice(end);
    const sep = (before && !before.match(/[,，]\s*$/)) ? ', ' : '';
    promptInput.value = before + sep + content + after;
    const newPos = start + sep.length + content.length;
    promptInput.setSelectionRange(newPos, newPos);
    promptInput.focus();
    promptInput.dispatchEvent(new Event('input', { bubbles: true }));
    toast('已插入', { type: 'success' });
}

export function initPresets() {
    _els = {
        presetsList:    document.getElementById('presetsList'),
        presetSelect:   document.getElementById('presetSelectPane'),
        snippetsArea:   document.getElementById('snippetsArea'),
    };
    if (!_els.presetsList) return;

    document.getElementById('presetSaveBtn')?.addEventListener('click', saveCurrentPreset);
    document.getElementById('snippetsSaveBtn')?.addEventListener('click', saveSnippets);
    document.getElementById('snippetsAddBtn')?.addEventListener('click', insertSnippet);
    _els.presetSelect?.addEventListener('change', (e) => {
        const id = parseInt(e.target.value);
        if (!id) return;
        const p = (getState().presets || []).find(x => x.id === id);
        if (p) applyPreset(p);
        e.target.value = '';
    });

    // Floating panel mini tabs
    document.querySelectorAll('.floating-panel .mini-tabs button').forEach(b => {
        b.addEventListener('click', () => {
            const target = b.dataset.tab;
            document.querySelectorAll('.floating-panel .mini-tabs button').forEach(x => x.classList.toggle('active', x === b));
            document.querySelectorAll('.floating-panel .mini-panel').forEach(p => p.classList.toggle('hidden', p.dataset.pane !== target));
        });
    });

    // Settings toggles in floating panel
    document.getElementById('qualitySettingsToggle')?.addEventListener('change', (e) => {
        setState({ qualityToggle: e.target.checked });
        saveLocal();
    });
    document.getElementById('ucPreset')?.addEventListener('change', (e) => {
        setState({ ucPreset: parseInt(e.target.value) });
        saveLocal();
    });
    document.getElementById('emphasisHighlightToggle')?.addEventListener('change', (e) => {
        setState({ emphasisHighlight: e.target.checked });
        saveLocal();
    });

    // Open floating panel
    document.getElementById('promptSettingsBtn')?.addEventListener('click', () => {
        const panel = document.getElementById('promptSettingsPanel');
        panel.classList.toggle('hidden');
        loadPresets();
        loadSnippets();
    });

    loadPresets();
    loadSnippets();
    // Initial sync of toggle states
    const s = getState();
    const q = document.getElementById('qualitySettingsToggle');
    if (q) q.checked = s.qualityToggle;
    const e = document.getElementById('emphasisHighlightToggle');
    if (e) e.checked = s.emphasisHighlight;
    const u = document.getElementById('ucPreset');
    if (u) u.value = String(s.ucPreset);
}
