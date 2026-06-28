/**
 * NAI Studio - Centralized state with subscribe pattern.
 */

const _state = {
    // UI
    activeView: 'generate',          // 'generate' | 'director'
    leftPanelOpen: window.innerWidth > 900,
    galleryCollapsed: false,
    theme: 'dark',
    emphasisHighlight: true,
    qualityToggle: true,
    anlas: null,

    // API
    apiKeyPresent: false,
    csrfToken: '',

    // Generation params
    model: 'nai-diffusion-4-5-curated',
    sampler: 'k_euler_ancestral',
    steps: 28,
    scale: 5.0,
    cfgRescale: 0.0,
    noiseSchedule: 'karras',
    size: '832x1216',
    nSamples: 1,
    ucPreset: 0,
    seed: 0,
    qualityWeight: 0.18,

    // Prompts
    prompt: '',
    negativePrompt: '',
    characterPrompts: [''],          // 角色提示词数组（1-3 个，拼接成 character_prompt）
    posePrompt: '',                  // 姿势提示词（拼到主提示词后面）

    // References
    vibeRefs: [],                    // [{id, path, name, strength, info_extracted}]
    preciseRefs: [],                 // [{id, path, name, type, strength, info_extracted}]

    // Base image (img2img / inpaint)
    baseImage: null,                 // {path, base64, dataURL}
    baseImageMode: 'img2img',        // 'img2img' | 'inpaint'
    strength: 0.7,
    noise: 0,
    mask: null,                      // base64 dataURL

    // Director
    directorSource: null,            // base64
    directorResult: null,
    directorTool: 'augment-bg-removal',

    // Prompts
    presets: [],                     // [{id, title, positive, negative, ...}]
    characterPresets: [],            // [{id, name, prompt, is_favorite}]
    posePresets: [],                 // [{id, name, prompt, category, is_favorite}]
    snippets: [],

    // Gallery
    gallery: [],                     // current page
    galleryPage: 1,
    galleryTotal: 0,
    activeImage: null,               // current focused image
};

const _subscribers = new Map();
let _id = 0;

export function getState() { return _state; }

export function setState(patch, opts = {}) {
    const before = {};
    for (const k of Object.keys(patch)) before[k] = _state[k];
    Object.assign(_state, patch);
    if (!opts.silent) notify(patch, before);
}

export function subscribe(keys, fn) {
    const id = ++_id;
    const keyArr = Array.isArray(keys) ? keys : [keys];
    _subscribers.set(id, { keys: keyArr, fn });
    return () => _subscribers.delete(id);
}

function notify(patch, before) {
    for (const [, sub] of _subscribers) {
        const hit = sub.keys.some(k => k in patch);
        if (hit) {
            try { sub.fn(patch, before); } catch (e) { console.error('[state] subscriber error', e); }
        }
    }
}

// One-time state reset (for "重置工作台")
export function resetWorkbench() {
    setState({
        prompt: '',
        negativePrompt: '',
        characterPrompts: [''],
        posePrompt: '',
        characters: [],
        vibeRefs: [],
        preciseRefs: [],
        baseImage: null,
        mask: null,
        strength: 0.7,
        noise: 0,
        seed: 0,
        nSamples: 1,
    });
}
