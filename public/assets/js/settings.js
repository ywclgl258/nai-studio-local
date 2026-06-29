/**
 * NAI Studio - Settings modal
 */

import { api } from './api.js?v=104';
import { getState, setState, subscribe } from './state.js?v=104';
import { toast } from './toast.js?v=104';
import { saveLocal } from './storage.js?v=104';

let _els = {};
let _pmFilter = '';

function escapeHtml(s) {
    return (s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function open() { _els.modal.classList.remove('hidden'); }
function close() { _els.modal.classList.add('hidden'); }

async function loadStats() {
    try {
        const [g, p, t] = await Promise.all([
            api.listGallery({ per_page: 1 }),
            api.listPrompts({ per_page: 1 }),
            api.tagCategories(),
        ]);
        _els.stats.innerHTML = `
            <div class="stat"><span>生成图</span><strong>${g.total || 0}</strong></div>
            <div class="stat"><span>保存预设</span><strong>${p.total || 0}</strong></div>
            <div class="stat"><span>标签库</span><strong>${(t.rows || []).reduce((s, c) => s + (c.tag_count || 0), 0)}</strong></div>
            <div class="stat"><span>数据库</span><strong>nai_studio</strong></div>
        `;
    } catch (e) {
        _els.stats.textContent = '加载统计失败: ' + e.message;
    }
}

async function save() {
    const patch = {
        default_model:    _els.defaultModel.value,
        default_size:     _els.defaultSize.value,
        default_steps:    parseInt(_els.defaultSteps.value),
        default_scale:    parseFloat(_els.defaultScale.value),
        theme:            _els.theme.value,
        emphasis_highlight: _els.emphasis.checked ? 1 : 0,
        quality_toggle:   _els.quality.checked ? 1 : 0,
        proxy_enabled:    _els.proxyEnabled?.checked ? 1 : 0,
        proxy_url:        _els.proxyUrl?.value.trim() || '',
    };
    try {
        await api.updateSettings(patch);
        // Apply to local state
        setState({
            model: patch.default_model,
            size: patch.default_size,
            steps: patch.default_steps,
            scale: patch.default_scale,
            theme: patch.theme,
            emphasisHighlight: !!patch.emphasis_highlight,
            qualityToggle: !!patch.quality_toggle,
        });
        saveLocal();
        document.documentElement.dataset.theme = patch.theme;
        toast('设置已保存', { type: 'success' });
        close();
    } catch (e) {
        toast('保存失败: ' + e.message, { type: 'error' });
    }
}

async function testProxy() {
    if (!_els.proxyTestStatus) return;
    _els.proxyTestStatus.textContent = '测试中…';
    try {
        const r = await api.testProxy();
        _els.proxyTestStatus.textContent = r.message || (r.ok ? '✓ 代理可用' : '✗ 失败');
        _els.proxyTestStatus.style.color = r.ok ? 'var(--success)' : 'var(--danger)';
    } catch (e) {
        _els.proxyTestStatus.textContent = '✗ ' + e.message;
        _els.proxyTestStatus.style.color = 'var(--danger)';
    }
}

async function exportAll() {
    try {
        const [g, p] = await Promise.all([
            api.listGallery({ per_page: 1000 }),
            api.listPrompts({ per_page: 1000 }),
        ]);
        const data = {
            version: window.__NAI_BOOT__?.version,
            exported_at: new Date().toISOString(),
            gallery: g.rows,
            prompts: p.rows,
        };
        const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `nai-studio-export-${Date.now()}.json`;
        a.click();
        URL.revokeObjectURL(url);
        toast('已导出', { type: 'success' });
    } catch (e) {
        toast('导出失败: ' + e.message, { type: 'error' });
    }
}

async function clearGallery() {
    if (!confirm('清空所有生成图？此操作不可撤销。')) return;
    if (!confirm('确定要清空吗？再次确认。')) return;
    try {
        const r = await api.listGallery({ per_page: 1000 });
        for (const item of r.rows) {
            await api.deleteGallery(item.id, true);
        }
        toast('已清空画廊', { type: 'success' });
        loadStats();
    } catch (e) {
        toast('清空失败: ' + e.message, { type: 'error' });
    }
}

function switchTab(name) {
    document.querySelectorAll('#settingsModal .settings-tabs button').forEach(b => b.classList.toggle('active', b.dataset.settingsTab === name));
    document.querySelectorAll('#settingsModal .settings-pane').forEach(p => p.classList.toggle('hidden', p.dataset.pane !== name));
    if (name === 'presets') loadPresetManager();
}

export function openPresetManager() {
    open();
    switchTab('presets');
}

// =================== 预设管理（统一删除/收藏/搜索） ===================
function renderPresetRow(p, kind) {
    const row = document.createElement('div');
    row.style.cssText = 'display:flex;align-items:center;gap:8px;padding:6px 10px;background:var(--bg);border:1px solid var(--border);border-radius:var(--r);';
    const summary = kind === 'character'
        ? (p.prompts ? (Array.isArray(p.prompts) ? p.prompts.filter(Boolean).join(' | ') : p.prompts) : (p.prompt || ''))
        : kind === 'pose' ? (p.prompt || '')
        : ((p.positive || '') + (p.negative ? ' / neg: ' + p.negative : ''));
    const showName = p.title || p.name || '(未命名)';
    const showStar = p.is_favorite ? '★' : '☆';
    row.innerHTML = `
        <span style="flex:1;min-width:0;font-size:12px;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${escapeHtml(summary)}">
            ${escapeHtml(showName)}
            <span style="color:var(--text-muted);font-size:10px;margin-left:4px">${escapeHtml(summary.slice(0, 40))}</span>
        </span>
        <button data-act="fav" title="收藏" style="background:transparent;border:0;cursor:pointer;color:${p.is_favorite ? '#fbbf24' : 'var(--text-muted)'};font-size:14px;padding:0 4px">${showStar}</button>
        <button data-act="del" title="删除" style="background:transparent;border:0;cursor:pointer;color:var(--danger);font-size:16px;padding:0 4px;line-height:1">×</button>
    `;
    row.querySelector('[data-act="fav"]').addEventListener('click', async () => {
        try {
            if (kind === 'prompt') await api.updatePrompt(p.id, { is_favorite: p.is_favorite ? 0 : 1 });
            else if (kind === 'character') await api.updateCharacterPreset(p.id, { is_favorite: p.is_favorite ? 0 : 1 });
            else if (kind === 'pose') await api.updatePosePreset(p.id, { is_favorite: p.is_favorite ? 0 : 1 });
            loadPresetManager();
        } catch (e) { toast(e.message, { type: 'error' }); }
    });
    row.querySelector('[data-act="del"]').addEventListener('click', async () => {
        if (!confirm(`删除预设「${showName}」？`)) return;
        try {
            if (kind === 'prompt') await api.deletePrompt(p.id);
            else if (kind === 'character') await api.deleteCharacterPreset(p.id);
            else if (kind === 'pose') await api.deletePosePreset(p.id);
            toast('已删除', { type: 'success' });
            loadPresetManager();
        } catch (e) { toast(e.message, { type: 'error' }); }
    });
    return row;
}

function filterPresets(presets) {
    if (!_pmFilter) return presets;
    const q = _pmFilter.toLowerCase();
    return presets.filter(p => {
        const text = (p.title || p.name || '') + ' ' + (p.prompt || '') + ' ' + (p.positive || '') +
                     (Array.isArray(p.prompts) ? p.prompts.join(' ') : '');
        return text.toLowerCase().includes(q);
    });
}

async function loadPresetManager() {
    const listPrompt = document.getElementById('presetManagerListPrompt');
    const listChar   = document.getElementById('presetManagerListChar');
    const listPose   = document.getElementById('presetManagerListPose');
    const countP = document.getElementById('presetCountPrompt');
    const countC = document.getElementById('presetCountChar');
    const countPo = document.getElementById('presetCountPose');
    if (!listPrompt) return;

    async function loadOne(apiCall, list, countEl, kind) {
        try {
            const r = await apiCall({ per_page: 1000 });
            const rows = (r.rows || []).slice().sort((a, b) => (b.is_favorite ? 1 : 0) - (a.is_favorite ? 1 : 0));
            countEl.textContent = `(${rows.length})`;
            list.innerHTML = '';
            const filtered = filterPresets(rows);
            if (filtered.length === 0) {
                list.innerHTML = '<p style="color:var(--text-muted);font-size:11px;padding:6px 0">' + (rows.length === 0 ? '暂无预设' : '没有匹配的') + '</p>';
                return;
            }
            for (const p of filtered) list.appendChild(renderPresetRow(p, kind));
        } catch (e) { list.innerHTML = '<p style="color:var(--danger);font-size:11px">' + e.message + '</p>'; }
    }
    await Promise.all([
        loadOne(api.listPrompts, listPrompt, countP, 'prompt'),
        loadOne(api.listCharacterPresets, listChar, countC, 'character'),
        loadOne(api.listPosePresets, listPose, countPo, 'pose'),
    ]);
}

export function initSettings() {
    _els = {
        modal: document.getElementById('settingsModal'),
        proxy: document.getElementById('settingsProxy'),
        proxyEnabled: document.getElementById('settingsProxyEnabled'),
        proxyUrl: document.getElementById('settingsProxy'),
        proxyTestStatus: document.getElementById('proxyTestStatus'),
        defaultModel: document.getElementById('settingsDefaultModel'),
        defaultSize: document.getElementById('settingsDefaultSize'),
        defaultSteps: document.getElementById('settingsDefaultSteps'),
        defaultScale: document.getElementById('settingsDefaultScale'),
        theme: document.getElementById('settingsTheme'),
        emphasis: document.getElementById('settingsEmphasis'),
        quality: document.getElementById('settingsQuality'),
        stats: document.getElementById('settingsStats'),
        version: document.getElementById('aboutVersion'),
    };
    if (!_els.modal) return;

    // Populate model select
    const models = window.__NAI_BOOT__?.models || {};
    _els.defaultModel.innerHTML = Object.entries(models).map(([id, name]) => `<option value="${id}">${name}</option>`).join('');

    // Load current settings
    api.getSettings().then(res => {
        const s = res.settings;
        _els.defaultModel.value = s.default_model || '';
        _els.defaultSize.value = s.default_size || '832x1216';
        _els.defaultSteps.value = s.default_steps || 28;
        _els.defaultScale.value = s.default_scale || 5;
        _els.theme.value = s.theme || 'dark';
        _els.emphasis.checked = !!s.emphasis_highlight;
        _els.quality.checked = !!s.quality_toggle;
        if (_els.proxyEnabled) _els.proxyEnabled.checked = !!s.proxy_enabled;
        if (_els.proxyUrl) _els.proxyUrl.value = s.proxy_url || '';
        if (_els.proxyTestStatus && s.proxy_test_status) {
            _els.proxyTestStatus.textContent = s.proxy_test_status;
            _els.proxyTestStatus.style.color = s.proxy_test_status.startsWith('ok:') ? 'var(--success)' : 'var(--danger)';
        }
    });

    // Version
    _els.version.textContent = window.__NAI_BOOT__?.version || '1.0.0';

    // Proxy test
    document.getElementById('testProxyBtn')?.addEventListener('click', testProxy);

    // Events
    document.getElementById('openSettingsBtn')?.addEventListener('click', () => { open(); loadStats(); });
    document.getElementById('closeSettingsBtn')?.addEventListener('click', close);
    document.getElementById('cancelSettingsBtn')?.addEventListener('click', close);
    _els.modal.addEventListener('click', (e) => { if (e.target === _els.modal) close(); });
    document.getElementById('saveSettingsBtn')?.addEventListener('click', save);
    document.getElementById('exportAllBtn')?.addEventListener('click', exportAll);
    document.getElementById('clearGalleryBtn')?.addEventListener('click', clearGallery);
    document.querySelectorAll('.settings-tabs button').forEach(b => {
        b.addEventListener('click', () => switchTab(b.dataset.settingsTab));
    });

    // 预设管理搜索
    document.getElementById('presetSearch')?.addEventListener('input', (e) => {
        _pmFilter = e.target.value.trim();
        loadPresetManager();
    });

    // Theme change is instant
    _els.theme?.addEventListener('change', () => {
        document.documentElement.dataset.theme = _els.theme.value;
    });
}
