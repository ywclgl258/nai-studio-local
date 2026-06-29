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
    if (_state.tags.length > 0) lazyLoadExamples(_state.tags);
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

async function lazyLoadExamples(tags) {
    for (const t of tags) {
        if (t.example_url || t.example_image_url) continue;
        try {
            const r = await api.danbooruPost(t.name, 1);
            const rows = r.rows || [];
            if (rows.length > 0) {
                t.example_url = rows[0].preview_url;
                updateCardImage(t.name, t.example_url);
            }
        } catch {}
    }
}

function updateCardImage(name, url) {
    if (!url) return;
    const card = _els.body.querySelector(`[data-name="${CSS.escape(name)}"] .db-img`);
    if (card) card.style.backgroundImage = `url('${url}')`;
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

function buildCard(tag) {
    const card = document.createElement('div');
    card.className = 'tag-card-danbooru' + (isInCart(tag.name) ? ' selected' : '');
    card.dataset.name = tag.name;
    const catName = DB_CAT_BADGE[tag.category] || '';
    const cn = tag.cn_name ? `<div class="db-cn">${escapeHtml(tag.cn_name)}</div>` : '';
    const inCart = isInCart(tag.name);
    card.innerHTML = `
        <div class="db-img" ${tag.example_url || tag.example_image_url ? `style="background-image:url('${tag.example_url || tag.example_image_url}')"` : ''}></div>
        <div class="db-count">${formatCount(tag.post_count || 0)}</div>
        ${catName ? `<div class="db-cat">${escapeHtml(catName)}</div>` : ''}
        ${inCart ? `<div class="db-cart-mark" title="已在购物车">🛒</div>` : ''}
        <div class="db-info">
            <div class="db-name"></div>
            ${cn}
        </div>
    `;
    card.querySelector('.db-name').textContent = tag.name;
    // 点击 = toggle 加入/移出购物车
    card.addEventListener('click', () => {
        const wasIn = isInCart(tag.name);
        toggleCart(tag);
        toast(wasIn ? `🗑 已从购物车移除: ${tag.name}` : `🛒 已加入购物车: ${tag.name}`, { type: 'success', duration: 1500 });
    });
    return card;
}

// =================== Modal 控制 ===================
function open() {
    if (_state.open) return;
    _state.open = true;
    _els.picker.classList.remove('hidden');
    renderTags();
    renderCart();
    setTimeout(() => _els.search.focus(), 50);
}
function close() {
    if (!_state.open) return;
    _state.open = false;
    _els.picker.classList.add('hidden');
    _els.dropdown?.classList.add('hidden');
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
        // 新增：购物车
        cart:           document.getElementById('tagPickerCart'),
        cartList:       document.getElementById('tagPickerCartList'),
        cartBadge:      document.getElementById('tagPickerCartBadge'),
        cartCount:      document.getElementById('tagPickerCartCount'),
        checkoutBtn:    document.getElementById('tagPickerCheckoutBtn'),
        cartClearBtn:   document.getElementById('tagPickerCartClearBtn'),
        footerCount:    document.getElementById('tagPickerFooterCount'),
        // 新增：sidebar
        sidebar:        document.getElementById('tagPickerSidebar'),
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

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && _state.open) close();
        if (e.key === 't' && !e.ctrlKey && !e.metaKey && !_state.open) {
            const tag = document.activeElement?.tagName;
            if (tag === 'INPUT' || tag === 'TEXTAREA') return;
            open();
        }
    });

    renderTags();
    renderCart();
}