/**
 * NAI Studio - Artist Library (画师库 v2)
 *
 * v2 改动：默认从 Danbooru 作者库在线浏览（热门 + 模糊搜索 + 画风预览图）
 *  顶栏 source 切换：🌐 Danbooru 在线 / 📚 我的画师
 *  - 在线：直接调 Danbooru artists.json 拉，画师卡片显示英文 tag + 预览图 + 复制 + 收藏
 *  - 本地：从本地 artists 表读，自定义分类/笔记/画师串预设
 *
 * 保留旧功能：手动添加画师、画师串预设、编辑、删除
 */

import { api } from './api.js';
import { toast } from './toast.js';

let _els = {};
let _categories = [];
let _artists = [];          // 本地表
let _dbArtists = [];       // Danbooru 在线结果
let _currentSource = 'danbooru';  // 'danbooru' | 'local'
let _search = '';
let _editingArtistId = null;
let _editingPresetId = null;
let _searchDebounce = null;

function escapeHtml(s) {
    return (s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[c]));
}

function styleLabel(key) {
    return ({
        thick_anime: '厚涂二次元', soft_anime: '软萌二次元', realistic: '写实派',
        cinematic: '电影感', illustration: '插画风', dark: '黑暗系', classic: '经典派',
    })[key] || '';
}

function styleIcon(key) {
    return ({
        thick_anime: '🎨', soft_anime: '🌸', realistic: '📷', cinematic: '🎬',
        illustration: '✏️', dark: '🌑', classic: '🏛️',
    })[key] || '🏷';
}

// =================== Modal control ===================
function openLib() {
    _els.modal.classList.remove('hidden');
    // 默认走 Danbooru 在线
    if (_currentSource === 'danbooru') {
        loadDanbooruArtists(_search);
    } else {
        loadLocalArtists();
    }
    setTimeout(() => _els.searchInput.focus(), 100);
}
function closeLib() { _els.modal.classList.add('hidden'); }

async function loadDanbooruArtists(q) {
    try {
        _els.artistsList.innerHTML = '<div class="al-empty">正在从 Danbooru 加载…</div>';
        const r = await api.artistDanbooruSearch(q || '', 24);
        _dbArtists = r.rows || [];
        if (r.warning) toast(r.warning, { type: 'warning' });
        renderDbArtists();
    } catch (e) {
        toast('Danbooru 加载失败: ' + e.message, { type: 'error' });
        _els.artistsList.innerHTML = '<div class="al-empty">Danbooru 加载失败</div>';
    }
}

async function loadLocalArtists() {
    try {
        const [cats, allArtists] = await Promise.all([
            api.artistCategories(),
            api.artistList({}),
        ]);
        _categories = cats.rows || cats || [];
        _artists = allArtists.rows || allArtists || [];
        renderLocalArtists();
    } catch (e) {
        toast('加载失败: ' + e.message, { type: 'error' });
    }
}

function updateCount() {
    if (_els.catCountAll) _els.catCountAll.textContent = _artists.length;
}

// =================== Danbooru 在线模式 ===================
function renderDbArtists() {
    _els.artistsList.innerHTML = '';
    const list = _dbArtists;
    if (list.length === 0) {
        _els.artistsList.innerHTML = '<div class="al-empty">' + (_search ? 'Danbooru 没找到匹配画师' : '正在加载…') + '</div>';
        return;
    }
    for (const a of list) {
        const card = document.createElement('div');
        card.className = 'al-artist-card al-artist-card-danbooru';
        card.dataset.dbname = a.name;
        const cover = a.example_url
            ? `<div class="al-artist-cover" style="background-image:url('${escapeHtml(a.example_url)}')"></div>`
            : `<div class="al-artist-cover al-artist-cover-empty">🎨</div>`;
        const tagCount = a.tag_count ? formatCount(a.tag_count) : '0';
        card.innerHTML = `
            ${cover}
            <div class="al-artist-info">
                <div class="al-artist-name-en">${escapeHtml(a.name)}</div>
                <div class="al-artist-name-en-sub">${tagCount} posts${a.is_banned ? ' · ⛔ banned' : ''}${a.is_deleted ? ' · 🗑 deleted' : ''}</div>
                ${a.other_names && a.other_names.length ? `<div class="al-artist-other-names">${escapeHtml(a.other_names.slice(0, 3).join(' · '))}</div>` : ''}
            </div>
            <div class="al-artist-actions">
                <button class="al-act-btn" data-act="copy" data-name="${escapeHtml(a.name_noob)}" title="复制 NOOB 格式">📋N</button>
                <button class="al-act-btn" data-act="copy" data-name="${escapeHtml(a.name_nai)}" title="复制 NAI 格式">📋C</button>
                <button class="al-act-btn" data-act="save" data-name="${escapeHtml(a.name)}" data-nai="${escapeHtml(a.name_nai)}" data-noob="${escapeHtml(a.name_noob)}" title="收藏到我的画师库">⭐</button>
            </div>
        `;
        _els.artistsList.appendChild(card);
    }
}

function formatCount(n) {
    if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
    if (n >= 1000) return (n / 1000).toFixed(1) + 'k';
    return String(n);
}

// =================== 本地模式 ===================
function renderLocalArtists() {
    _els.artistsList.innerHTML = '';
    const list = _artists;
    if (list.length === 0) {
        _els.artistsList.innerHTML = '<div class="al-empty">我的画师库是空的。试试切到🌐 Danbooru 在线找画师收藏</div>';
        return;
    }
    for (const a of list) {
        const card = document.createElement('div');
        card.className = 'al-artist-card';
        card.dataset.id = a.id;
        const cover = a.example_image_path
            ? `<div class="al-artist-cover" style="background-image:url('${escapeHtml(a.example_image_path)}')"></div>`
            : `<div class="al-artist-cover al-artist-cover-empty">${styleIcon(a.style)}</div>`;
        const rank = a.post_count ? ` · ${a.post_count} posts` : '';
        const catNames = (a.category_names || '').split(',').filter(Boolean).join(' / ');
        card.innerHTML = `
            ${cover}
            <div class="al-artist-info">
                <div class="al-artist-name">${escapeHtml(a.name_cn || a.name_nai)}</div>
                <div class="al-artist-name-en">${escapeHtml(a.name_nai)}${rank}</div>
                <div class="al-artist-cats">${catNames ? '🏷 ' + escapeHtml(catNames) : ''}</div>
                ${a.notes ? `<div class="al-artist-notes">${escapeHtml(a.notes)}</div>` : ''}
            </div>
            <div class="al-artist-actions">
                <button class="al-act-btn" data-act="copy-noob" title="复制 NOOB">📋N</button>
                <button class="al-act-btn" data-act="copy-nai"  title="复制 NAI">📋C</button>
                <button class="al-act-btn" data-act="edit"      title="编辑">✏️</button>
                <button class="al-act-btn al-act-del" data-act="delete" title="删除">×</button>
            </div>
        `;
        _els.artistsList.appendChild(card);
    }
}

async function copyText(text, label) {
    try {
        await navigator.clipboard.writeText(text);
        toast(`已复制 ${label}: ${text}`, { type: 'success' });
    } catch {
        toast('复制失败', { type: 'error' });
    }
}

async function handleArtistClick(e) {
    const btn = e.target.closest('[data-act]');
    if (!btn) return;
    const card = btn.closest('.al-artist-card');
    const act = btn.dataset.act;

    // Danbooru 在线模式
    if (card.classList.contains('al-artist-card-danbooru')) {
        if (act === 'copy') {
            await copyText(btn.dataset.name, btn.dataset.name.startsWith('artist:') ? 'NOOB' : 'NAI');
        } else if (act === 'save') {
            // 收藏到我的画师库
            try {
                await api.artistCreate({
                    name_nai: btn.dataset.nai,
                    name_noob: btn.dataset.noob,
                    name_cn: '',
                    danbooru_link: 'https://danbooru.donmai.us/posts?tags=' + encodeURIComponent(btn.dataset.noob),
                    notes: '从 Danbooru 收藏',
                });
                toast(`⭐ 已收藏: ${btn.dataset.nai}`, { type: 'success' });
            } catch (e) {
                if (e.message?.includes('Duplicate')) {
                    toast('已在我的画师库', { type: 'info' });
                } else {
                    toast('收藏失败: ' + e.message, { type: 'error' });
                }
            }
        }
        return;
    }

    // 本地模式
    const id = parseInt(card.dataset.id);
    const a = _artists.find(x => x.id === id);
    if (!a) return;

    if (act === 'copy-noob') await copyText(a.name_noob || `artist:${a.name_nai}`, 'NOOB');
    else if (act === 'copy-nai') await copyText(a.name_nai, 'NAI');
    else if (act === 'edit') openArtistForm(a);
    else if (act === 'delete') {
        if (!confirm(`删除「${a.name_cn || a.name_nai}」？`)) return;
        try {
            await api.artistDelete(id);
            toast('已删除', { type: 'success' });
            await loadLocalArtists();
        } catch (e) { toast('删除失败: ' + e.message, { type: 'error' }); }
    } else if (act === 'fetch') {
        await fetchFromDanbooru(id);
    }
}

async function fetchFromDanbooru(id) {
    toast('正在抓取 Danbooru 数据...', { type: 'info' });
    try {
        const r = await api.artistFetch({ id });
        const d = r.data || {};
        toast(`抓取完成：${d.post_count || '?'} posts${d.example_image ? ' + 预览图' : ''}`, { type: 'success' });
        await loadLocalArtists();
    } catch (e) {
        toast('抓取失败: ' + e.message, { type: 'error' });
    }
}

// =================== Artist Form ===================
function openArtistForm(artist) {
    _editingArtistId = artist ? artist.id : null;
    _els.artistFormTitle.textContent = artist ? '编辑画师' : '添加画师';
    _els.afNameNai.value = artist ? (artist.name_nai || '') : '';
    _els.afNameNoob.value = artist ? (artist.name_noob || '') : '';
    _els.afNameCn.value = artist ? (artist.name_cn || '') : '';
    _els.afStyle.value = artist ? (artist.style || '') : '';
    _els.afDanbooruLink.value = artist ? (artist.danbooru_link || '') : '';
    _els.afNotes.value = artist ? (artist.notes || '') : '';
    // 分类多选
    const sel = _els.afCategories;
    sel.innerHTML = '';
    for (const c of _categories) {
        const opt = document.createElement('option');
        opt.value = c.id;
        opt.textContent = c.name;
        if (artist && (artist.category_ids || []).includes(c.id)) opt.selected = true;
        sel.appendChild(opt);
    }
    _els.artistFormModal.classList.remove('hidden');
    setTimeout(() => _els.afNameNai.focus(), 100);
}

function closeArtistForm() {
    _els.artistFormModal.classList.add('hidden');
    _editingArtistId = null;
}

async function autoComplete() {
    const data = {
        name_nai: _els.afNameNai.value.trim(),
        name_noob: _els.afNameNoob.value.trim(),
        danbooru_link: _els.afDanbooruLink.value.trim(),
    };
    try {
        const r = await api.artistAutocomplete(data);
        if (r.name_noob) _els.afNameNoob.value = r.name_noob;
        if (r.name_nai) _els.afNameNai.value = r.name_nai;
        if (r.danbooru_link) _els.afDanbooruLink.value = r.danbooru_link;
        toast('已自动补全', { type: 'success' });
    } catch (e) {
        toast('补全失败: ' + e.message, { type: 'error' });
    }
}

async function saveArtist(e) {
    e.preventDefault();
    const data = {
        name_nai: _els.afNameNai.value.trim(),
        name_noob: _els.afNameNoob.value.trim(),
        name_cn: _els.afNameCn.value.trim(),
        style: _els.afStyle.value,
        danbooru_link: _els.afDanbooruLink.value.trim(),
        notes: _els.afNotes.value.trim(),
        category_ids: Array.from(_els.afCategories.selectedOptions).map(o => parseInt(o.value)),
    };
    if (!data.name_nai && !data.name_noob) {
        toast('NAI 或 NOOB 至少填一个', { type: 'warning' });
        return;
    }
    try {
        if (_editingArtistId) {
            data.id = _editingArtistId;
            await api.artistUpdate(data);
        } else {
            await api.artistCreate(data);
        }
        toast('已保存', { type: 'success' });
        closeArtistForm();
        await loadLocalArtists();
    } catch (e) {
        toast('保存失败: ' + e.message, { type: 'error' });
    }
}

// =================== Presets ===================
async function loadPresets() {
    try {
        const r = await api.presetList();
        const list = r.rows || [];
        _els.presetsList.innerHTML = '';
        if (list.length === 0) {
            _els.presetsList.innerHTML = '<div class="al-empty">还没画师串，点 + 新建</div>';
            return;
        }
        for (const p of list) {
            const card = document.createElement('div');
            card.className = 'al-preset-card';
            card.innerHTML = `
                <div class="al-preset-info">
                    <div class="al-preset-name">${escapeHtml(p.name)} ${p.is_favorite ? '⭐' : ''}</div>
                    <div class="al-preset-desc">${escapeHtml(p.description || '')}</div>
                    <div class="al-preset-meta">${p.artist_count} 个画师 · 使用 ${p.use_count} 次</div>
                </div>
                <div class="al-preset-actions">
                    <button class="al-act-btn" data-act="use" data-id="${p.id}" title="使用">⚡</button>
                    <button class="al-act-btn" data-act="copy-noob" data-id="${p.id}" title="复制 NOOB">📋N</button>
                    <button class="al-act-btn" data-act="copy-nai" data-id="${p.id}" title="复制 NAI">📋C</button>
                    <button class="al-act-btn" data-act="edit" data-id="${p.id}" title="编辑">✏️</button>
                    <button class="al-act-btn al-act-del" data-act="delete" data-id="${p.id}" title="删除">×</button>
                </div>
            `;
            _els.presetsList.appendChild(card);
        }
    } catch (e) {
        toast('加载预设失败: ' + e.message, { type: 'error' });
    }
}

async function handlePresetClick(e) {
    const btn = e.target.closest('[data-act]');
    if (!btn) return;
    const id = parseInt(btn.dataset.id);
    const act = btn.dataset.act;
    if (act === 'use') {
        try {
            const r = await api.presetUse(id);
            const text = r.nai_text || r.noob_text;
            await navigator.clipboard.writeText(text);
            toast(`已复制画师串: ${text}`, { type: 'success' });
        } catch (e) { toast('失败: ' + e.message, { type: 'error' }); }
    } else if (act === 'copy-noob') {
        const r = await api.presetDetail(id);
        const p = r.row;
        const text = p.noob_text || (p.items || []).map(i => 'artist:' + (i.name_nai || '')).join(', ');
        await navigator.clipboard.writeText(text);
        toast(`已复制 NOOB: ${text}`, { type: 'success' });
    } else if (act === 'copy-nai') {
        const r = await api.presetDetail(id);
        const p = r.row;
        const text = p.nai_text || (p.items || []).map(i => i.name_nai || '').join(', ');
        await navigator.clipboard.writeText(text);
        toast(`已复制 NAI: ${text}`, { type: 'success' });
    } else if (act === 'edit') {
        openPresetForm(id);
    } else if (act === 'delete') {
        if (!confirm('删除这个画师串？')) return;
        try {
            await api.presetDelete(id);
            toast('已删除', { type: 'success' });
            await loadPresets();
        } catch (e) { toast('失败: ' + e.message, { type: 'error' }); }
    }
}

async function openPresetForm(presetId) {
    _editingPresetId = presetId || null;
    _els.presetFormTitle.textContent = presetId ? '编辑画师串' : '新建画师串';

    // 填充可选画师
    const sel = _els.pfArtists;
    sel.innerHTML = '';
    for (const a of _artists) {
        const opt = document.createElement('option');
        opt.value = a.id;
        opt.textContent = `${a.name_cn || a.name_nai}  (${a.name_nai})`;
        sel.appendChild(opt);
    }

    if (presetId) {
        try {
            const r = await api.presetDetail(presetId);
            const p = r.row;
            _els.pfName.value = p.name || '';
            _els.pfDesc.value = p.description || '';
            const ids = (p.items || []).map(i => i.artist_id);
            Array.from(sel.options).forEach(o => { if (ids.includes(parseInt(o.value))) o.selected = true; });
            updatePresetPreview();
        } catch (e) { toast('加载失败: ' + e.message, { type: 'error' }); }
    } else {
        _els.pfName.value = '';
        _els.pfDesc.value = '';
        _els.pfNaiPreview.value = '';
    }
    _els.presetFormModal.classList.remove('hidden');
    setTimeout(() => _els.pfName.focus(), 100);
}

function closePresetForm() {
    _els.presetFormModal.classList.add('hidden');
    _editingPresetId = null;
}

function updatePresetPreview() {
    const ids = Array.from(_els.pfArtists.selectedOptions).map(o => parseInt(o.value));
    const names = ids.map(id => {
        const a = _artists.find(x => x.id === id);
        return a ? a.name_nai : '';
    }).filter(Boolean);
    _els.pfNaiPreview.value = names.join(', ');
}

async function savePreset(e) {
    e.preventDefault();
    const ids = Array.from(_els.pfArtists.selectedOptions).map(o => parseInt(o.value));
    if (ids.length === 0) { toast('至少选一个画师', { type: 'warning' }); return; }
    const names = ids.map(id => {
        const a = _artists.find(x => x.id === id);
        return a ? a.name_nai : '';
    }).filter(Boolean);
    const data = {
        name: _els.pfName.value.trim(),
        description: _els.pfDesc.value.trim(),
        artist_ids: ids,
        nai_text: names.join(', '),
    };
    if (!data.name) { toast('名称必填', { type: 'warning' }); return; }
    try {
        if (_editingPresetId) {
            data.id = _editingPresetId;
            await api.presetUpdate(data);
        } else {
            await api.presetCreate(data);
        }
        toast('已保存', { type: 'success' });
        closePresetForm();
        await loadPresets();
    } catch (e) { toast('保存失败: ' + e.message, { type: 'error' }); }
}

// =================== Tabs ===================
function switchTab(name) {
    document.querySelectorAll('#artistLibModal .al-tab').forEach(b => b.classList.toggle('active', b.dataset.alTab === name));
    _els.artistsTab.classList.toggle('hidden', name !== 'artists');
    _els.presetsTab.classList.toggle('hidden', name !== 'presets');
    if (name === 'presets') loadPresets();
}

// =================== Init ===================
export function initArtistLibrary() {
    _els = {
        modal:           document.getElementById('artistLibModal'),
        artistsList:     document.getElementById('alArtistsList'),
        presetsList:     document.getElementById('alPresetsList'),
        searchInput:     document.getElementById('alSearchInput'),
        refreshBtn:      document.getElementById('alRefreshBtn'),
        addBtn:          document.getElementById('alAddBtn'),
        artistsTab:      document.getElementById('alArtistsTab'),
        presetsTab:      document.getElementById('alPresetsTab'),
        newPresetBtn:    document.getElementById('alNewPresetBtn'),
        // artist form
        artistFormModal: document.getElementById('artistFormModal'),
        artistFormTitle: document.getElementById('artistFormTitle'),
        afNameNai:       document.getElementById('afNameNai'),
        afNameNoob:      document.getElementById('afNameNoob'),
        afNameCn:        document.getElementById('afNameCn'),
        afStyle:         document.getElementById('afStyle'),
        afDanbooruLink:  document.getElementById('afDanbooruLink'),
        afCategories:    document.getElementById('afCategories'),
        afNotes:         document.getElementById('afNotes'),
        afAutoBtn:       document.getElementById('afAutoBtn'),
        afFetchBtn:      document.getElementById('afFetchBtn'),
        afCancelBtn:     document.getElementById('afCancelBtn'),
        afSaveBtn:       document.getElementById('afSaveBtn'),
        // preset form
        presetFormModal: document.getElementById('presetFormModal'),
        presetFormTitle: document.getElementById('presetFormTitle'),
        pfName:          document.getElementById('pfName'),
        pfDesc:          document.getElementById('pfDesc'),
        pfArtists:       document.getElementById('pfArtists'),
        pfNaiPreview:    document.getElementById('pfNaiPreview'),
        pfCancelBtn:     document.getElementById('pfCancelBtn'),
        pfSaveBtn:       document.getElementById('pfSaveBtn'),
    };
    if (!_els.modal) return;

    // 顶栏按钮
    document.getElementById('openArtistLibBtn')?.addEventListener('click', openLib);

    // 弹窗内
    document.getElementById('closeArtistLibBtn')?.addEventListener('click', closeLib);
    _els.modal.addEventListener('click', e => { if (e.target === _els.modal) closeLib(); });
    _els.refreshBtn?.addEventListener('click', () => {
        if (_currentSource === 'danbooru') loadDanbooruArtists(_search);
        else loadLocalArtists();
    });
    _els.addBtn?.addEventListener('click', () => openArtistForm(null));
    _els.searchInput?.addEventListener('input', e => {
        _search = e.target.value.trim();
        clearTimeout(_searchDebounce);
        if (_currentSource === 'danbooru') {
            _searchDebounce = setTimeout(() => loadDanbooruArtists(_search), 350);
        } else {
            renderLocalArtists();
        }
    });

    // Source toggle: Danbooru / 本地
    document.querySelectorAll('#artistLibModal .al-source-toggle button').forEach(b => {
        b.addEventListener('click', () => {
            const src = b.dataset.alSource;
            if (src === _currentSource) return;
            _currentSource = src;
            document.querySelectorAll('#artistLibModal .al-source-toggle button').forEach(x => x.classList.toggle('active', x.dataset.alSource === src));
            if (src === 'danbooru') loadDanbooruArtists(_search);
            else loadLocalArtists();
        });
    });

    // 画师卡片事件委托
    _els.artistsList.addEventListener('click', handleArtistClick);
    _els.presetsList.addEventListener('click', handlePresetClick);

    // Tabs
    document.querySelectorAll('#artistLibModal .al-tab').forEach(b => {
        b.addEventListener('click', () => switchTab(b.dataset.alTab));
    });

    // Artist form
    _els.afCancelBtn?.addEventListener('click', closeArtistForm);
    document.getElementById('closeArtistFormBtn')?.addEventListener('click', closeArtistForm);
    _els.artistFormModal?.addEventListener('click', e => { if (e.target === _els.artistFormModal) closeArtistForm(); });
    _els.afAutoBtn?.addEventListener('click', autoComplete);
    _els.afFetchBtn?.addEventListener('click', async () => {
        const name = _els.afNameNai.value.trim();
        if (!name) { toast('先填 NAI 名', { type: 'warning' }); return; }
        toast('正在抓取 Danbooru...', { type: 'info' });
        try {
            const r = await api.artistFetch({ name_nai: name });
            const d = r.data || {};
            toast(`抓取完成：${d.post_count || '?'} posts`, { type: 'success' });
            // 不立即保存到 DB，等用户点保存
        } catch (e) { toast('抓取失败: ' + e.message, { type: 'error' }); }
    });
    document.getElementById('artistForm')?.addEventListener('submit', saveArtist);

    // Preset form
    _els.newPresetBtn?.addEventListener('click', () => openPresetForm(null));
    _els.pfCancelBtn?.addEventListener('click', closePresetForm);
    document.getElementById('closePresetFormBtn')?.addEventListener('click', closePresetForm);
    _els.presetFormModal?.addEventListener('click', e => { if (e.target === _els.presetFormModal) closePresetForm(); });
    _els.pfArtists?.addEventListener('change', updatePresetPreview);
    document.getElementById('presetForm')?.addEventListener('submit', savePreset);
}
