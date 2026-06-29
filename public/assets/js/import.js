/**
 * NAI Studio - Image import + metadata extraction
 */

import { api } from './api.js?v=104';
import { getState, setState } from './state.js?v=104';
import { toast } from './toast.js?v=104';

let _pendingFile = null;
let _pendingDataURL = null;
let _meta = null;
let _els = {};

function readFileAsDataURL(file) {
    return new Promise((resolve, reject) => {
        const r = new FileReader();
        r.onload = () => resolve(r.result);
        r.onerror = () => reject(r.error);
        r.readAsDataURL(file);
    });
}

async function importAs(mode) {
    if (!_pendingDataURL) return;
    // Use the path from metadata or upload now
    let path = _meta?.path;
    if (!path) {
        try {
            const r = await api.upload(_pendingFile);
            path = r.path;
        } catch (e) {
            toast('上传失败: ' + e.message, { type: 'error' });
            return;
        }
    }
    const item = {
        id: 'i_' + Date.now().toString(36),
        path,
        name: _pendingFile?.name || 'imported',
        strength: mode === 'vibe' ? 0.6 : 0.6,
        info_extracted: _meta?.info || null,
    };
    if (mode === 'vibe') {
        setState({ vibeRefs: [...getState().vibeRefs, item] });
        toast('已添加为风格参考', { type: 'success' });
    } else if (mode === 'precise') {
        item.type = 'character';
        setState({ preciseRefs: [...getState().preciseRefs, item] });
        toast('已添加为精确参考', { type: 'success' });
    } else if (mode === 'img2img') {
        setState({ baseImage: { path, base64: _pendingDataURL, dataURL: _pendingDataURL }, baseImageMode: 'img2img' });
        showBaseImage(path);
        toast('已作为底图，可在底图栏调整', { type: 'success' });
    }
    close();
}

function showBaseImage(path) {
    const slot = document.getElementById('baseImageSlot');
    const active = document.getElementById('baseImageActive');
    const preview = document.getElementById('baseImagePreview');
    if (!slot || !active) return;
    slot.classList.add('hidden');
    active.classList.remove('hidden');
    preview.src = path;
}

function applyMetadata() {
    if (!_meta?.info) return;
    const s = getState();
    const append = document.getElementById('metaAppendCheck')?.checked ?? false;
    if (document.getElementById('metaPromptCheck')?.checked && _meta.info.prompt) {
        s.prompt = append ? (s.prompt ? s.prompt + ', ' : '') + _meta.info.prompt : _meta.info.prompt;
        const el = document.getElementById('promptInput');
        if (el) { el.value = s.prompt; el.dispatchEvent(new Event('input', { bubbles: true })); }
    }
    if (document.getElementById('metaNegativeCheck')?.checked && _meta.info.negative) {
        s.negativePrompt = append ? (s.negativePrompt ? s.negativePrompt + ', ' : '') + _meta.info.negative : _meta.info.negative;
        const el = document.getElementById('negativeInput');
        if (el) { el.value = s.negativePrompt; el.dispatchEvent(new Event('input', { bubbles: true })); }
    }
    if (document.getElementById('metaSettingsCheck')?.checked) {
        if (_meta.info.model) s.model = _meta.info.model;
        if (_meta.info.sampler) s.sampler = _meta.info.sampler;
        if (_meta.info.steps) s.steps = _meta.info.steps;
        if (_meta.info.scale) s.scale = _meta.info.scale;
        if (_meta.info.noise_schedule) s.noiseSchedule = _meta.info.noise_schedule;
        if (_meta.info.width && _meta.info.height) s.size = `${_meta.info.width}x${_meta.info.height}`;
        ['model','sampler','steps','scale','noiseSchedule','size'].forEach(k => {
            window.dispatchEvent(new CustomEvent('nai:refresh-ui', { detail: { key: k } }));
        });
    }
    if (document.getElementById('metaSeedCheck')?.checked && _meta.info.seed !== undefined) {
        s.seed = _meta.info.seed;
        const el = document.getElementById('seedInput');
        if (el) { el.value = s.seed; el.dispatchEvent(new Event('input', { bubbles: true })); }
    }
    toast('已导入元数据', { type: 'success' });
    close();
}

function showMetadata(info) {
    const panel = document.getElementById('metadataPanel');
    const empty = document.getElementById('metadataEmptyPanel');
    if (!info || (!info.prompt && !info.negative && !info.model)) {
        panel.classList.add('hidden');
        empty.classList.remove('hidden');
        return;
    }
    panel.classList.remove('hidden');
    empty.classList.add('hidden');
    const setVal = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v || '-'; };
    setVal('metaPromptValue',   info.prompt ? info.prompt.slice(0, 60) + (info.prompt.length > 60 ? '...' : '') : '-');
    setVal('metaNegativeValue', info.negative ? info.negative.slice(0, 60) : '-');
    setVal('metaSettingsValue', [info.model, info.sampler, info.steps, info.scale].filter(Boolean).join(' / '));
    setVal('metaSeedValue', info.seed !== undefined ? String(info.seed) : '-');
}

async function open(file) {
    if (!file) return;
    _pendingFile = file;
    _pendingDataURL = await readFileAsDataURL(file);
    _els.modal.classList.remove('hidden');
    _els.preview.src = _pendingDataURL;
    _els.metadataPanel.classList.add('hidden');
    _els.metadataEmpty.classList.remove('hidden');
    // Extract metadata via API
    try {
        const r = await api.importMeta({ base64: _pendingDataURL });
        _meta = r;
        showMetadata(r.info);
    } catch (e) {
        _meta = { info: null };
        showMetadata(null);
    }
}

function close() {
    _els.modal.classList.add('hidden');
    _pendingFile = null;
    _pendingDataURL = null;
    _meta = null;
}

export function initImport() {
    _els = {
        modal:    document.getElementById('imageImportModal'),
        preview:  document.getElementById('importPreview'),
        metadataPanel:    document.getElementById('metadataPanel'),
        metadataEmpty:    document.getElementById('metadataEmptyPanel'),
    };
    if (!_els.modal) return;

    document.getElementById('importImageBtn')?.addEventListener('click', () => {
        document.getElementById('importImageInput')?.click();
    });
    document.getElementById('importImageInput')?.addEventListener('change', (e) => {
        if (e.target.files[0]) open(e.target.files[0]);
    });

    document.getElementById('closeImageImportBtn')?.addEventListener('click', close);
    _els.modal.addEventListener('click', (e) => { if (e.target === _els.modal) close(); });

    document.getElementById('importAsImg2ImgBtn')?.addEventListener('click', () => importAs('img2img'));
    document.getElementById('importAsVibeBtn')?.addEventListener('click', () => importAs('vibe'));
    document.getElementById('importAsPreciseBtn')?.addEventListener('click', () => importAs('precise'));
    document.getElementById('importMetadataBtn')?.addEventListener('click', applyMetadata);

    // Drag & drop on app body
    document.body.addEventListener('dragover', (e) => {
        if (e.dataTransfer.types.includes('Files')) {
            e.preventDefault();
        }
    });
    document.body.addEventListener('drop', (e) => {
        if (e.dataTransfer.files && e.dataTransfer.files.length) {
            for (const f of e.dataTransfer.files) {
                if (f.type.startsWith('image/')) {
                    open(f);
                    break;
                }
            }
        }
    });
}
