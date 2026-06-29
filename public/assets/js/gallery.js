/**
 * NAI Studio - Gallery history (right side, vertical scroll)
 * Includes: thumbnail grid, action menu, one-click download, clear all
 */

import { api } from './api.js';
import { getState, setState } from './state.js';
import { toast } from './toast.js';

let _page = 1;
const PER_PAGE = 30;
let _loading = false;
let _hasMore = true;
let _filters = { favorite: false };
let _activeId = null;
let _allItems = [];

async function loadPage(reset = false) {
    if (_loading) return;
    _loading = true;
    try {
        const params = { page: _page, per_page: PER_PAGE, ..._filters };
        const r = await api.listGallery(params);
        if (reset) _allItems = [];
        _allItems.push(...(r.rows || []));
        _hasMore = _page < r.pages;
        setState({ gallery: _allItems, galleryTotal: r.total, galleryPage: r.page });
        render();
        updateLoadMore();
    } catch (e) {
        toast('加载画廊失败: ' + e.message, { type: 'error' });
    } finally {
        _loading = false;
    }
}

function updateLoadMore() {
    const el = document.getElementById('galleryLoadMore');
    if (!el) return;
    el.classList.toggle('hidden', !_hasMore);
    const c = document.getElementById('galleryCount');
    if (c) c.textContent = getState().galleryTotal || 0;
}

function render() {
    const grid = document.getElementById('galleryGrid');
    if (!grid) return;
    grid.innerHTML = '';
    if (_allItems.length === 0) {
        const empty = document.createElement('div');
        empty.style.cssText = 'grid-column: 1 / -1; text-align: center; padding: 24px; color: var(--text-muted); font-size: 11px;';
        empty.textContent = '还没有生成图片';
        grid.appendChild(empty);
        return;
    }
    for (const item of _allItems) grid.appendChild(renderItem(item));
}

function renderItem(item) {
    const div = document.createElement('div');
    div.className = 'gallery-item';
    div.dataset.id = item.id;
    if (item.id === _activeId) div.classList.add('active');
    const img = document.createElement('img');
    img.loading = 'lazy';
    // 后端返回 /storage/... 是相对根路径的；当前页面在 /nai-studio/ 子目录下，
    // img.src 会解析成 http://localhost/storage/...（找不到）。
    // 用绝对路径解决：基于当前 location.pathname 的目录
    const baseDir = location.pathname.replace(/\/[^/]*$/, '/');  // /nai-studio/
    const src = item.thumbnail_path || item.image_path;
    img.src = src.startsWith('/') ? baseDir + src.slice(1) : baseDir + src;
    img.alt = '生成图';
    div.appendChild(img);
    if (item.is_favorite) {
        const star = document.createElement('span');
        star.className = 'fav';
        star.textContent = '★';
        div.appendChild(star);
    }
    const del = document.createElement('button');
    del.className = 'del';
    del.innerHTML = '×';
    del.title = '删除';
    del.addEventListener('click', async (e) => {
        e.stopPropagation();
        if (!confirm('删除这张图片？')) return;
        try {
            await api.deleteGallery(item.id, true);
            _allItems = _allItems.filter(x => x.id !== item.id);
            render();
            updateLoadMore();
            toast('已删除', { type: 'success' });
        } catch (err) {
            toast('删除失败: ' + err.message, { type: 'error' });
        }
    });
    div.appendChild(del);
    div.addEventListener('click', () => viewItem(item));
    return div;
}

async function viewItem(item) {
    try {
        const r = await api.getGalleryItem(item.id);
        const full = r.item;
        _activeId = full.id;
        setState({ activeImage: full });
        showMainImage(full);
        render();
    } catch (e) {
        toast('加载图片失败: ' + e.message, { type: 'error' });
    }
}

export function showMainImage(item) {
    const main = document.getElementById('galleryMainImage');
    const mainImg = document.getElementById('galleryMainImg');
    const mainPrompt = document.getElementById('galleryMainPrompt');
    const mainModel = document.getElementById('galleryMainModel');
    const mainSeed = document.getElementById('galleryMainSeed');
    const mainSize = document.getElementById('galleryMainSize');
    const empty = document.getElementById('emptyGalleryMessage');
    if (!main) return;
    const baseDir = location.pathname.replace(/\/[^/]*$/, '/');
    const src = item.image_path;
    mainImg.src = src.startsWith('/') ? baseDir + src.slice(1) : baseDir + src;
    mainPrompt.textContent = (item.prompt || '').slice(0, 200);
    if (mainModel) mainModel.textContent = '🎨 ' + (item.model || '?');
    if (mainSeed)  mainSeed.textContent  = '🌱 ' + (item.seed || '?');
    if (mainSize)  mainSize.textContent  = '📐 ' + (item.width || '?') + '×' + (item.height || '?');
    main.classList.remove('hidden');
    empty.classList.add('hidden');
    // Update favorite button
    const favBtn = document.getElementById('mainFavoriteBtn');
    if (favBtn) {
        favBtn.style.color = item.is_favorite ? '#fbbf24' : 'white';
    }
}

async function downloadImage(item) {
    if (!item || !item.image_path) { toast('没有可下载的图片', { type: 'warning' }); return; }
    const baseDir = location.pathname.replace(/\/[^/]*$/, '/');
    const src = item.image_path;
    const fullSrc = (src.startsWith('/') ? baseDir + src.slice(1) : baseDir + src) + '?download=1';
    const filename = `nai_${item.id}_seed${item.seed || 'x'}_${item.width || 0}x${item.height || 0}.png`;

    try {
        // 走 fetch + blob, 避免 <a target=_blank> 跨源失效
        const resp = await fetch(fullSrc);
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        const blob = await resp.blob();
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(() => URL.revokeObjectURL(url), 1000);
        toast('开始下载', { type: 'success' });
    } catch (e) {
        toast('下载失败: ' + e.message, { type: 'error' });
    }
}

async function toggleFavorite(item) {
    try {
        await api.galleryAction('favorite', item.id, !item.is_favorite);
        item.is_favorite = item.is_favorite ? 0 : 1;
        render();
        if (item.id === _activeId) {
            const favBtn = document.getElementById('mainFavoriteBtn');
            if (favBtn) favBtn.style.color = item.is_favorite ? '#fbbf24' : 'white';
        }
        toast(item.is_favorite ? '已收藏' : '已取消收藏', { type: 'success' });
    } catch (e) {
        toast('操作失败: ' + e.message, { type: 'error' });
    }
}

function applyPromptToForm(item) {
    const s = getState();
    s.prompt = item.prompt || '';
    s.negativePrompt = item.negative_prompt || '';
    s.model = item.model;
    s.sampler = item.sampler;
    s.steps = item.steps;
    s.scale = parseFloat(item.scale);
    s.cfgRescale = parseFloat(item.cfg_rescale);
    s.noiseSchedule = item.noise_schedule;
    s.size = `${item.width}x${item.height}`;
    s.seed = item.seed;
    ['promptInput','negativeInput','stepsInput','scaleInput','seedInput'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.dispatchEvent(new Event('input', { bubbles: true }));
    });
    document.getElementById('samplerSelect')?.dispatchEvent(new Event('change', { bubbles: true }));
    document.getElementById('noiseScheduleSelect')?.dispatchEvent(new Event('change', { bubbles: true }));
    toast('已应用到表单', { type: 'success' });
}

function applyToDirector(item) {
    if (!item.image_path) return;
    const img = new Image();
    img.crossOrigin = 'anonymous';
    img.onload = () => {
        const c = document.createElement('canvas');
        c.width = img.naturalWidth; c.height = img.naturalHeight;
        c.getContext('2d').drawImage(img, 0, 0);
        const dataURL = c.toDataURL('image/png');
        window.dispatchEvent(new CustomEvent('nai:director-set-source', { detail: { dataURL, path: item.image_path } }));
    };
    img.src = item.image_path;
    document.getElementById('modeSwitchLabel').textContent = '生图';
    document.getElementById('openDirectorBtn').click();
    toast('已发送到 Director', { type: 'success' });
}

async function copyToClipboard(text) {
    try { await navigator.clipboard.writeText(text); toast('已复制', { type: 'success' }); }
    catch { toast('复制失败', { type: 'error' }); }
}

async function clearAllHistory() {
    if (!confirm('清空全部历史？此操作不可撤销。')) return;
    if (!confirm('再次确认：真的要清空吗？')) return;
    try {
        const r = await api.clearGallery();
        _allItems = [];
        _page = 1;
        _hasMore = false;
        render();
        updateLoadMore();
        document.getElementById('galleryMainImage')?.classList.add('hidden');
        document.getElementById('emptyGalleryMessage')?.classList.remove('hidden');
        toast(`已清空：${r.deleted} 张`, { type: 'success' });
    } catch (e) {
        toast('清空失败: ' + e.message, { type: 'error' });
    }
}

let _menu = null;
function showContextMenu(x, y, item) {
    hideContextMenu();
    _menu = document.getElementById('galleryActionMenu');
    if (!_menu) return;
    _menu.classList.remove('hidden');
    _menu.style.left = `${x}px`;
    _menu.style.top = `${y}px`;
    _menu._item = item;
    setTimeout(() => document.addEventListener('click', hideContextMenu, { once: true }), 0);
}
function hideContextMenu() {
    _menu?.classList.add('hidden');
}

export function initGallery() {
    // Sidebar toggle
    document.getElementById('galleryHistoryToggle')?.addEventListener('click', () => {
        document.getElementById('galleryHistorySidebar')?.classList.toggle('collapsed');
    });
    document.getElementById('galleryLoadMoreBtn')?.addEventListener('click', () => {
        _page++;
        loadPage();
    });

    // FAB buttons
    const getActive = () => getState().activeImage;
    document.getElementById('mainDownloadBtn')?.addEventListener('click', () => downloadImage(getActive()));
    document.getElementById('mainApplyBtn')?.addEventListener('click', () => { const it = getActive(); if (it) applyPromptToForm(it); });
    document.getElementById('mainDirectorBtn')?.addEventListener('click', () => { const it = getActive(); if (it) applyToDirector(it); });
    document.getElementById('mainCopyPromptBtn')?.addEventListener('click', () => { const it = getActive(); if (it) copyToClipboard(it.prompt || ''); });
    document.getElementById('mainFavoriteBtn')?.addEventListener('click', () => { const it = getActive(); if (it) toggleFavorite(it); });
    document.getElementById('mainDeleteBtn')?.addEventListener('click', async () => {
        const it = getActive();
        if (!it) return;
        if (!confirm('删除这张图片？')) return;
        try {
            await api.deleteGallery(it.id, true);
            _allItems = _allItems.filter(x => x.id !== it.id);
            render();
            updateLoadMore();
            document.getElementById('galleryMainImage')?.classList.add('hidden');
            document.getElementById('emptyGalleryMessage')?.classList.remove('hidden');
            toast('已删除', { type: 'success' });
        } catch (e) { toast('删除失败: ' + e.message, { type: 'error' }); }
    });

    // History sidebar footer
    document.getElementById('galleryClearBtn')?.addEventListener('click', clearAllHistory);
    document.getElementById('galleryRefreshBtn')?.addEventListener('click', () => { _page = 1; _allItems = []; loadPage(true); });
    document.getElementById('galleryZipBtn')?.addEventListener('click', downloadZip);
    document.getElementById('galleryFilterFavBtn')?.addEventListener('click', () => {
        _filters.favorite = !_filters.favorite;
        document.getElementById('galleryFilterFavBtn')?.classList.toggle('active', _filters.favorite);
        _page = 1; _allItems = []; loadPage(true);
    });

    // Context menu on right-click
    document.getElementById('galleryMainImage')?.addEventListener('contextmenu', (e) => {
        e.preventDefault();
        const item = getState().activeImage;
        if (item) showContextMenu(e.clientX, e.clientY, item);
    });

    // Context menu actions
    document.getElementById('galleryActionMenu')?.addEventListener('click', (e) => {
        const btn = e.target.closest('button[data-gallery-action]');
        if (!btn) return;
        const action = btn.dataset.galleryAction;
        const item = _menu?._item;
        hideContextMenu();
        if (!item) return;
        switch (action) {
            case 'download':  downloadImage(item); break;
            case 'favorite':  toggleFavorite(item); break;
            case 'prompt':    applyPromptToForm(item); break;
            case 'director':  applyToDirector(item); break;
            case 'copy-prompt': copyToClipboard(item.prompt || ''); break;
            case 'copy-seed':   copyToClipboard(String(item.seed || '')); break;
            case 'delete':
                if (confirm('删除这张图片？')) {
                    api.deleteGallery(item.id, true).then(() => {
                        _allItems = _allItems.filter(x => x.id !== item.id);
                        render();
                        updateLoadMore();
                        document.getElementById('galleryMainImage')?.classList.add('hidden');
                        document.getElementById('emptyGalleryMessage')?.classList.remove('hidden');
                    });
                }
                break;
        }
    });

    // Keyboard shortcut: Ctrl+D to download
    document.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && e.key === 'd' && getState().activeImage) {
            e.preventDefault();
            downloadImage(getState().activeImage);
        }
    });

    // Initial load
    loadPage(true);
}

export function reloadGallery() {
    _page = 1;
    _allItems = [];
    loadPage(true);
}

/**
 * 一键打包下载：按当前筛选条件下载 zip
 * - 当前是"只看收藏" → 只下载收藏
 * - 否则下载全部历史
 */
async function downloadZip() {
    const onlyFav = !!_filters.favorite;
    const count = _filters.favorite
        ? _allItems.filter(i => i.is_favorite).length
        : _allItems.length;
    if (count === 0) {
        toast('当前没有可打包的图片', { type: 'warning' });
        return;
    }
    const scope = onlyFav ? '收藏的' : '全部历史的';
    if (!confirm(`打包下载 ${scope} ${count} 张图为 zip？\n\n大文件可能需要几十秒，后端流式输出。\n下载的 zip 内含 manifest.json（完整 prompt / seed / 参数）。`)) return;
    const params = new URLSearchParams({ action: 'zip' });
    if (onlyFav) params.set('favorite', '1');
    const url = 'api/gallery.php?' + params.toString();
    toast('开始打包...请等待浏览器下载', { type: 'info', duration: 3000 });
    try {
        // 直接用 <a download> 触发，浏览器自己处理流
        const a = document.createElement('a');
        a.href = url;
        a.rel = 'noopener';
        document.body.appendChild(a);
        a.click();
        setTimeout(() => a.remove(), 1000);
    } catch (e) {
        toast('打包失败: ' + e.message, { type: 'error' });
    }
}
