/**
 * PresetCombobox - 自定义预设选择下拉
 *
 * 替换浏览器原生 <select>，解决预设多了之后下拉框超出屏幕的问题。
 *
 * 特性：
 * - 搜索框（顶部，可输入过滤）
 * - 分组：★ 收藏 / 全部
 * - 滚动列表（max-height: 280px）
 * - 行内操作：收藏 / 删除（hover 露出）
 * - 触发器显示当前选中
 * - 底部"📋 打开完整预设库"链接
 *
 * 用法：
 *   const cb = createPresetCombobox({
 *       container: document.getElementById('mySelect').parentNode,
 *       getItems: () => getState().posePresets || [],
 *       onSelect: (item) => loadPreset(item),
 *       onOpenManage: () => openManagePanel(),
 *       onToggleFav: async (item) => { ... },
 *       onDelete: async (item) => { ... },
 *   });
 *   cb.refresh();  // 数据变化时调用
 *   cb.setValue(itemId);
 */
export function createPresetCombobox(opts) {
    const cfg = Object.assign({
        getItems: () => [],
        onSelect: () => {},
        onOpenManage: null,
        onToggleFav: null,
        onDelete: null,
        placeholder: '— 选择预设 —',
        manageLabel: '📋 打开完整预设库',
    }, opts);

    // 创建 DOM
    const wrap = document.createElement('div');
    wrap.className = 'preset-combobox';
    wrap.innerHTML = `
        <button type="button" class="preset-cb-trigger">
            <span class="preset-cb-label">${cfg.placeholder}</span>
            <svg class="preset-cb-arrow" width="10" height="10" viewBox="0 0 12 12" fill="none">
                <path d="M2 4l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </button>
        <div class="preset-cb-dropdown hidden">
            <input type="text" class="preset-cb-search" placeholder="🔍 搜索预设..." spellcheck="false">
            <div class="preset-cb-list"></div>
            ${cfg.onOpenManage ? `
                <div class="preset-cb-footer">
                    <button type="button" class="preset-cb-manage-btn">${cfg.manageLabel}</button>
                </div>
            ` : ''}
        </div>
    `;

    const trigger   = wrap.querySelector('.preset-cb-trigger');
    const label     = wrap.querySelector('.preset-cb-label');
    const dropdown  = wrap.querySelector('.preset-cb-dropdown');
    const search    = wrap.querySelector('.preset-cb-search');
    const list      = wrap.querySelector('.preset-cb-list');
    const manageBtn = wrap.querySelector('.preset-cb-manage-btn');

    let _currentId = null;
    let _highlightIdx = 0;
    let _items = [];

    function isOpen() { return !dropdown.classList.contains('hidden'); }
    function open()   { dropdown.classList.remove('hidden'); trigger.classList.add('open'); _highlightIdx = 0; updateHighlight(); setTimeout(() => search.focus(), 30); }
    function close()  { dropdown.classList.add('hidden'); trigger.classList.remove('open'); search.value = ''; renderList(''); }

    function renderList(filter) {
        _items = cfg.getItems();
        const q = (filter || '').toLowerCase().trim();
        const filtered = q
            ? _items.filter(p =>
                  (p.name || '').toLowerCase().includes(q) ||
                  (p.prompt || p.cn_name || '').toLowerCase().includes(q))
            : _items;

        if (_items.length === 0) {
            list.innerHTML = '<div class="preset-cb-empty">还没有预设<br><span style="font-size:11px">保存一个试试 ↓</span></div>';
            return;
        }
        if (filtered.length === 0) {
            list.innerHTML = '<div class="preset-cb-empty">没有匹配的预设</div>';
            return;
        }

        // 分组：★ 收藏 / 全部
        const favs = filtered.filter(p => p.is_favorite);
        const rests = filtered.filter(p => !p.is_favorite);

        let html = '';
        if (favs.length > 0) {
            html += `<div class="preset-cb-group-label">⭐ 收藏 <span class="group-count">${favs.length}</span></div>`;
            for (const p of favs) html += renderOption(p, _items.indexOf(p));
        }
        if (rests.length > 0) {
            html += `<div class="preset-cb-group-label">全部 <span class="group-count">${rests.length}</span></div>`;
            for (const p of rests) html += renderOption(p, _items.indexOf(p));
        }
        list.innerHTML = html;

        // 绑定点击
        list.querySelectorAll('.preset-cb-option').forEach(el => {
            el.addEventListener('click', (e) => {
                if (e.target.closest('.opt-action')) return;
                const id = parseInt(el.dataset.id);
                const p = _items.find(x => x.id === id);
                if (p) {
                    cfg.onSelect(p);
                    _currentId = p.id;
                    updateLabel();
                    close();
                }
            });
            el.querySelector('.opt-fav-btn')?.addEventListener('click', async (e) => {
                e.stopPropagation();
                const id = parseInt(el.dataset.id);
                const p = _items.find(x => x.id === id);
                if (p && cfg.onToggleFav) await cfg.onToggleFav(p);
            });
            el.querySelector('.opt-del-btn')?.addEventListener('click', async (e) => {
                e.stopPropagation();
                const id = parseInt(el.dataset.id);
                const p = _items.find(x => x.id === id);
                if (p && cfg.onDelete) {
                    if (confirm(`删除预设"${p.name}"？`)) await cfg.onDelete(p);
                }
            });
        });

        updateHighlight();
    }

    function renderOption(p, originalIdx) {
        const selected = p.id === _currentId;
        const favBtn = cfg.onToggleFav
            ? `<button class="opt-action opt-fav-btn" title="${p.is_favorite ? '取消收藏' : '收藏'}">${p.is_favorite ? '★' : '☆'}</button>` : '';
        const delBtn = cfg.onDelete
            ? `<button class="opt-action opt-del-btn danger" title="删除">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 7h12m-10 0 .7 13h6.6L16 7M10 7V5h4v2" stroke-linecap="round"/></svg>
            </button>` : '';
        return `
            <div class="preset-cb-option${selected ? ' selected' : ''}" data-id="${p.id}">
                ${p.is_favorite ? '<span class="opt-fav">★</span>' : ''}
                <span class="opt-name" title="${escapeHtml(p.prompt || p.cn_name || p.name || '')}">${escapeHtml(p.name)}</span>
                ${(cfg.onToggleFav || cfg.onDelete) ? `<span class="opt-actions">${favBtn}${delBtn}</span>` : ''}
            </div>
        `;
    }

    function updateLabel() {
        const cur = _items.find(p => p.id === _currentId);
        if (cur) {
            label.innerHTML = (cur.is_favorite ? '<span class="fav-mark">★</span>' : '') + escapeHtml(cur.name);
            label.classList.add('has-value');
        } else {
            label.textContent = cfg.placeholder;
            label.classList.remove('has-value');
        }
    }

    function updateHighlight() {
        const options = list.querySelectorAll('.preset-cb-option');
        options.forEach((el, i) => el.classList.toggle('highlight', i === _highlightIdx));
        if (options[_highlightIdx]) {
            options[_highlightIdx].scrollIntoView({ block: 'nearest' });
        }
    }

    function selectHighlighted() {
        const options = list.querySelectorAll('.preset-cb-option');
        if (options[_highlightIdx]) options[_highlightIdx].click();
    }

    // ===== 事件 =====
    trigger.addEventListener('click', () => { isOpen() ? close() : open(); });
    if (manageBtn) manageBtn.addEventListener('click', () => { close(); cfg.onOpenManage && cfg.onOpenManage(); });
    search.addEventListener('input', () => { renderList(search.value); });
    search.addEventListener('keydown', (e) => {
        const total = list.querySelectorAll('.preset-cb-option').length;
        if (e.key === 'ArrowDown') { e.preventDefault(); _highlightIdx = Math.min(total - 1, _highlightIdx + 1); updateHighlight(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); _highlightIdx = Math.max(0, _highlightIdx - 1); updateHighlight(); }
        else if (e.key === 'Enter') { e.preventDefault(); selectHighlighted(); }
        else if (e.key === 'Escape') { e.preventDefault(); close(); trigger.focus(); }
    });
    document.addEventListener('click', (e) => {
        if (!wrap.contains(e.target) && isOpen()) close();
    });

    // 初始化首次渲染
    renderList('');

    return {
        el: wrap,
        refresh() { renderList(search.value || ''); },
        open, close,
        setValue(id) { _currentId = id; updateLabel(); },
        getValue() { return _currentId; },
    };
}

function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}