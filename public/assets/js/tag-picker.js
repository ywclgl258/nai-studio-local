/**
 * NAI Studio - Tag Picker
 * 仿 tags.novelai.dev: 3 栏布局 — 分类侧栏 / 标签网格 / 已选面板
 *
 * 数据源：
 *  - 本地库 (tags 表 + categories 表，有 cn_name)
 *  - 在线 Danbooru (无 cn_name 时回落到字典/在线翻译)
 */

import { api } from './api.js';
import { toast } from './toast.js';

let _state = {
    open: false,
    source: 'local',                 // 'local' | 'danbooru'
    categories: [],
    activeCategory: null,            // null = 全部
    tags: [],
    page: 1,
    perPage: 60,
    total: 0,
    query: '',
    selected: new Map(),             // name -> { weight: 1.0, source, cn_name }
    loading: false,
    hasMore: true,
};

let _elements = {};
const DB_CAT_BADGE = { 0: '通用', 1: '画师', 3: '版权', 4: '角色', 5: '元' };
const DB_CAT_ICON  = { 0: '🏷', 1: '🎨', 3: '©', 4: '👤', 5: '⚙' };

function debounce(fn, ms) {
    let t;
    return (...args) => {
        clearTimeout(t);
        t = setTimeout(() => fn(...args), ms);
    };
}

async function loadCategories() {
    if (_state.categories.length > 0) return;
    const r = await api.tagCategories();
    _state.categories = r.rows || [];
    renderCategories();
}

function renderCategories() {
    const wrap = _elements.categories;
    if (_state.source !== 'local') {
        wrap.innerHTML = '';
        return;
    }
    wrap.innerHTML = '';
    // 虚拟分类：姿势/动作（顶部高亮，不走 tags API）
    const poseBtn = makeCatBtn('__pose__', '姿势/动作', '📚', 187);
    poseBtn.classList.add('tag-picker-cat-special');
    if (_state.activeCategory === '__pose__') poseBtn.classList.add('active');
    wrap.appendChild(poseBtn);
    // 全部
    const allBtn = makeCatBtn(null, '全部', '🗂', _state.categories.reduce((s, c) => s + (c.tag_count || 0), 0));
    wrap.appendChild(allBtn);
    for (const cat of _state.categories) {
        const btn = makeCatBtn(cat.id, cat.name_cn || cat.name, DB_CAT_ICON[cat.id] || '📁', cat.tag_count || 0);
        wrap.appendChild(btn);
    }
}

function makeCatBtn(id, name, icon, count) {
    const btn = document.createElement('button');
    btn.className = 'tag-picker-cat-btn' + (_state.activeCategory === id ? ' active' : '');
    btn.innerHTML = `
        <span class="cat-icon">${icon || ''}</span>
        <span class="cat-name"></span>
        <span class="cat-count">${formatCount(count)}</span>
    `;
    btn.querySelector('.cat-name').textContent = name;
    btn.addEventListener('click', () => switchCategory(id));
    return btn;
}

async function switchCategory(categoryId) {
    _state.activeCategory = categoryId;
    _state.page = 1;
    _state.tags = [];
    _state.query = '';
    _elements.search.value = '';
    // 更新标题
    const titleEl = _elements.centerTitle;
    if (titleEl) {
        if (categoryId === null) titleEl.textContent = '全部';
        else if (categoryId === '__pose__') titleEl.textContent = '姿势/动作';
        else {
            const cat = _state.categories.find(c => c.id === categoryId);
            titleEl.textContent = cat ? (cat.name_cn || cat.name) : '分类';
        }
    }
    renderCategories();
    await loadTags(true);
}

async function loadTags(reset = false) {
    if (_state.loading) return;
    _state.loading = true;
    showLoading(true);
    try {
        if (_state.source === 'local') {
            // 虚拟分类：姿势/动作（不走 tags API，直接读 PoseDict）
            if (_state.activeCategory === '__pose__') {
                const r = await fetch('api/pose-dict.php?q=' + encodeURIComponent(_state.query || ''), { credentials: 'same-origin' });
                const j = await r.json();
                const flat = [];
                for (const [cat, items] of Object.entries(j.categories || {})) {
                    for (const it of items) {
                        flat.push({
                            name: it.en,
                            cn_name: it.cn,
                            post_count: 0,
                            _pose_cat: cat,
                        });
                    }
                }
                _state.tags = flat;
                _state.hasMore = false;
                _state.total = j.total || flat.length;
            } else {
                const params = { q: _state.query, page: _state.page, per_page: _state.perPage };
                if (_state.activeCategory !== null) params.category = _state.activeCategory;
                const r = await api.tagSearch(params);
                const rows = r.rows || [];
                if (reset) _state.tags = [];
                _state.tags.push(...rows);
                _state.hasMore = rows.length === _state.perPage;
                _state.total = r.total || _state.tags.length;
            }
        } else {
            // Danbooru
            if (!_state.query.trim()) {
                if (reset) _state.tags = [];
                _state.hasMore = false;
                _state.total = 0;
            } else {
                const r = await api.danbooruTag(_state.query, _state.perPage);
                const rows = r.rows || [];
                if (rows.length > 0) {
                    try {
                        const localLookup = await api.tagLookup(rows.map(t => t.name).slice(0, 50));
                        const cnMap = {};
                        for (const lr of (localLookup.rows || [])) cnMap[lr.name] = lr.cn_name;
                        for (const t of rows) {
                            t.cn_name = cnMap[t.name] || null;
                            if (!t.example_url && t.post_count > 0) t._pendingImg = true;
                        }
                    } catch {}
                }
                if (reset) _state.tags = [];
                _state.tags.push(...rows);
                _state.hasMore = false;
                _state.total = rows.length;
            }
        }
        updateHeaderCount();
        renderTags();
        if (_state.source === 'danbooru') lazyLoadExamples();
        // 加载更多按钮：仅本地有分页时显示
        if (_elements.loadMore) {
            _elements.loadMore.style.display = (_state.source === 'local' && _state.hasMore) ? '' : 'none';
        }
    } catch (e) {
        toast('加载标签失败: ' + e.message, { type: 'error' });
    } finally {
        _state.loading = false;
        showLoading(false);
    }
}

// PoseDict 分类渲染：在标签卡上显示中文在前、英文小字后
function renderLocalCards() {
    for (const tag of _state.tags) {
        const hasImg = !!tag.example_image_url;
        const card = document.createElement('div');
        const isPose = _state.activeCategory === '__pose__';
        card.className = (hasImg ? 'tag-card tag-card-with-img' : 'tag-card') + (isPose ? ' tag-card-pose' : '');
        card.dataset.name = tag.name;
        if (_state.selected.has(tag.name)) card.classList.add('selected');
        const imgHtml = hasImg
            ? `<div class="tag-card-img" style="background-image:url('${tag.example_image_url}')"></div>`
            : '';
        if (isPose) {
            card.innerHTML = `
                <div class="tag-card-body">
                    <span class="tag-cn tag-cn-primary"></span>
                    <span class="tag-name tag-name-en"></span>
                    ${tag._pose_cat ? `<span class="tag-pose-cat"></span>` : ''}
                </div>
            `;
            card.querySelector('.tag-cn-primary').textContent = tag.cn_name || '';
            card.querySelector('.tag-name-en').textContent = tag.name;
            if (tag._pose_cat) card.querySelector('.tag-pose-cat').textContent = tag._pose_cat;
        } else {
            card.innerHTML = `
                ${imgHtml}
                <div class="tag-card-body">
                    <span class="tag-name"></span>
                    <span class="tag-cn"></span>
                </div>
                <span class="tag-count">${tag.post_count > 0 ? formatCount(tag.post_count) : ''}</span>
            `;
            card.querySelector('.tag-name').textContent = tag.name;
            card.querySelector('.tag-cn').textContent = tag.cn_name || '';
        }
        card.addEventListener('click', () => toggleSelect(tag.name, 'local', tag.cn_name));
        attachContextMenu(card, tag.name, 'local', tag.cn_name);
        _elements.body.appendChild(card);
    }
    if (_elements.loadMore) {
        _elements.loadMore.style.display = (_state.source === 'local' && _state.hasMore && _state.activeCategory !== '__pose__') ? '' : 'none';
    }
}

async function lazyLoadExamples() {
    const pending = _state.tags.filter(t => t._pendingImg).slice(0, 6);
    for (const t of pending) {
        try {
            const r = await api.danbooruPost(t.name, 1);
            const rows = r.rows || [];
            if (rows.length > 0) t.example_url = rows[0].preview_url;
            t._pendingImg = false;
            updateCardImage(t.name, t.example_url);
        } catch {
            t._pendingImg = false;
        }
    }
}

function updateCardImage(name, url) {
    if (!url) return;
    const card = _elements.body.querySelector(`[data-name="${CSS.escape(name)}"] .db-img`);
    if (card) card.style.backgroundImage = `url('${url}')`;
}

const debouncedSearch = debounce(() => {
    _state.page = 1;
    loadTags(true);
}, 350);

function showLoading(show) {
    if (show && _elements.body.querySelector('.tag-picker-loading')) return;
    if (show) {
        const el = document.createElement('div');
        el.className = 'tag-picker-loading';
        el.textContent = _state.source === 'danbooru' ? '正在查询 Danbooru' : '加载中';
        _elements.body.appendChild(el);
    } else {
        _elements.body.querySelectorAll('.tag-picker-loading').forEach(el => el.remove());
    }
}

function formatCount(n) {
    if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
    if (n >= 1000) return (n / 1000).toFixed(1) + 'k';
    return String(n);
}

function renderTags() {
    _elements.body.innerHTML = '';
    if (_state.tags.length === 0) {
        const el = document.createElement('div');
        el.className = 'tag-picker-empty';
        if (_state.source === 'danbooru' && !_state.query.trim()) {
            el.innerHTML = '<div class="empty-icon">🔍</div><div>输入关键词搜索 Danbooru 标签</div>';
        } else {
            el.innerHTML = '<div class="empty-icon">📭</div><div>' + (_state.query ? '没有匹配的标签' : '此分类暂无标签') + '</div>';
        }
        _elements.body.appendChild(el);
        updateCount();
        return;
    }
    if (_state.source === 'danbooru') {
        renderDanbooruCards();
    } else {
        renderLocalCards();
    }
    updateCount();
}

function renderDanbooruCards() {
    for (const tag of _state.tags) {
        const card = document.createElement('div');
        card.className = 'tag-card-danbooru';
        card.dataset.name = tag.name;
        if (_state.selected.has(tag.name)) card.classList.add('selected');
        const catName = DB_CAT_BADGE[tag.category] || '';
        const cn = tag.cn_name ? `<div class="db-cn">${tag.cn_name}</div>` : '';
        card.innerHTML = `
            <div class="db-img" ${tag.example_url ? `style="background-image:url('${tag.example_url}')"` : ''}></div>
            <div class="db-count">${formatCount(tag.post_count || 0)}</div>
            ${catName ? `<div class="db-cat">${catName}</div>` : ''}
            <div class="db-info">
                <div class="db-name"></div>
                ${cn}
            </div>
        `;
        card.querySelector('.db-name').textContent = tag.name;
        card.addEventListener('click', (e) => {
            if (e.target.closest('button')) return;
            toggleSelect(tag.name, 'danbooru', tag.cn_name);
        });
        attachContextMenu(card, tag.name, 'danbooru', tag.cn_name);
        _elements.body.appendChild(card);
    }
}

function attachContextMenu(card, name, source, cnName) {
    card.addEventListener('contextmenu', (e) => {
        e.preventDefault();
        const cur = _state.selected.get(name);
        const curWeight = cur ? cur.weight : 1.0;
        const newWeight = parseFloat(prompt(`${name} 权重 (0.1 ~ 2.0)\n1.0 = 普通；>1 加权；<1 减权`, curWeight.toFixed(2)));
        if (isNaN(newWeight)) return;
        const w = Math.max(0.1, Math.min(2.0, newWeight));
        _state.selected.set(name, { weight: w, source, cn_name: cnName });
        renderTags();
        renderSelectedList();
    });
}

function toggleSelect(name, source, cnName) {
    if (_state.selected.has(name)) {
        _state.selected.delete(name);
    } else {
        _state.selected.set(name, { weight: 1.0, source, cn_name: cnName || null });
    }
    renderTags();
    renderSelectedList();
}

function updateCount() {
    const n = _state.selected.size;
    if (_elements.selectedCount) _elements.selectedCount.textContent = String(n);
}

function updateHeaderCount() {
    if (_elements.count) _elements.count.textContent = String(_state.tags.length);
    if (_elements.total) {
        _elements.total.textContent = formatCount(_state.total || _state.tags.length);
    }
}

function renderSelectedList() {
    const list = _elements.selectedList;
    if (!list) return;
    list.innerHTML = '';
    const items = Array.from(_state.selected.entries());
    if (items.length === 0) {
        updateCount();
        return;
    }
    for (const [name, meta] of items) {
        const row = document.createElement('div');
        row.className = 'selected-tag-row';
        const cn = meta.cn_name ? `<span class="sel-cn">${meta.cn_name}</span>` : '';
        row.innerHTML = `
            <span class="sel-name"></span>
            ${cn}
            <input class="sel-weight" type="number" min="0.1" max="2.0" step="0.05" value="${meta.weight.toFixed(2)}" title="权重 (0.1~2.0)">
            <button class="sel-remove" title="移除">×</button>
        `;
        row.querySelector('.sel-name').textContent = name;
        const wInput = row.querySelector('.sel-weight');
        wInput.addEventListener('change', () => {
            const v = parseFloat(wInput.value);
            if (isNaN(v)) { wInput.value = meta.weight.toFixed(2); return; }
            const w = Math.max(0.1, Math.min(2.0, v));
            meta.weight = w;
            wInput.value = w.toFixed(2);
        });
        row.querySelector('.sel-remove').addEventListener('click', () => {
            _state.selected.delete(name);
            renderTags();
            renderSelectedList();
        });
        list.appendChild(row);
    }
    updateCount();
}

function open() {
    if (_state.open) return;
    _state.open = true;
    _elements.picker.classList.remove('hidden');
    if (_state.source === 'local') {
        if (_state.categories.length === 0) loadCategories();
        if (_state.tags.length === 0) loadTags(true);
    } else {
        if (_state.tags.length === 0) renderTags();
    }
    renderSelectedList();
    setTimeout(() => _elements.search.focus(), 50);
}
function close() {
    if (!_state.open) return;
    _state.open = false;
    _elements.picker.classList.add('hidden');
}
function toggle() { _state.open ? close() : open(); }

function switchSource(src) {
    if (_state.source === src) return;
    _state.source = src;
    _state.page = 1;
    _state.tags = [];
    _state.query = '';
    _state.activeCategory = null;
    _elements.search.value = '';
    _elements.search.placeholder = src === 'local'
        ? '搜索标签 — 中文 / 英文 / 分类'
        : '搜索 Danbooru 标签（英文 / tag 名）';
    document.querySelectorAll('.tag-picker-source-tabs button').forEach(b => {
        b.classList.toggle('active', b.dataset.source === src);
    });
    // 侧栏：Danbooru 模式隐藏
    _elements.categories.style.display = src === 'local' ? '' : 'none';
    // 中心标题
    if (_elements.centerTitle) _elements.centerTitle.textContent = src === 'local' ? '全部' : '在线搜索';
    renderCategories();
    renderTags();
    renderSelectedList();
    if (src === 'local' && _state.categories.length === 0) {
        loadCategories().then(() => loadTags(true));
    } else if (src !== 'local') {
        // 等用户输入
    } else {
        loadTags(true);
    }
}

function insertSelected() {
    if (_state.selected.size === 0) {
        toast('没有选中的标签', { type: 'warning' });
        return;
    }
    const promptInput = document.getElementById('promptInput');
    if (!promptInput) return;
    const tags = Array.from(_state.selected.entries()).map(([name, meta]) => {
        if (Math.abs(meta.weight - 1.0) < 0.001) return name;
        if (meta.weight > 1) return `{${name}:${meta.weight.toFixed(2)}}`;
        return `(${name}:${meta.weight.toFixed(2)})`;
    });
    const start = promptInput.selectionStart || 0;
    const end = promptInput.selectionEnd || 0;
    const before = promptInput.value.slice(0, start);
    const after = promptInput.value.slice(end);
    const sep = (before && !before.match(/[,，]\s*$/)) ? ', ' : '';
    const insertText = tags.join(', ');
    promptInput.value = before + sep + insertText + after;
    const newPos = start + sep.length + insertText.length;
    promptInput.setSelectionRange(newPos, newPos);
    promptInput.focus();
    promptInput.dispatchEvent(new Event('input', { bubbles: true }));
    toast(`已插入 ${tags.length} 个标签`, { type: 'success' });
    _state.selected.clear();
    renderSelectedList();
    close();
}

function clearSelected() {
    _state.selected.clear();
    renderTags();
    renderSelectedList();
}

export function initTagPicker() {
    _elements = {
        picker:         document.getElementById('tagPicker'),
        search:         document.getElementById('tagPickerSearch'),
        categories:     document.getElementById('tagPickerCategories'),
        body:           document.getElementById('tagPickerBody'),
        count:          document.getElementById('tagPickerCount'),
        total:          document.getElementById('tagPickerTotal'),
        centerTitle:    document.getElementById('tagPickerCenterTitle'),
        selectedCount:  document.getElementById('tagPickerSelectedCount'),
        selectedList:   document.getElementById('tagPickerSelectedList'),
        selectedEmpty:  document.getElementById('tagPickerSelectedEmpty'),
        closeBtn:       document.getElementById('tagPickerCloseBtn'),
        insertBtn:      document.getElementById('tagPickerInsertBtn'),
        clearBtn:       document.getElementById('tagPickerClearBtn'),
        loadMore:       document.getElementById('tagPickerLoadMoreBtn'),
    };
    if (!_elements.picker) return;

    document.getElementById('tagPickerBtn')?.addEventListener('click', open);
    _elements.closeBtn.addEventListener('click', close);
    _elements.picker.addEventListener('click', (e) => {
        if (e.target === _elements.picker) close();
    });

    // Source tabs
    document.querySelectorAll('.tag-picker-source-tabs button').forEach(b => {
        b.addEventListener('click', () => switchSource(b.dataset.source));
    });

    _elements.search.addEventListener('input', () => {
        _state.query = _elements.search.value.trim();
        debouncedSearch();
    });
    _elements.search.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            insertSelected();
        }
    });

    _elements.insertBtn.addEventListener('click', insertSelected);
    _elements.clearBtn.addEventListener('click', clearSelected);
    _elements.loadMore.addEventListener('click', () => {
        if (_state.source !== 'local') return;
        if (!_state.loading && _state.hasMore) {
            _state.page++;
            loadTags();
        }
    });

    _elements.body.addEventListener('scroll', () => {
        if (_state.source !== 'local') return;
        if (_elements.body.scrollTop + _elements.body.clientHeight >= _elements.body.scrollHeight - 200) {
            if (!_state.loading && _state.hasMore) {
                _state.page++;
                loadTags();
            }
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
}
