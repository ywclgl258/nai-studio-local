/**
 * NAI Studio - API client (fetch wrapper)
 */

// Derive base path from the script's own location so the API works whether
// served from root, /nai-studio/, or any subdirectory.
// We can't rely on document.currentScript when the script is an ES module, so
// we walk the document.scripts collection (already populated since modules
// are deferred by default).
function detectBase() {
    const scripts = document.getElementsByTagName('script');
    for (let i = 0; i < scripts.length; i++) {
        const src = scripts[i].src || '';
        if (src.indexOf('/assets/js/app.js') >= 0) {
            const idx = src.indexOf('/assets/js/app.js');
            return src.slice(0, idx);
        }
    }
    // Fallback: derive from document URL path
    const path = window.location.pathname;
    // path looks like "/nai-studio/" or "/nai-studio/index.php" or "/"
    const m = path.match(/^(\/[^\/]*)?\//);
    return (m && m[1]) ? m[1] : '';
}
const BASE = detectBase();
console.log('[NAI Studio] API base =', JSON.stringify(BASE));

class ApiError extends Error {
    constructor(message, status, body) {
        super(message);
        this.status = status;
        this.body = body;
    }
}

async function request(method, url, options = {}) {
    const headers = { 'Accept': 'application/json', ...(options.headers || {}) };
    if (options.body && typeof options.body !== 'string' && !(options.body instanceof FormData)) {
        headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(options.body);
    }
    if (window.__NAI_BOOT__?.csrfToken) {
        headers['X-Requested-With'] = 'XMLHttpRequest';
    }
    const res = await fetch(BASE + url, { method, headers, body: options.body, credentials: 'same-origin' });
    const text = await res.text();
    let data = null;
    try { data = text ? JSON.parse(text) : null; } catch { data = { raw: text }; }
    if (!res.ok || (data && data.ok === false)) {
        const errMsg = (data && data.error) || `HTTP ${res.status}`;
        throw new ApiError(errMsg, res.status, data);
    }
    return data;
}

export const api = {
    // Settings
    getSettings:    () => request('GET', '/api/settings.php'),
    updateSettings: (patch) => request('POST', '/api/settings.php', { body: patch }),
    getAiConfig:    () => request('GET', '/api/settings_ai.php'),
    saveAiConfig:   (patch) => request('POST', '/api/settings_ai.php', { body: patch }),
    testAi:         () => request('GET', '/api/settings_ai.php?action=test'),

    // Tags
    tagCategories:  () => request('GET', '/api/tags.php?action=categories'),
    tagSearch:      (params) => request('GET', '/api/tags.php?action=search&' + new URLSearchParams(params)),
    tagLocalSearch: (q, limit = 15) => request('GET', `/api/tags.php?action=local_search&q=${encodeURIComponent(q)}&limit=${limit}`),
    tagPopular:     (categoryId, limit = 60) => request('GET', `/api/tags.php?action=popular&category=${categoryId}&limit=${limit}`),
    tagLookup:      (names) => request('GET', '/api/tags.php?action=lookup&names=' + encodeURIComponent(names.join(','))),
    tagDetail:      (name) => request('GET', '/api/tags.php?action=detail&name=' + encodeURIComponent(name)),

    // Prompts (presets)
    listPrompts:    (params = {}) => request('GET', '/api/prompts.php?' + new URLSearchParams(params)),
    getPrompt:      (id) => request('GET', '/api/prompts.php?id=' + id),
    createPrompt:   (data) => request('POST', '/api/prompts.php', { body: data }),
    updatePrompt:   (id, data) => request('PUT', '/api/prompts.php?id=' + id, { body: data }),
    deletePrompt:   (id) => request('DELETE', '/api/prompts.php?id=' + id),

    // Character presets
    listCharacterPresets: (params = {}) => request('GET', '/api/character_presets.php?' + new URLSearchParams(params)),
    createCharacterPreset: (data) => request('POST', '/api/character_presets.php', { body: data }),
    updateCharacterPreset: (id, data) => request('PUT', '/api/character_presets.php?id=' + id, { body: data }),
    deleteCharacterPreset: (id) => request('DELETE', '/api/character_presets.php?id=' + id),

    // Pose presets
    listPosePresets: (params = {}) => request('GET', '/api/pose_presets.php?' + new URLSearchParams(params)),
    createPosePreset: (data) => request('POST', '/api/pose_presets.php', { body: data }),
    updatePosePreset: (id, data) => request('PUT', '/api/pose_presets.php?id=' + id, { body: data }),
    deletePosePreset: (id) => request('DELETE', '/api/pose_presets.php?id=' + id),

    // Danbooru (在线标签 + 示例图)
    danbooruTag:  (q, limit = 100) => request('GET', `/api/danbooru.php?action=tag&q=${encodeURIComponent(q)}&limit=${limit}`),
    danbooruPost: (q, limit = 24) => request('GET', `/api/danbooru.php?action=post&q=${encodeURIComponent(q)}&limit=${limit}`),

    // Generation
    generate:       (data) => request('POST', '/api/generate.php', { body: data }),
    anlas:          () => request('GET', '/api/anlas.php'),

    // Gallery
    listGallery:    (params = {}) => request('GET', '/api/gallery.php?' + new URLSearchParams(params)),
    getGalleryItem: (id) => request('GET', '/api/gallery.php?id=' + id),
    galleryAction:  (action, id, value) => request('POST', '/api/gallery.php', { body: { action, id, value } }),
    deleteGallery:  (id, hard = false) => request('DELETE', `/api/gallery.php?id=${id}${hard ? '&hard=1' : ''}`),
    clearGallery:   () => request('POST', '/api/gallery.php', { body: { action: 'clear_all' } }),

    // Upload + import
    upload:         (file) => {
        const fd = new FormData();
        fd.append('file', file);
        return request('POST', '/api/upload.php', { body: fd });
    },
    importMeta:     (data) => request('POST', '/api/import_meta.php', { body: data }),

    // Cleanup
    cleanup:        (level = 'all', keepFavorites = true) => request('POST', '/api/cleanup.php', { body: { level, keep_favorites: keepFavorites ? 1 : 0 } }),

    // Backend control
    backendStatus:  () => request('GET', '/api/backend.php?action=status'),
    backendStart:   () => request('POST', '/api/backend.php?action=start'),
    backendStop:    () => request('POST', '/api/backend.php?action=stop'),

    // Proxy
    proxyStatus:    () => request('GET', '/api/proxy.php?action=status'),
    testProxy:      () => request('POST', '/api/proxy.php?action=test'),

    // 一键扩充标签库（仿 tags.novelai.dev）
    expandStatus:   () => request('GET',  '/api/admin/expand-tags.php?action=status'),
    expandStart:    (params) => request('POST', '/api/admin/expand-tags.php?action=start', params),
    expandStop:     () => request('POST', '/api/admin/expand-tags.php?action=stop'),
    // Import all Danbooru tags (one-time, ~30 万 tag, 后台 1-2h)
    importAllStatus: () => request('GET',  '/api/admin/import-all-tags.php?action=status'),
    importAllStart:  (params) => request('POST', '/api/admin/import-all-tags.php?action=start', params),
    importAllStop:   () => request('POST', '/api/admin/import-all-tags.php?action=stop'),
    clearProxy:     () => request('POST', '/api/proxy.php?action=clear'),

    // Prompt decomposer
    decompose:       (params) => request('POST', '/api/decompose.php?action=classify', { body: params }),
    decomposeSample: () => request('GET',  '/api/decompose.php?action=sample'),
    decomposeAdvise: (params) => request('POST', '/api/decompose.php?action=advise', { body: params }),
    testLocalTranslate: () => request('GET', '/api/decompose.php?action=test_translate'),
    lookupTag:       (q) => request('GET',  '/api/decompose.php?action=lookup&q=' + encodeURIComponent(q)),

    // Artist library
    artistList:        (params) => request('GET',  '/api/artists.php?action=list&' + new URLSearchParams(params || {})),
    artistDetail:      (id) => request('GET',  '/api/artists.php?action=detail&id=' + id),
    artistSearch:      (q) => request('GET',  '/api/artists.php?action=lookup&q=' + encodeURIComponent(q)),
    artistCreate:      (data) => request('POST', '/api/artists.php?action=create', { body: data }),
    artistUpdate:      (data) => request('POST', '/api/artists.php?action=update', { body: data }),
    artistDelete:      (id) => request('POST', '/api/artists.php?action=delete', { body: { id } }),
    artistAutocomplete: (data) => request('POST', '/api/artists.php?action=autocomplete', { body: data }),
    artistFetch:       (data) => request('POST', '/api/artists.php?action=fetch', { body: data }),
    artistCategories:  () => request('GET',  '/api/artists.php?action=categories'),
    artistDanbooruSearch: (q, limit = 24) => request('GET', `/api/artists.php?action=danbooru_search&q=${encodeURIComponent(q)}&limit=${limit}`),
    artistCategoryCreate: (data) => request('POST', '/api/artists.php?action=category_create', { body: data }),
    artistCategoryDelete: (id) => request('POST', '/api/artists.php?action=category_delete', { body: { id } }),

    // Artist presets
    presetList:        () => request('GET',  '/api/artist_presets.php?action=list'),
    presetDetail:      (id) => request('GET',  '/api/artist_presets.php?action=detail&id=' + id),
    presetCreate:      (data) => request('POST', '/api/artist_presets.php?action=create', { body: data }),
    presetUpdate:      (data) => request('POST', '/api/artist_presets.php?action=update', { body: data }),
    presetDelete:      (id) => request('POST', '/api/artist_presets.php?action=delete', { body: { id } }),
    presetUse:         (id) => request('POST', '/api/artist_presets.php?action=use', { body: { id } }),

    // AI advisor (DeepSeek)
    aiAnalyze:      (data) => request('POST', '/api/ai_analyze.php?action=analyze', { body: data }),
    aiTranslate:    (texts) => request('POST', '/api/ai_analyze.php?action=translate', { body: { texts } }),
    aiExpand:       (description, model) => request('POST', '/api/ai_analyze.php?action=expand', { body: { description, model } }),
    aiCompose:      (history, model) => request('POST', '/api/ai_analyze.php?action=compose', { body: { history, model: model || 'curated' } }),
};

export { ApiError };
