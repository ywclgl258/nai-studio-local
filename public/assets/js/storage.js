/**
 * NAI Studio - localStorage with debounced sync and auto-save
 * Persists state across page reloads, syncs to server settings.php on key events.
 */

import { getState, setState, subscribe } from './state.js';
import { api } from './api.js';

const STORAGE_KEY = 'nai-studio:v1';
const DEBOUNCE_MS = 600;

// localStorage cache of UI state
let _lsTimer = null;
const _lsCache = {
    theme: null,
    leftPanelOpen: null,
    galleryCollapsed: null,
    prompt: '',
    negativePrompt: '',
    characterPrompts: [''],   // 角色提示词数组（1-3 个）
    posePrompt: '',
    seed: 0,
    size: '832x1216',
    model: 'nai-diffusion-4-5-curated',
    sampler: 'k_euler_ancestral',
    steps: 28,
    scale: 5.0,
    cfgRescale: 0,
    noiseSchedule: 'karras',
    nSamples: 1,
    ucPreset: 0,
    qualityWeight: 0.18,
    qualityToggle: true,
    emphasisHighlight: true,
    activeView: 'generate',
    characters: [],
    vibeRefs: [],
    preciseRefs: [],
    snippets: [],
};

export function loadLocal() {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) return;
        const data = JSON.parse(raw);
        Object.assign(_lsCache, data);
        // Apply to state
        setState({
            theme: data.theme ?? 'dark',
            leftPanelOpen: data.leftPanelOpen ?? true,
            galleryCollapsed: data.galleryCollapsed ?? false,
            prompt: data.prompt ?? '',
            negativePrompt: data.negativePrompt ?? '',
            // 兼容老数据：旧 localStorage 可能存 characterPrompt（单字符串），转成数组
            characterPrompts: data.characterPrompts ?? (data.characterPrompt ? [data.characterPrompt] : ['']),
            posePrompt: data.posePrompt ?? '',
            seed: data.seed ?? 0,
            size: data.size ?? '832x1216',
            model: data.model ?? 'nai-diffusion-4-5-curated',
            sampler: data.sampler ?? 'k_euler_ancestral',
            steps: data.steps ?? 28,
            scale: data.scale ?? 5.0,
            cfgRescale: data.cfgRescale ?? 0,
            noiseSchedule: data.noiseSchedule ?? 'karras',
            nSamples: data.nSamples ?? 1,
            ucPreset: data.ucPreset ?? 0,
            qualityWeight: data.qualityWeight ?? 0.18,
            qualityToggle: data.qualityToggle ?? true,
            emphasisHighlight: data.emphasisHighlight ?? true,
            activeView: data.activeView ?? 'generate',
            characters: data.characters ?? [],
            vibeRefs: data.vibeRefs ?? [],
            preciseRefs: data.preciseRefs ?? [],
            snippets: data.snippets ?? [],
        }, { silent: true });
    } catch (e) {
        console.warn('Failed to load local state', e);
    }
}

export function saveLocal() {
    if (_lsTimer) clearTimeout(_lsTimer);
    _lsTimer = setTimeout(() => {
        try {
            const s = getState();
            _lsCache.theme = s.theme;
            _lsCache.leftPanelOpen = s.leftPanelOpen;
            _lsCache.galleryCollapsed = s.galleryCollapsed;
            _lsCache.prompt = s.prompt;
            _lsCache.negativePrompt = s.negativePrompt;
            _lsCache.characterPrompts = s.characterPrompts;
            _lsCache.posePrompt = s.posePrompt;
            _lsCache.seed = s.seed;
            _lsCache.size = s.size;
            _lsCache.model = s.model;
            _lsCache.sampler = s.sampler;
            _lsCache.steps = s.steps;
            _lsCache.scale = s.scale;
            _lsCache.cfgRescale = s.cfgRescale;
            _lsCache.noiseSchedule = s.noiseSchedule;
            _lsCache.nSamples = s.nSamples;
            _lsCache.ucPreset = s.ucPreset;
            _lsCache.qualityWeight = s.qualityWeight;
            _lsCache.qualityToggle = s.qualityToggle;
            _lsCache.emphasisHighlight = s.emphasisHighlight;
            _lsCache.activeView = s.activeView;
            _lsCache.characters = s.characters;
            _lsCache.vibeRefs = s.vibeRefs;
            _lsCache.preciseRefs = s.preciseRefs;
            _lsCache.snippets = s.snippets;
            localStorage.setItem(STORAGE_KEY, JSON.stringify(_lsCache));
        } catch (e) {
            console.warn('Failed to save local state', e);
        }
    }, DEBOUNCE_MS);
}

export function clearLocal() {
    try { localStorage.removeItem(STORAGE_KEY); } catch {}
}

// API key is stored encrypted on server. The plaintext is fetched once on boot.
let _plainApiKey = null;
export function setPlainApiKey(k) { _plainApiKey = k; }
export function getPlainApiKey() { return _plainApiKey; }

// Subscribe to all state changes → save local
export function initStorage() {
    const keys = Object.keys(_lsCache);
    subscribe(keys, saveLocal);
}
