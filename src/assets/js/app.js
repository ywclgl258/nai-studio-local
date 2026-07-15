/**
 * NAI Studio - Main application entry
 */

import { getState, setState, subscribe, resetWorkbench } from './state.js';
import { loadLocal, initStorage, saveLocal } from './storage.js';
import { api } from './api.js';
import { buildGeneratePayload } from './generate-payload.js';
import { toast } from './toast.js';
import { enqueueBatch, initQueue } from './queue.js';
import { initProjectQueue } from './project-queue.js';

import { initPanel } from './panel.js';
import { initPromptEditor } from './prompt.js';
import { initAiSettings } from './ai-settings.js';
import { initTagPicker } from './tag-picker.js';
import { initCharacters } from './characters.js';
import { initPose } from './pose.js';
import { attachMention } from './mention-presets.js';
import { initVibe } from './vibe.js';
import { initPrecise } from './precise.js';
import { initBaseImage } from './base-image.js';
import { initMaskEditor } from './mask-editor.js';
import { initGallery } from './gallery.js';
import { initImport } from './import.js';
import { initDirector } from './director.js';
import { initSettings } from './settings.js';
import { initActions } from './actions.js';
import { initPresets } from './presets.js';
import { initKeyboard } from './keyboard.js';
import { initPresetSave } from './preset-modal.js';
import { initDecomposer } from './decomposer.js';
import { initArtistLibrary } from './artist_library.js';
import { initAiCompose } from './ai-compose.js';
import { initUpscale } from './upscale.js';

function mountTemplate(id) {
    const tpl = document.getElementById(id);
    if (!tpl) return;
    const root = tpl.content.cloneNode(true);
    document.getElementById('app').appendChild(root);
}

// v0.8 布局开关：PR-1 默认 v1（零行为变更）；URL 加 ?shell=v2 可预览新布局
function applyShellVersion() {
    const params = new URLSearchParams(location.search);
    const want = params.get('shell') || 'v1';
    const app = document.getElementById('app');
    if (!app) return;
    if (want === 'v2') {
        app.setAttribute('data-shell', 'v2');
    } else {
        app.removeAttribute('data-shell');
    }
}

function mountShell() {
    mountTemplate('tpl-topbar');
    mountTemplate('tpl-leftpanel');
    mountTemplate('tpl-main');
}

async function onGenerate() {
    const btn = document.getElementById('generateBtn');
    if (btn.disabled) return;
    const s = getState();
    if (!s.prompt && !s.characterPrompt && !s.posePrompt) {
        toast('请填写提示词、角色或姿势', { type: 'warning' });
        return;
    }
    if (!s.apiKeyPresent && !window.__NAI_BOOT__?.apiKeyPresent) {
        toast('请先在左侧设置 API Key', { type: 'warning' });
        return;
    }
    btn.disabled = true;
    btn.classList.add('generating');
    const label = document.getElementById('generateLabel');
    const labelV2 = document.getElementById('generateLabelV2');
    const origLabel = label.textContent;
    const origLabelV2 = labelV2?.textContent;
    label.textContent = '生成中…';
    if (labelV2) labelV2.textContent = '生成中…';
    try {
        const payload = buildGeneratePayload(s);
        const r = await api.generate(payload);
        toast(`生成完成：${r.items.length} 张`, { type: 'success' });
        // Refresh history strip + show the new image in main preview
        const gallery = await import('./gallery.js');
        gallery.reloadGallery();
        if (r.items.length > 0) {
            const first = await api.getGalleryItem(r.items[0].id);
            if (first?.item) gallery.showMainImage(first.item);
        }
    } catch (e) {
        toast('生成失败: ' + e.message, { type: 'error', duration: 6000 });
    } finally {
        btn.disabled = false;
        btn.classList.remove('generating');
        label.textContent = origLabel;
        if (labelV2 && origLabelV2) labelV2.textContent = origLabelV2;
    }
}

function onResetWorkbench() {
    resetWorkbench();
    document.getElementById('promptInput').value = '';
    document.getElementById('negativeInput').value = '';
    const poseInput = document.getElementById('poseInput');
    if (poseInput) poseInput.value = '';
    document.getElementById('seedInput').value = '';
    document.getElementById('baseImageSlot')?.classList.remove('hidden');
    document.getElementById('baseImageActive')?.classList.add('hidden');
    document.getElementById('characterList').innerHTML = '';
    document.getElementById('vibeList').innerHTML = '';
    document.getElementById('preciseList').innerHTML = '';
    document.getElementById('vibeCount').textContent = '(0)';
    toast('已重置工作台', { type: 'success' });
}

function applyTheme(theme) {
    document.documentElement.dataset.theme = theme || 'dark';
}

function setupMobileMenu() {
    const btn = document.getElementById('mobileMenuBtn');
    btn?.addEventListener('click', () => {
        const shell = document.querySelector('.app-shell');
        shell.classList.toggle('show-left');
    });
}

// v0.8 辅助工具 dropdown 开关
function setupToolsDropdown() {
    const btn = document.getElementById('toolsDropdownBtn');
    const menu = document.getElementById('toolsDropdownMenu');
    if (!btn || !menu) return;
    btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const isOpen = !menu.classList.contains('hidden');
        menu.classList.toggle('hidden');
        btn.setAttribute('aria-expanded', String(!isOpen));
    });
    // 点击外部或 Esc 关闭
    document.addEventListener('click', (e) => {
        if (!menu.classList.contains('hidden') && !menu.contains(e.target) && e.target !== btn) {
            menu.classList.add('hidden');
            btn.setAttribute('aria-expanded', 'false');
        }
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !menu.classList.contains('hidden')) {
            menu.classList.add('hidden');
            btn.setAttribute('aria-expanded', 'false');
        }
    });
}

// v0.8 浮动主操作条 → 复用旧按钮行为
function setupFloatingCta() {
    const ctaPrimary = document.getElementById('ctaPrimary');
    const ctaQueue = document.getElementById('ctaQueue');
    const ctaProject = document.getElementById('ctaProject');
    ctaPrimary?.addEventListener('click', () => document.getElementById('generateBtn')?.click());
    ctaQueue?.addEventListener('click', () => document.getElementById('queueGenerateBtn')?.click());
    ctaProject?.addEventListener('click', () => document.getElementById('openProjectQueueBtn')?.click());
}

// v0.8 空态模板点击 → 写入对应提示词
function setupEmptyTemplates() {
    if (!document.querySelector('.app-shell[data-shell="v2"]')) return;
    const tplMap = {
        portrait:  '1girl, masterpiece, best quality, portrait, upper body, looking at viewer, simple background, ',
        scene:     'masterpiece, best quality, scenic illustration, beautiful detailed sky, atmospheric, wide angle, ',
        fullbody:  '1girl, masterpiece, best quality, full body, standing, multiple girls, dynamic pose, ',
        pixel:     'masterpiece, best quality, ',  // 画师串走 preset 库
    };
    document.querySelectorAll('.empty-template-card').forEach(card => {
        card.addEventListener('click', () => {
            const t = card.dataset.template;
            const ta = document.getElementById('promptInput');
            if (!ta) return;
            if (t === 'pixel') {
                document.getElementById('tagPickerBtn')?.click();
                return;
            }
            const cur = ta.value.trim();
            ta.value = (cur ? cur.replace(/[, ]+$/, '') + ', ' : '') + tplMap[t];
            ta.dispatchEvent(new Event('input', { bubbles: true }));
            ta.focus();
        });
    });
}

// v0.8 快捷键提示气泡
let _kbdHintTimer = null;
export function showKbdHint(text, key = '') {
    const el = document.getElementById('kbdHint');
    if (!el) return;
    document.getElementById('kbdHintText').textContent = text;
    const k = document.getElementById('kbdHintKey');
    if (key) { k.textContent = key; k.style.display = ''; } else { k.style.display = 'none'; }
    el.hidden = false;
    el.classList.add('show');
    clearTimeout(_kbdHintTimer);
    _kbdHintTimer = setTimeout(() => {
        el.classList.remove('show');
        setTimeout(() => { el.hidden = true; }, 200);
    }, 1600);
}

// v0.8 全局快捷键（v2 only）— ⌘K 命令面板占位
function setupGlobalShortcuts() {
    if (!document.querySelector('.app-shell[data-shell="v2"]')) return;
    document.addEventListener('keydown', (e) => {
        const isMod = e.metaKey || e.ctrlKey;
        if (isMod && e.key.toLowerCase() === 'k') {
            e.preventDefault();
            showKbdHint('命令面板（开发中）', '⌘K');
        }
        if (isMod && e.key === ',') {
            e.preventDefault();
            document.getElementById('openSettingsBtn')?.click();
            showKbdHint('已打开设置', '⌘,');
        }
        if (isMod && e.shiftKey && e.key.toLowerCase() === 'p') {
            e.preventDefault();
            document.getElementById('openDirectorBtn')?.click();
            showKbdHint('切换到 Director', '⌘⇧P');
        }
    });
}

function setupRefreshUI() {
    window.addEventListener('nai:refresh-ui', (e) => {
        const s = getState();
        const { key } = e.detail || {};
        if (key === 'model' || !key) {
            // Update model select display
            const lbl = document.getElementById('modelSelectLabel');
            if (lbl) lbl.textContent = (window.__NAI_BOOT__?.models || {})[s.model] || s.model;
        }
        if (key === 'size' || !key) {
            const lbl = document.getElementById('sizePresetLabel');
            if (lbl) {
                const m = s.size?.match(/^(\d+)x(\d+)$/);
                if (m) {
                    lbl.textContent = `${m[1]} × ${m[2]}`;
                    document.getElementById('widthValue').textContent = m[1];
                    document.getElementById('heightValue').textContent = m[2];
                }
            }
        }
        if (key === 'sampler' || !key) {
            const sel = document.getElementById('samplerSelect');
            if (sel) sel.value = s.sampler;
        }
        if (key === 'steps' || !key) {
            const inp = document.getElementById('stepsInput');
            const val = document.getElementById('stepsValue');
            if (inp) inp.value = s.steps;
            if (val) val.textContent = s.steps;
        }
        if (key === 'scale' || !key) {
            const inp = document.getElementById('scaleInput');
            const val = document.getElementById('scaleValue');
            if (inp) inp.value = s.scale;
            if (val) val.textContent = parseFloat(s.scale).toFixed(1);
        }
        if (key === 'noiseSchedule' || !key) {
            const sel = document.getElementById('noiseScheduleSelect');
            if (sel) sel.value = s.noiseSchedule;
        }
    });
}

async function boot() {
    loadLocal();
    applyTheme(getState().theme);
    setState({ apiKeyPresent: !!window.__NAI_BOOT__?.apiKeyPresent });

    // Mount templates
    applyShellVersion();
    mountShell();

    // Init storage auto-save
    initStorage();

    // Init modules
    const safeInit = (name, fn) => { try { fn(); } catch (e) { console.error('[init]', name, 'failed:', e); } };
    // Mention 必须最早 init（在 panel 之前），因为它装全局 listener
    safeInit('Mention', () => attachMention(document.getElementById('promptInput'), 'prompt'));
    safeInit('Panel',       initPanel);
    safeInit('PromptEditor',initPromptEditor);
    safeInit('AiSettings',  initAiSettings);
    safeInit('TagPicker',   initTagPicker);
    safeInit('Characters',  initCharacters);
    safeInit('Pose',        initPose);
    safeInit('Vibe',        initVibe);
    safeInit('Precise',     initPrecise);
    safeInit('BaseImage',   initBaseImage);
    safeInit('MaskEditor',  initMaskEditor);
    safeInit('Gallery',     initGallery);
    safeInit('Import',      initImport);
    safeInit('Director',    initDirector);
    safeInit('Settings',    initSettings);
    safeInit('Actions',     initActions);
    safeInit('Presets',     initPresets);
    safeInit('Queue',       initQueue);
    safeInit('ProjectQueue',initProjectQueue);
    safeInit('Keyboard',    initKeyboard);
    safeInit('PresetSave',  initPresetSave);
    safeInit('Decomposer',  initDecomposer);
    safeInit('ArtistLibrary', initArtistLibrary);
    safeInit('AiCompose',   initAiCompose);
    safeInit('Upscale',     initUpscale);
    setupMobileMenu();
    setupToolsDropdown();
    setupFloatingCta();
    setupEmptyTemplates();
    setupGlobalShortcuts();
    setupRefreshUI();

    // Apply theme + UI state
    document.documentElement.dataset.theme = getState().theme;

    // Update model/size labels
    setTimeout(() => {
        window.dispatchEvent(new CustomEvent('nai:refresh-ui', { detail: { key: 'model' } }));
        window.dispatchEvent(new CustomEvent('nai:refresh-ui', { detail: { key: 'size' } }));
    }, 100);

    // Generate button
    document.getElementById('generateBtn')?.addEventListener('click', onGenerate);

    // Reset
    window.addEventListener('nai:reset-workbench', onResetWorkbench);

    // Hide boot splash
    const splash = document.getElementById('bootSplash');
    if (splash) {
        splash.classList.add('hidden');
        setTimeout(() => splash.remove(), 400);
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}
