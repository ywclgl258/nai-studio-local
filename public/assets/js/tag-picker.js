/**
 * NAI Studio - Tag Picker v4（购物车模式）
 *
 * 行为：
 *   - 输入时立即查本地 danbooru_tag_cache（cn_name + name），下拉显示
 *   - 350ms debounce 后调 Danbooru API 拉更多
 *   - 本地 + 在线合并去重，本地排前
 *   - 点击标签卡 = 加入购物车（toggle，再点取消）
 *   - 购物车行有 ✕ 删除按钮
 *   - "🛒 结算复制" 按钮：拼接 cart 里所有 tag（逗号分隔）→ 复制到剪贴板
 *   - 左侧 sidebar 按类别筛选（全部 / 本地 / Danbooru / 通用 / 画师 / 角色 / 版权 / 元）
 *   - 中文自动翻译
 */

import { api } from './api.js';
import { toast } from './toast.js';

const _state = {
    open: false,
    query: '',
    tags: [],              // 合并后：本地 + Danbooru
    localTags: [],
    onlineTags: [],
    fromCn: null,
    toEn: null,
    translateSource: null,
    loading: false,
    localLoading: false,
    cart: [],              // [{ name, cn_name, category }]
    activeCat: 'all',      // 'all' | 'local' | 'online' | '0' | '1' | '3' | '4' | '5'

    // ===== 本地缓存 tab 状态 =====
    activeTab: 'search',       // 'search' | 'local'
    localTagsAll: [],          // 已加载的本地缓存 tag 列表
    localPage: 0,              // 当前加载页
    localPerPage: 60,
    localTotal: 0,             // 服务端返回的总数
    localHasMore: false,
    localLoadingMore: false,   // 防止重复触发
    localFilters: { category: '', has_image: '', sort: 'popular', q: '' },
};
let _els = {};

const DB_CAT_BADGE = { 0: '通用', 1: '画师', 3: '版权', 4: '角色', 5: '元' };
const DB_CAT_ICON  = { 0: '🏷', 1: '🎨', 3: '©', 4: '👤', 5: '⚙' };

function debounce(fn, ms) {
    let t;
    return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
}

function formatCount(n) {
    if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
    if (n >= 1000) return (n / 1000).toFixed(1) + 'k';
    return String(n);
}

function escapeHtml(s) {
    return (s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[c]));
}

function showLoading(show, text) {
    if (show) {
        if (_els.body.querySelector('.tag-picker-loading')) return;
        const el = document.createElement('div');
        el.className = 'tag-picker-loading';
        el.textContent = text || '加载中…';
        _els.body.appendChild(el);
    } else {
        _els.body.querySelectorAll('.tag-picker-loading').forEach(el => el.remove());
    }
}

function setTranslateBar(fromCn, toEn, source) {
    const bar = _els.translateBar;
    if (!bar) return;
    if (!fromCn || !toEn) { bar.classList.add('hidden'); return; }
    const sourceLabel = ({
        builtin: '本地字典', memory: '本地缓存', mymemory: 'MyMemory',
        libretranslate: 'LibreTranslate', google_unofficial: 'Google', tagdict: '本地字典',
    })[source] || source;
    bar.querySelector('.tpt-text').innerHTML =
        `已翻译 <code>${escapeHtml(fromCn)}</code> → <code>${escapeHtml(toEn)}</code> <span class="tpt-source">via ${sourceLabel}</span>`;
    bar.classList.remove('hidden');
}

// =================== 复制到剪贴板 ===================
async function copyText(text) {
    try {
        await navigator.clipboard.writeText(text);
        return true;
    } catch (e) {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        try {
            document.execCommand('copy');
            return true;
        } catch {
            return false;
        } finally {
            document.body.removeChild(ta);
        }
    }
}

// =================== 搜索：本地 + 在线 ===================
async function searchLocal() {
    const q = _state.query.trim();
    if (!q) {
        _state.localTags = [];
        renderDropdown();
        return;
    }
    _state.localLoading = true;
    try {
        const r = await api.tagLocalSearch(q, 15);
        _state.localTags = r.rows || [];
    } catch {
        _state.localTags = [];
    } finally {
        _state.localLoading = false;
        renderDropdown();
        mergeAndRender();
    }
}

async function searchOnline() {
    const q = _state.query.trim();
    if (!q) {
        _state.onlineTags = [];
        _state.fromCn = null;
        _state.toEn = null;
        _state.translateSource = null;
        setTranslateBar(null, null, null);
        mergeAndRender();
        return;
    }
    _state.loading = true;
    showLoading(true, '正在搜索 Danbooru…');
    try {
        const r = await api.danbooruTag(q, 100);
        _state.onlineTags = r.rows || [];
        _state.fromCn = r.from_cn || null;
        _state.toEn = r.to_en || null;
        _state.translateSource = r.translate_source || null;
        if (_state.fromCn && _state.toEn) {
            setTranslateBar(_state.fromCn, _state.toEn, _state.translateSource);
        } else {
            setTranslateBar(null, null, null);
        }
        if (r.warning) toast(r.warning, { type: 'warning' });
    } catch (e) {
        toast('搜索失败: ' + e.message, { type: 'error' });
        _state.onlineTags = [];
    } finally {
        _state.loading = false;
        showLoading(false);
        mergeAndRender();
    }
}

function mergeAndRender() {
    const seen = new Set();
    const merged = [];
    for (const t of _state.localTags) {
        if (seen.has(t.name)) continue;
        seen.add(t.name);
        merged.push({ ...t, _src: 'local' });
    }
    for (const t of _state.onlineTags) {
        if (seen.has(t.name)) continue;
        seen.add(t.name);
        merged.push({ ...t, _src: 'danbooru' });
    }
    _state.tags = merged;
    updateCategoryCounts();
    renderTags();
}

const debouncedSearchLocal = debounce(searchLocal, 150);
const debouncedSearchOnline = debounce(searchOnline, 350);

function onInput() {
    _state.query = _els.search.value;
    debouncedSearchLocal();
    debouncedSearchOnline();
    if (!_state.query) {
        _state.localTags = [];
        _state.onlineTags = [];
        _state.fromCn = null;
        _state.toEn = null;
        _state.translateSource = null;
        setTranslateBar(null, null, null);
        renderDropdown();
        renderTags();
    }
}

/**
 * 静态图片加载（仿 wfjsw/tags.novelai.dev）
 *
 * 策略：图片已由后端预生成存到 /storage/tag-previews/<hash>/<name>.jpg
 *       前端用纯静态 <img loading="lazy"> + onerror 占位符
 *
 * - 没有复杂的并发池 / 状态机
 * - 不实时调 Danbooru（图片已离线）
 * - 需要补全时：设置页点"批量补全图片"按钮 → tools/fetch_all_tag_images.php
 */

// 兼容：返回 tag 的图片 URL
function getImageUrl(tag) {
    return tag.example_image_url || tag.example_url || '';
}

// =================== 类别计数 + 筛选 ===================
function updateCategoryCounts() {
    const counts = {
        all: _state.tags.length,
        local: _state.tags.filter(t => t._src === 'local').length,
        online: _state.tags.filter(t => t._src !== 'local').length,
        0: _state.tags.filter(t => t.category === 0).length,
        1: _state.tags.filter(t => t.category === 1).length,
        3: _state.tags.filter(t => t.category === 3).length,
        4: _state.tags.filter(t => t.category === 4).length,
        5: _state.tags.filter(t => t.category === 5).length,
    };
    const map = {
        all: 'tagPickerCatCountAll',
        local: 'tagPickerCatCountLocal',
        online: 'tagPickerCatCountOnline',
        0: 'tagPickerCatCountGeneral',
        1: 'tagPickerCatCountArtist',
        3: 'tagPickerCatCountCopy',
        4: 'tagPickerCatCountChar',
        5: 'tagPickerCatCountMeta',
    };
    for (const k in map) {
        const el = document.getElementById(map[k]);
        if (el) el.textContent = counts[k] || 0;
    }
}

function filterByCategory(tags, cat) {
    if (cat === 'all') return tags;
    if (cat === 'local') return tags.filter(t => t._src === 'local');
    if (cat === 'online') return tags.filter(t => t._src !== 'local');
    return tags.filter(t => String(t.category) === String(cat));
}

function onCatClick(e) {
    const btn = e.currentTarget;
    const cat = btn.dataset.cat;
    _state.activeCat = cat;
    _els.sidebar.querySelectorAll('.tag-picker-cat-btn').forEach(b => b.classList.toggle('active', b === btn));
    renderTags();
}

// =================== 购物车 ===================
function isInCart(name) {
    return _state.cart.some(t => t.name === name);
}

function addToCart(tag) {
    if (isInCart(tag.name)) return;
    _state.cart.push({
        name: tag.name,
        cn_name: tag.cn_name || '',
        category: tag.category,
    });
    renderCart();
    updateCardSelectedState();
}

function removeFromCart(name) {
    _state.cart = _state.cart.filter(t => t.name !== name);
    renderCart();
    updateCardSelectedState();
}

function toggleCart(tag) {
    if (isInCart(tag.name)) {
        removeFromCart(tag.name);
    } else {
        addToCart(tag);
    }
}

function clearCart() {
    if (_state.cart.length === 0) return;
    _state.cart = [];
    renderCart();
    updateCardSelectedState();
    toast('🛒 购物车已清空', { type: 'info', duration: 1500 });
}

function updateCardSelectedState() {
    _els.body.querySelectorAll('.tag-card-danbooru').forEach(card => {
        card.classList.toggle('selected', isInCart(card.dataset.name));
    });
}

function renderCart() {
    const n = _state.cart.length;
    if (_els.cartBadge) _els.cartBadge.textContent = String(n);
    if (_els.cartCount) _els.cartCount.textContent = String(n);
    if (_els.footerCount) _els.footerCount.textContent = String(n);
    if (_els.checkoutBtn) _els.checkoutBtn.disabled = n === 0;

    const list = _els.cartList;
    if (!list) return;
    list.innerHTML = '';
    if (n === 0) {
        const empty = document.createElement('div');
        empty.className = 'tag-picker-cart-empty';
        empty.innerHTML = `
            <div class="empty-icon">🛒</div>
            <div>还没添加标签</div>
            <div class="empty-hint">点击中间任意标签卡加入购物车</div>
        `;
        list.appendChild(empty);
        return;
    }
    _state.cart.forEach((t, i) => {
        const row = document.createElement('div');
        row.className = 'cart-tag-row';
        row.dataset.name = t.name;
        const catName = DB_CAT_BADGE[t.category] || '';
        row.innerHTML = `
            <span class="cart-index">${i + 1}</span>
            <span class="cart-name"></span>
            ${t.cn_name ? `<span class="cart-cn">${escapeHtml(t.cn_name)}</span>` : ''}
            <button class="cart-remove" title="从购物车移除 ${escapeHtml(t.name)}">✕</button>
        `;
        row.querySelector('.cart-name').textContent = t.name;
        row.querySelector('.cart-remove').addEventListener('click', (e) => {
            e.stopPropagation();
            removeFromCart(t.name);
            toast(`🗑 已移除: ${t.name}`, { type: 'info', duration: 1500 });
        });
        // 点击整行也能移除（双击也行，单击是常用交互）
        row.addEventListener('click', () => {
            removeFromCart(t.name);
            toast(`🗑 已移除: ${t.name}`, { type: 'info', duration: 1500 });
        });
        list.appendChild(row);
    });
}

async function checkout() {
    if (_state.cart.length === 0) {
        toast('购物车是空的', { type: 'warning' });
        return;
    }
    const text = _state.cart.map(t => t.name).join(', ');
    const ok = await copyText(text);
    if (ok) {
        toast(`📋 已复制 ${_state.cart.length} 个标签到剪贴板`, { type: 'success', duration: 2500 });
    } else {
        toast('复制失败，请检查浏览器权限', { type: 'error' });
    }
}

// =================== 渲染 ===================
function renderDropdown() {
    const dd = _els.dropdown;
    if (!dd) return;
    if (!_state.query || _state.localTags.length === 0) {
        dd.classList.add('hidden');
        dd.innerHTML = '';
        return;
    }
    dd.classList.remove('hidden');
    dd.innerHTML = _state.localTags.map(t => {
        const cat = DB_CAT_BADGE[t.category] || '';
        const cn = t.cn_name || '';
        const inCart = isInCart(t.name);
        return `
            <div class="tpd-row ${inCart ? 'tpd-in-cart' : ''}" data-name="${escapeHtml(t.name)}" title="${inCart ? '从购物车移除' : '加入购物车'} ${escapeHtml(t.name)}">
                <span class="tpd-name"></span>
                ${cn ? `<span class="tpd-cn">${escapeHtml(cn)}</span>` : ''}
                ${cat ? `<span class="tpd-cat">${escapeHtml(cat)}</span>` : ''}
                <span class="tpd-count">${formatCount(t.post_count || 0)}</span>
                <span class="tpd-action">${inCart ? '✕ 移除' : '+ 加购'}</span>
            </div>
        `;
    }).join('');
    dd.querySelectorAll('.tpd-name').forEach((el, i) => {
        el.textContent = _state.localTags[i].name;
    });
    dd.querySelectorAll('.tpd-row').forEach((row, i) => {
        const t = _state.localTags[i];
        row.addEventListener('click', () => {
            toggleCart(t);
            renderDropdown();    // 刷新下拉的 + 加购 / ✕ 移除 状态
        });
    });
}

function renderTags() {
    _els.body.innerHTML = '';
    const filtered = filterByCategory(_state.tags, _state.activeCat);
    const n = filtered.length;
    if (_els.count) _els.count.textContent = String(n);
    if (_els.total) _els.total.textContent = formatCount(_state.tags.length);

    if (!_state.query) {
        const el = document.createElement('div');
        el.className = 'tag-picker-empty';
        el.innerHTML = '<div class="empty-icon">🔍</div><div>输入关键词开始搜索</div><div class="empty-hint">中文 / 英文 tag 都行 · 本地缓存 + Danbooru 同时搜</div>';
        _els.body.appendChild(el);
        return;
    }
    if (n === 0 && !_state.loading) {
        const el = document.createElement('div');
        el.className = 'tag-picker-empty';
        el.innerHTML = '<div class="empty-icon">📭</div><div>这个分类下没有标签</div><div class="empty-hint">试试别的分类或关键词</div>';
        _els.body.appendChild(el);
        return;
    }

    // 按来源分组
    const localPart = filtered.filter(t => t._src === 'local');
    const onlinePart = filtered.filter(t => t._src !== 'local');

    if (localPart.length > 0 && (_state.activeCat === 'all' || _state.activeCat === 'local' || _state.activeCat !== 'online')) {
        const groupHeader = document.createElement('div');
        groupHeader.className = 'tag-picker-group';
        groupHeader.innerHTML = `📦 本地缓存 <span>${localPart.length}</span>`;
        _els.body.appendChild(groupHeader);
        for (const tag of localPart) _els.body.appendChild(buildCard(tag));
    }
    if (onlinePart.length > 0 && (_state.activeCat === 'all' || _state.activeCat === 'online' || _state.activeCat !== 'local')) {
        const groupHeader = document.createElement('div');
        groupHeader.className = 'tag-picker-group';
        groupHeader.innerHTML = `🌐 Danbooru 在线 <span>${onlinePart.length}</span>`;
        _els.body.appendChild(groupHeader);
        for (const tag of onlinePart) _els.body.appendChild(buildCard(tag));
    }
}

function buildCard(tag, opts = {}) {
    const card = document.createElement('div');
    card.className = 'tag-card-danbooru' + (isInCart(tag.name) ? ' selected' : '');
    card.dataset.name = tag.name;
    // category 兼容：tags 表用 category_name_cn，danbooru 表用 category (0/1/3/4/5)
    const catName = tag.category_name_cn || DB_CAT_BADGE[tag.category] || '';
    const cn = tag.cn_name ? `<div class="db-cn">${escapeHtml(tag.cn_name)}</div>` : '';
    const inCart = isInCart(tag.name);
    const imgUrl = getImageUrl(tag);

    // 纯静态 <img>：有图就显示，没图就让 :empty::before 显示 ? 占位
    // 0 JS 状态机 / 0 异步抓取 / 0 并发池
    // 想补全图 → 设置页点按钮调后端 batch API / 或本地缓存 tab 点单卡「拉取」按钮
    const fetchBtn = (opts.showFetchBtn && !imgUrl)
        ? `<button class="db-fetch-btn" data-fetch="${escapeHtml(tag.name)}" title="拉取 ${escapeHtml(tag.name)} 的预览图">📥 拉取</button>`
        : '';

    card.innerHTML = `
        <div class="db-img">
            ${imgUrl
                ? `<img class="db-img-el" src="${escapeHtml(imgUrl)}" loading="lazy" decoding="async" referrerpolicy="no-referrer" alt="" onerror="this.remove();">`
                : ''}
        </div>
        <div class="db-count">${formatCount(tag.post_count || 0)}</div>
        ${catName ? `<div class="db-cat">${escapeHtml(catName)}</div>` : ''}
        ${inCart ? `<div class="db-cart-mark" title="已在购物车">🛒</div>` : ''}
        ${fetchBtn}
        <div class="db-info">
            <div class="db-name"></div>
            ${cn}
        </div>
    `;
    card.querySelector('.db-name').textContent = tag.name;

    // 「拉取预览」按钮单独绑定（不冒泡到 card 点击）
    const fetchBtnEl = card.querySelector('.db-fetch-btn');
    if (fetchBtnEl) {
        fetchBtnEl.addEventListener('click', async (e) => {
            e.stopPropagation();
            await fetchOneImageForCard(fetchBtnEl, tag);
        });
    }

    // 点击卡片 = toggle 加入/移出购物车
    card.addEventListener('click', () => {
        const wasIn = isInCart(tag.name);
        toggleCart(tag);
        toast(wasIn ? `🗑 已从购物车移除: ${tag.name}` : `🛒 已加入购物车: ${tag.name}`, { type: 'success', duration: 1500 });
    });
    return card;
}

/**
 * 单 tag 拉取预览图（仿 wfjsw：构建时预生成 → 运行时纯静态）
 * 走 /api/tag_image.php?action=fetch — 本地有直接回，没有后端调 Danbooru 拉 + 存 + 写 DB
 */
async function fetchOneImageForCard(btn, tag) {
    btn.disabled = true;
    btn.textContent = '⏳ 拉取中';
    try {
        const r = await api.tagImageFetch(tag.name);
        if (r.ok && r.url) {
            // 1. 把 img 注入到 db-img
            const card = btn.closest('.tag-card-danbooru');
            const wrap = card?.querySelector('.db-img');
            if (wrap && !wrap.querySelector('img')) {
                const img = document.createElement('img');
                img.className = 'db-img-el';
                img.src = r.url;
                img.loading = 'lazy';
                img.decoding = 'async';
                img.referrerPolicy = 'no-referrer';
                img.alt = '';
                img.onerror = () => img.remove();
                wrap.appendChild(img);
            }
            // 2. 隐藏按钮
            btn.classList.add('fetched');
            btn.textContent = '✅ 已拉取';
            setTimeout(() => btn.remove(), 1500);
            // 3. 更新 tag 对象 + 全局 localTotal
            tag.example_image_url = r.url;
            _state.localHasImage = (_state.localHasImage || 0) + 1;
            refreshLocalCount();
            toast(`✅ 已拉取预览: ${tag.name}`, { type: 'success', duration: 1500 });
        } else {
            btn.textContent = '❌ 失败';
            btn.disabled = false;
            toast(`拉取失败: ${r.error || '未知'}`, { type: 'error', duration: 3000 });
        }
    } catch (e) {
        btn.textContent = '❌ 网络错';
        btn.disabled = false;
        toast(`拉取出错: ${e.message}`, { type: 'error' });
    }
}

// =================== Modal 控制 ===================
function open() {
    if (_state.open) return;
    _state.open = true;
    _els.picker.classList.remove('hidden');
    if (_state.activeTab === 'local' && _state.localTagsAll.length === 0) {
        loadLocalPage(true);
    } else {
        renderTags();
    }
    renderCart();
    refreshLocalCount();
    setTimeout(() => _els.search.focus(), 50);
}
function close() {
    if (!_state.open) return;
    _state.open = false;
    _els.picker.classList.add('hidden');
    _els.dropdown?.classList.add('hidden');
}

// =================== Tab 切换：搜索 / 本地缓存 ===================
async function switchTab(tab) {
    if (_state.activeTab === tab) return;
    _state.activeTab = tab;

    // 更新 tab 按钮视觉
    _els.picker.querySelectorAll('.tag-picker-tab').forEach(b => {
        b.classList.toggle('active', b.dataset.tab === tab);
    });

    if (tab === 'local') {
        // 切到本地缓存：隐藏 sidebar + search wrap + translate bar + 显示 toolbar
        _els.sidebar?.classList.add('hidden');
        _els.searchWrap?.classList.add('hidden');
        _els.translateBar?.classList.add('hidden');
        _els.dropdown?.classList.add('hidden');
        _els.localToolbar?.classList.remove('hidden');
        if (_els.centerTitle) _els.centerTitle.textContent = '本地缓存';
        if (_els.localRefresh) _els.localRefresh.classList.remove('hidden');
        if (_state.localTagsAll.length === 0) {
            await loadLocalPage(true);
        } else {
            renderLocal();
        }
        refreshLocalCount();
    } else {
        // 切到搜索：恢复 sidebar + search wrap + 隐藏 toolbar
        _els.sidebar?.classList.remove('hidden');
        _els.searchWrap?.classList.remove('hidden');
        _els.localToolbar?.classList.add('hidden');
        if (_els.centerTitle) _els.centerTitle.textContent = '在线搜索';
        if (_els.localRefresh) _els.localRefresh.classList.add('hidden');
        renderTags();
        setTimeout(() => _els.search?.focus(), 50);
    }
}

/**
 * 加载本地缓存页（追加或重置）
 */
async function loadLocalPage(reset = false) {
    if (_state.localLoadingMore) return;
    _state.localLoadingMore = true;

    if (reset) {
        _state.localTagsAll = [];
        _state.localPage = 0;
        _state.localTotal = 0;
        _state.localHasMore = false;
        _els.body.innerHTML = '<div class="tag-picker-empty"><div class="empty-icon">⏳</div><div>加载本地缓存...</div></div>';
    }

    try {
        const params = {
            page: (_state.localPage || 0) + 1,
            per_page: _state.localPerPage,
            ..._state.localFilters,
        };
        // 空字符串 → 不传
        Object.keys(params).forEach(k => {
            if (params[k] === '' || params[k] == null) delete params[k];
        });

        const r = await api.tagLocalList(params);
        _state.localTotal = r.total;
        _state.localHasMore = r.has_more;
        _state.localPage = r.page;
        if (reset) {
            _state.localTagsAll = r.rows;
        } else {
            _state.localTagsAll = _state.localTagsAll.concat(r.rows);
        }
        renderLocal();
    } catch (e) {
        toast('本地缓存加载失败: ' + e.message, { type: 'error' });
        _els.body.innerHTML = `<div class="tag-picker-empty"><div class="empty-icon">❌</div><div>加载失败</div><div class="empty-hint">${escapeHtml(e.message)}</div></div>`;
    } finally {
        _state.localLoadingMore = false;
    }
}

function renderLocal() {
    _els.body.innerHTML = '';
    const tags = _state.localTagsAll;
    if (_els.count) _els.count.textContent = String(tags.length);
    if (_els.total) _els.total.textContent = formatCount(_state.localTotal);

    if (tags.length === 0 && !_state.localLoadingMore) {
        const el = document.createElement('div');
        el.className = 'tag-picker-empty';
        el.innerHTML = '<div class="empty-icon">📭</div><div>本地缓存没有匹配的标签</div><div class="empty-hint">试试别的筛选条件</div>';
        _els.body.appendChild(el);
        return;
    }

    // 用 fragment 批量 append
    const frag = document.createDocumentFragment();
    for (const tag of tags) frag.appendChild(buildCard(tag, { showFetchBtn: true }));
    _els.body.appendChild(frag);

    // 底部加载更多指示
    if (_state.localHasMore) {
        const more = document.createElement('div');
        more.className = 'tag-picker-local-loadmore';
        more.innerHTML = `<button class="ghost-button" id="tagPickerLoadMoreBtn">加载更多（${tags.length}/${_state.localTotal}）</button>`;
        _els.body.appendChild(more);
        more.querySelector('button').addEventListener('click', () => loadLocalPage(false));
    }
}

/**
 * 刷新本地缓存计数（用于 tab badge）
 */
async function refreshLocalCount() {
    if (!_els.localCount) return;
    try {
        const r = await api.fetchImgStats();
        _els.localCount.textContent = String(r.total);
        _els.localCount.title = `${r.have}/${r.total} 有图（${r.coverage}%）`;
    } catch {}
}
function onSearchKey(e) {
    if (e.key === 'Escape') {
        e.preventDefault();
        close();
    } else if (e.key === 'ArrowDown' && !_els.dropdown.classList.contains('hidden')) {
        e.preventDefault();
        _els.dropdown.querySelector('.tpd-row')?.click();
    }
}

export function initTagPicker() {
    _els = {
        picker:         document.getElementById('tagPicker'),
        search:         document.getElementById('tagPickerSearch'),
        body:           document.getElementById('tagPickerBody'),
        count:          document.getElementById('tagPickerCount'),
        total:          document.getElementById('tagPickerTotal'),
        centerTitle:    document.getElementById('tagPickerCenterTitle'),
        closeBtn:       document.getElementById('tagPickerCloseBtn'),
        translateBar:   document.getElementById('tagPickerTranslateBar'),
        dropdown:       document.getElementById('tagPickerDropdown'),
        // 购物车
        cart:           document.getElementById('tagPickerCart'),
        cartList:       document.getElementById('tagPickerCartList'),
        cartBadge:      document.getElementById('tagPickerCartBadge'),
        cartCount:      document.getElementById('tagPickerCartCount'),
        checkoutBtn:    document.getElementById('tagPickerCheckoutBtn'),
        cartClearBtn:   document.getElementById('tagPickerCartClearBtn'),
        footerCount:    document.getElementById('tagPickerFooterCount'),
        // sidebar
        sidebar:        document.getElementById('tagPickerSidebar'),
        searchWrap:     document.querySelector('.tag-picker-search-wrap'),
        // 本地缓存 tab
        localCount:     document.getElementById('tagPickerLocalCount'),
        localToolbar:   document.getElementById('tagPickerLocalToolbar'),
        localCategory:  document.getElementById('tagPickerLocalCategory'),
        localHasImage:  document.getElementById('tagPickerLocalHasImage'),
        localSort:      document.getElementById('tagPickerLocalSort'),
        localRefresh:   document.getElementById('tagPickerLocalRefreshBtn'),
    };
    if (!_els.picker) return;

    document.getElementById('tagPickerBtn')?.addEventListener('click', open);
    _els.closeBtn.addEventListener('click', close);
    _els.picker.addEventListener('click', (e) => { if (e.target === _els.picker) close(); });

    _els.search.addEventListener('input', onInput);
    _els.search.addEventListener('keydown', onSearchKey);

    document.addEventListener('click', (e) => {
        if (_state.open && !e.target.closest('.tag-picker-search-wrap')) {
            _els.dropdown?.classList.add('hidden');
        }
    });

    // 购物车按钮
    _els.checkoutBtn.addEventListener('click', checkout);
    _els.cartClearBtn.addEventListener('click', clearCart);

    // sidebar 分类切换
    if (_els.sidebar) {
        _els.sidebar.querySelectorAll('.tag-picker-cat-btn').forEach(btn => {
            btn.addEventListener('click', onCatClick);
        });
    }

    // Tab 切换
    _els.picker.querySelectorAll('.tag-picker-tab').forEach(btn => {
        btn.addEventListener('click', () => switchTab(btn.dataset.tab));
    });

    // 本地缓存筛选条
    const onFilterChange = () => {
        _state.localFilters = {
            category:  _els.localCategory?.value || '',
            has_image: _els.localHasImage?.value || '',
            sort:      _els.localSort?.value || 'popular',
            q:         '',
        };
        loadLocalPage(true);
    };
    _els.localCategory?.addEventListener('change', onFilterChange);
    _els.localHasImage?.addEventListener('change', onFilterChange);
    _els.localSort?.addEventListener('change', onFilterChange);
    _els.localRefresh?.addEventListener('click', () => loadLocalPage(true));

    // 滚动到底自动加载更多
    _els.body?.addEventListener('scroll', () => {
        if (_state.activeTab !== 'local' || !_state.localHasMore || _state.localLoadingMore) return;
        const scrollBottom = _els.body.scrollTop + _els.body.clientHeight;
        if (scrollBottom >= _els.body.scrollHeight - 200) {
            loadLocalPage(false);
        }
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && _state.open) close();
        if (e.key === 't' && !e.ctrlKey && !e.metaKey && !_state.open) {
            const tag = document.activeElement?.tagName;
            if (tag === 'INPUT' || tag === 'TEXTAREA') return;
            open();
        }
    });

    // 默认 tab 是 search，渲染一次
    renderTags();
    renderCart();
}