/**
 * NAI Studio - Left panel: API key, model, size, sample count, anlas
 */

import { getState, setState, subscribe } from './state.js?v=104';
import { api } from './api.js?v=104';
import { toast } from './toast.js?v=104';
import { setPlainApiKey, saveLocal } from './storage.js?v=104';

const SIZE_PRESETS = [
    { value: '640x768',  label: '小竖图',   w: 640,  h: 768  },
    { value: '832x1216', label: '普通竖图', w: 832,  h: 1216 },
    { value: '1024x1024', label: '方图',   w: 1024, h: 1024 },
    { value: '1024x1536', label: '大竖图', w: 1024, h: 1536 },
    { value: '1216x832',  label: '普通横图', w: 1216, h: 832  },
    { value: '1280x720',  label: '宽屏',   w: 1280, h: 720  },
    { value: '1472x1472', label: '大方图', w: 1472, h: 1472 },
    { value: '1536x1024', label: '大横图', w: 1536, h: 1024 },
    { value: '1920x1024', label: '超宽',   w: 1920, h: 1024 },
];

function getModelLabel(id) {
    const models = window.__NAI_BOOT__?.models || {};
    return models[id] || id;
}

function setupCustomSelect(triggerId, menuId, labelId, options, current, onSelect) {
    const trigger = document.getElementById(triggerId);
    const menu = document.getElementById(menuId);
    const label = document.getElementById(labelId);
    if (!trigger || !menu) return;

    function render() {
        menu.innerHTML = '';
        for (const opt of options) {
            const el = document.createElement('div');
            el.className = 'custom-select-option' + (opt.value === current ? ' selected' : '');
            el.innerHTML = `<span>${opt.label}</span>${opt.meta ? `<span class="opt-meta">${opt.meta}</span>` : ''}`;
            el.addEventListener('click', () => {
                current = opt.value;
                onSelect(opt.value);
                label.textContent = opt.label;
                menu.querySelectorAll('.custom-select-option').forEach((o, i) => {
                    o.classList.toggle('selected', options[i].value === current);
                });
                trigger.setAttribute('aria-expanded', 'false');
                menu.classList.add('hidden');
            });
            menu.appendChild(el);
        }
    }
    render();
    label.textContent = (options.find(o => o.value === current) || {}).label || '';

    trigger.addEventListener('click', (e) => {
        e.stopPropagation();
        const expanded = trigger.getAttribute('aria-expanded') === 'true';
        trigger.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        menu.classList.toggle('hidden', expanded);
    });
    document.addEventListener('click', () => {
        trigger.setAttribute('aria-expanded', 'false');
        menu.classList.add('hidden');
    });
    return { setCurrent: (v) => { current = v; render(); label.textContent = (options.find(o => o.value === v) || {}).label || ''; } };
}

export function initPanel() {
    const s = getState();

    // --- API key ---
    const apiKeyInput = document.getElementById('apiKeyInput');
    const apiKeyHint = document.getElementById('apiKeyHint');
    const apiKeyCard = document.getElementById('apiKeyCard');
    const toggleKey = document.getElementById('toggleApiKeyVisibility');

    function setApiKeyHint(text, kind) {
        if (!apiKeyHint) return;
        apiKeyHint.textContent = text;
        apiKeyHint.classList.remove('error', 'ok');
        if (kind) apiKeyHint.classList.add(kind);
    }

    if (apiKeyInput) {
        // Load existing key
        api.getSettings().then(res => {
            if (res.settings.api_key_plain) {
                apiKeyInput.value = res.settings.api_key_plain;
                setPlainApiKey(res.settings.api_key_plain);
                setApiKeyHint(`已保存 · 末四位 ${res.settings.api_key_fingerprint || '----'}`, 'ok');
            } else {
                setApiKeyHint('未设置 API Key，将无法生图', 'error');
            }
        }).catch(e => {
            setApiKeyHint('加载设置失败: ' + e.message, 'error');
        });

        let saveTimer = null;
        apiKeyInput.addEventListener('input', () => {
            if (saveTimer) clearTimeout(saveTimer);
            saveTimer = setTimeout(async () => {
                const val = apiKeyInput.value.trim();
                try {
                    await api.updateSettings({ api_key: val });
                    setPlainApiKey(val);
                    setApiKeyHint(val ? `已保存 · 末四位 ${val.slice(-4)}` : '已清空', val ? 'ok' : 'error');
                } catch (e) {
                    setApiKeyHint('保存失败: ' + e.message, 'error');
                }
            }, 800);
        });
    }
    if (toggleKey) {
        toggleKey.addEventListener('click', () => {
            apiKeyInput.type = apiKeyInput.type === 'password' ? 'text' : 'password';
        });
    }

    // --- Model selector ---
    const modelOptions = Object.entries(window.__NAI_BOOT__?.models || {}).map(([id, name]) => ({
        value: id, label: name, meta: id.includes('curated') ? 'curated' : (id.includes('full') ? 'full' : ''),
    }));
    setupCustomSelect('modelSelectTrigger', 'modelSelectMenu', 'modelSelectLabel',
        modelOptions, s.model, (v) => { setState({ model: v }); saveLocal(); });

    // --- Size preset selector ---
    const sizeOptions = SIZE_PRESETS.map(p => ({ value: p.value, label: `${p.label} (${p.w}×${p.h})`, meta: `${p.value}` }));
    setupCustomSelect('sizePresetTrigger', 'sizePresetMenu', 'sizePresetLabel',
        sizeOptions, s.size, (v) => {
            setState({ size: v });
            saveLocal();
            const p = SIZE_PRESETS.find(p => p.value === v);
            if (p) {
                document.getElementById('widthValue').textContent = p.w;
                document.getElementById('heightValue').textContent = p.h;
            }
        });

    // Set initial width/height display
    const initPreset = SIZE_PRESETS.find(p => p.value === s.size) || SIZE_PRESETS[1];
    document.getElementById('widthValue').textContent = initPreset.w;
    document.getElementById('heightValue').textContent = initPreset.h;

    // --- Sample count ---
    document.querySelectorAll('.sample-tabs button').forEach(btn => {
        if (parseInt(btn.dataset.count) === s.nSamples) {
            document.querySelectorAll('.sample-tabs button').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
        }
        btn.addEventListener('click', () => {
            document.querySelectorAll('.sample-tabs button').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            setState({ nSamples: parseInt(btn.dataset.count) });
            saveLocal();
        });
    });

    // --- Anlas status ---
    const anlasStatus = document.getElementById('anlasStatus');
    const anlasValue = anlasStatus?.querySelector('.anlas-value');
    async function refreshAnlas() {
        if (!anlasStatus) return;
        try {
            const r = await api.anlas();
            const tier = r.tier;
            if (r.anlas !== null && r.anlas !== undefined) {
                anlasValue.textContent = r.anlas.toLocaleString();
                anlasValue.title = `${r.anlas} Anlas · ${tierLabel(tier)}`;
                anlasStatus.classList.add('active');
                setState({ anlas: r.anlas, anlasTier: tier });
            } else if (tier === 3) {
                // Paper 会员：无 anlas 限制
                anlasValue.textContent = '∞';
                anlasValue.title = 'Paper 订阅（无 Anlas 限制）';
                anlasStatus.classList.add('active', 'paper');
                setState({ anlas: null, anlasTier: tier });
            } else if (tier === 2) {
                anlasValue.textContent = '∞';
                anlasValue.title = 'Tabletop 订阅（无 Anlas 限制）';
                anlasStatus.classList.add('active', 'paper');
                setState({ anlas: null, anlasTier: tier });
            } else {
                anlasValue.textContent = '—';
                anlasValue.title = '暂不可用';
                anlasStatus.classList.remove('active', 'paper');
            }
        } catch (e) {
            anlasValue.textContent = 'err';
            anlasStatus.classList.add('error');
        }
    }
    function tierLabel(tier) {
        return { 0: 'Free', 1: 'Standard', 2: 'Tabletop', 3: 'Paper', 4: 'Opus' }[tier] || `Tier ${tier}`;
    }
    if (anlasStatus) anlasStatus.addEventListener('click', refreshAnlas);
    // Auto-refresh on boot
    setTimeout(refreshAnlas, 500);

    // --- Reset workbench ---
    document.getElementById('resetWorkbenchBtn')?.addEventListener('click', () => {
        if (confirm('重置工作台？提示词、底图、参考都会清空。')) {
            const evt = new CustomEvent('nai:reset-workbench');
            window.dispatchEvent(evt);
        }
    });
}
