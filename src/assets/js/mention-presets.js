/**
 * NAI Studio - @-mention 风格预设插入
 *
 * 在 textarea 内输 `/` 弹候选浮窗：
 *   - 输入 `/折` → 浮窗过滤出"折叠式"等
 *   - ↑/↓ 切换
 *   - Enter 选中 → 把 `/折` 替换成预设的 prompt 内容
 *   - Esc 关闭
 *   - 失去焦点/输入空格/删除 / → 关闭
 *
 * 用法（自动绑定）：
 *   给 textarea 加 data-mention-kind="pose|character|prompt" 即可
 *   也可以手动：attachMention(textarea, 'pose')
 *
 * 设计：单一浮窗 DOM（body 直接挂），所有 textarea 共享。
 */

import { getState, subscribe } from './state.js';
import { toast } from './toast.js';

let _popup = null;            // 单例浮窗
let _active = null;           // { textarea, kind, query, start, end, items, idx }
let _isComposing = false;     // 中文输入法进行中

const KIND_TO_STATE = {
    pose:      () => getState().posePresets || [],
    character: () => getState().characterPresets || [],
    prompt:    () => getState().promptPresets || [],
    negative:  () => getState().promptPresets || [],  // 主提示词 / 负面共用同一池
};

function ensurePopup() {
    if (_popup) return _popup;
    const el = document.createElement('div');
    el.className = 'mention-popup hidden';
    el.innerHTML = `
        <div class="mention-popup-hint">
            <span class="mp-tip">↑↓ 选 · Enter 插入 · Esc 关闭</span>
        </div>
        <div class="mention-popup-list"></div>
        <div class="mention-popup-footer hidden">
            <span class="mp-preview"></span>
        </div>
    `;
    document.body.appendChild(el);
    _popup = el;

    // 鼠标 hover 不关闭
    el.addEventListener('mouseenter', () => { _active && (_active.hover = true); });
    el.addEventListener('mouseleave', () => { _active && (_active.hover = false); });

    // 全局点击 → 关闭
    document.addEventListener('mousedown', (e) => {
        if (!_active) return;
        if (_popup.contains(e.target)) return;
        if (e.target === _active.textarea) return;
        close();
    });
    return el;
}

function getCaretCoords(textarea, pos) {
    // 用 mirror div 算 caret 坐标（textarea 自带的不可靠）
    const div = document.createElement('div');
    const cs = getComputedStyle(textarea);
    // 复制样式
    const props = ['fontSize','fontFamily','fontWeight','lineHeight','letterSpacing',
                   'paddingTop','paddingRight','paddingBottom','paddingLeft',
                   'borderTopWidth','borderRightWidth','borderBottomWidth','borderLeftWidth',
                   'boxSizing','wordWrap','whiteSpace','wordBreak','tabSize'];
    for (const p of props) div.style[p] = cs[p];
    div.style.position = 'absolute';
    div.style.visibility = 'hidden';
    div.style.whiteSpace = 'pre-wrap';
    div.style.width = textarea.clientWidth + 'px';
    div.style.top = '-9999px';
    div.style.left = '0';

    const before = textarea.value.substring(0, pos);
    div.textContent = before;
    // 末尾 span 模拟光标
    const span = document.createElement('span');
    span.textContent = textarea.value.substring(pos) || '.';
    div.appendChild(span);
    document.body.appendChild(div);

    const rect = textarea.getBoundingClientRect();
    const ta = document.createElement('textarea');
    ta.style.cssText = 'position:absolute;visibility:hidden;width:0;height:0';
    document.body.appendChild(ta);

    const top = span.offsetTop - textarea.scrollTop;
    const left = span.offsetLeft - textarea.scrollLeft;
    document.body.removeChild(div);
    document.body.removeChild(ta);

    return {
        x: rect.left + window.scrollX + left,
        y: rect.top + window.scrollY + top,
        h: parseFloat(cs.lineHeight) || 20,
    };
}

function findTrigger(textarea) {
    // 光标往前找最近空白/标点/行首，匹配 /xxx
    const pos = textarea.selectionStart;
    const text = textarea.value.substring(0, pos);
    // 允许: /xxx 后面任意非空白/中文字符继续（中文不阻断，因为中文可能粘在 / 后）
    // 实际上很多 tag 是英文+下划线，用 \w
    // 用 RegExp 构造器（不能写 /literal/，因为字符类里的 / 会让 parser 提前结束 regex）
    const re = new RegExp('(^|[^\\w])(/[A-Za-z0-9_]*)$');
    const m = text.match(re);
    if (!m) return null;
    const query = m[2]; // 含 /
    const start = pos - query.length;
    return { query, start, end: pos };
}

function getCandidates(kind) {
    const fn = KIND_TO_STATE[kind];
    if (!fn) return [];
    const all = fn();
    // 收藏靠前，按名字升序
    return all.slice().sort((a, b) => {
        if ((b.is_favorite ? 1 : 0) !== (a.is_favorite ? 1 : 0)) {
            return (b.is_favorite ? 1 : 0) - (a.is_favorite ? 1 : 0);
        }
        return (a.name || '').localeCompare(b.name || '');
    });
}

function renderList() {
    if (!_active) return;
    const list = _popup.querySelector('.mention-popup-list');
    const q = _active.query.slice(1).toLowerCase();
    const filtered = q
        ? _active.items.filter(p =>
              (p.name || '').toLowerCase().includes(q) ||
              (p.prompt || p.cn_name || '').toLowerCase().includes(q))
        : _active.items;
    _active.filtered = filtered;

    if (filtered.length === 0) {
        list.innerHTML = `<div class="mention-empty">没有匹配的 ${_active.kind} 预设<br><span class="mention-empty-hint">试试别的关键字，或先保存一个</span></div>`;
        _popup.querySelector('.mention-popup-footer').classList.add('hidden');
        return;
    }
    list.innerHTML = filtered.slice(0, 50).map((p, i) => `
        <div class="mention-opt${i === _active.idx ? ' active' : ''}" data-i="${i}">
            <span class="mention-opt-fav">${p.is_favorite ? '★' : '☆'}</span>
            <span class="mention-opt-name">${escapeHtml(p.name || '(无名)')}</span>
            <span class="mention-opt-preview">${escapeHtml((p.prompt || p.cn_name || '').substring(0, 60))}</span>
        </div>
    `).join('');
    list.querySelectorAll('.mention-opt').forEach(el => {
        el.addEventListener('click', () => {
            _active.idx = parseInt(el.dataset.i);
            insertSelected();
        });
        el.addEventListener('mousemove', () => {
            _active.idx = parseInt(el.dataset.i);
            updateActive();
        });
    });
    updateActive();
    updatePreview();
}

function updateActive() {
    if (!_active) return;
    _popup.querySelectorAll('.mention-opt').forEach((el, i) => {
        el.classList.toggle('active', i === _active.idx);
    });
    const opt = _active.filtered[_active.idx];
    if (opt) {
        const el = _popup.querySelector(`.mention-opt[data-i="${_active.idx}"]`);
        if (el) el.scrollIntoView({ block: 'nearest' });
    }
    updatePreview();
}

function updatePreview() {
    const opt = _active && _active.filtered && _active.filtered[_active.idx];
    const footer = _popup.querySelector('.mention-popup-footer');
    const preview = _popup.querySelector('.mp-preview');
    if (!opt) {
        footer.classList.add('hidden');
        return;
    }
    footer.classList.remove('hidden');
    const text = (opt.prompt || opt.cn_name || '').replace(/\n/g, ' ');
    preview.textContent = text.length > 200 ? text.substring(0, 200) + '…' : text;
}

function show(textarea) {
    const kind = textarea.dataset.mentionKind;
    if (!kind) return;
    const trigger = findTrigger(textarea);
    if (!trigger) { close(); return; }

    if (!_active || _active.textarea !== textarea || _active.query !== trigger.query) {
        _active = {
            textarea, kind,
            query: trigger.query,
            start: trigger.start,
            end: trigger.end,
            items: getCandidates(kind),
            filtered: [],
            idx: 0,
            hover: false,
        };
    } else {
        _active.query = trigger.query;
        _active.start = trigger.start;
        _active.end = trigger.end;
    }

    ensurePopup();
    renderList();

    // 定位
    const coords = getCaretCoords(textarea, _active.end);
    const popupW = 380;
    const popupMaxH = 320;
    let left = coords.x;
    const maxLeft = window.innerWidth - popupW - 8;
    if (left > maxLeft) left = maxLeft;
    if (left < 8) left = 8;
    let top = coords.y + coords.h + 4;
    const maxTop = window.innerHeight - popupMaxH - 8;
    if (top > maxTop + window.scrollY) {
        // 弹窗显示在光标上方
        top = coords.y - popupMaxH - 4 + window.scrollY;
    }
    _popup.style.left = left + 'px';
    _popup.style.top = top + 'px';
    _popup.style.width = popupW + 'px';
    _popup.classList.remove('hidden');
}

function close() {
    if (_popup) _popup.classList.add('hidden');
    _active = null;
}

function insertSelected() {
    if (!_active) return;
    const opt = _active.filtered[_active.idx];
    if (!opt) return;
    const ta = _active.textarea;
    const insertText = (opt.prompt || opt.cn_name || '').trim();
    if (!insertText) {
        toast('该预设没有内容', { type: 'warn' });
        close();
        return;
    }
    const before = ta.value.substring(0, _active.start);
    const after  = ta.value.substring(_active.end);
    // 智能拼接：避免相邻重复
    let glue = '';
    if (before.length && !/[,\s]/.test(before.slice(-1)) && !/^[,\s]/.test(insertText)) {
        glue = ', ';
    }
    // 如果新插入的 prompt 末尾没有逗号，而 after 又直接是词，加逗号
    let needTrailingComma = false;
    if (after.length && !/^[,\s]/.test(after) && !/[,\s]$/.test(insertText)) {
        needTrailingComma = true;
    }
    const newText = before + glue + insertText + (needTrailingComma ? ', ' : '') + after;
    const newCaret = before.length + glue.length + insertText.length + (needTrailingComma ? 2 : 0);

    // 触发 input event 让现有 listener（比如 NAI 高亮）更新
    ta.value = newText;
    ta.setSelectionRange(newCaret, newCaret);
    ta.dispatchEvent(new Event('input', { bubbles: true }));
    ta.focus();
    close();
    toast(`已插入「${opt.name}」`, { type: 'success', duration: 1500 });
}

function onInput(e) {
    if (_isComposing) return;
    const ta = e.target;
    if (!(ta instanceof HTMLTextAreaElement)) return;
    if (!ta.dataset.mentionKind) return;
    show(ta);
}

function onKeyDown(e) {
    if (!_active) return;
    if (e.key === 'ArrowDown') {
        if (_active.filtered.length > 0) {
            e.preventDefault();
            _active.idx = Math.min(_active.filtered.length - 1, _active.idx + 1);
            updateActive();
        }
    } else if (e.key === 'ArrowUp') {
        if (_active.filtered.length > 0) {
            e.preventDefault();
            _active.idx = Math.max(0, _active.idx - 1);
            updateActive();
        }
    } else if (e.key === 'Enter' || e.key === 'Tab') {
        if (_active.filtered.length > 0) {
            e.preventDefault();
            insertSelected();
        }
    } else if (e.key === 'Escape') {
        e.preventDefault();
        close();
    } else if (e.key === ' ' || e.key === '，' || e.key === ',') {
        // 空格/中文逗号后，关闭浮窗（不阻止输入）
        close();
    }
}

function onSelectionChange() {
    if (!_active) return;
    if (_active.hover) return;
    const ta = document.activeElement;
    if (ta !== _active.textarea) { close(); return; }
    // 光标位置变了：重新检查 trigger
    const trigger = findTrigger(ta);
    if (!trigger) { close(); return; }
    if (trigger.query !== _active.query) {
        show(ta);
    }
}

function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

let _installed = false;
function install() {
    if (_installed) return;
    _installed = true;
    // 委托所有 textarea
    document.addEventListener('input', onInput, true);
    document.addEventListener('keydown', onKeyDown, true);
    // 中文输入法状态
    document.addEventListener('compositionstart', () => { _isComposing = true; });
    document.addEventListener('compositionend', (e) => {
        _isComposing = false;
        if (e.target instanceof HTMLTextAreaElement && e.target.dataset.mentionKind) {
            show(e.target);
        }
    });
    document.addEventListener('selectionchange', onSelectionChange);
    // 状态变化时刷新（如果浮窗还开着）
    // subscribe(keys, fn) 第一个参数是要监听的 state key 名（字符串或数组）
    subscribe(['posePresets', 'characterPresets', 'promptPresets'], () => {
        if (_active) {
            _active.items = getCandidates(_active.kind);
            renderList();
        }
    });

    // 自动绑定所有现有 + 未来 textarea 带 data-mention-kind
    const obs = new MutationObserver((muts) => {
        for (const m of muts) {
            m.addedNodes.forEach(n => {
                if (!(n instanceof HTMLElement)) return;
                if (n.matches?.('textarea[data-mention-kind]')) {
                    // 已自动绑定（不需要显式 attachMention）
                }
                n.querySelectorAll?.('textarea[data-mention-kind]').forEach(ta => {
                    // 也无需 attach，对所有有 data-mention-kind 的 textarea 通用
                });
            });
        }
    });
    obs.observe(document.body, { childList: true, subtree: true });
}

// 任何导入这个模块的地方都自动装
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', install);
} else {
    install();
}

export function attachMention(textarea, kind) {
    if (!textarea) return;
    install();
    textarea.dataset.mentionKind = kind || textarea.dataset.mentionKind || 'pose';
}

// 自动 install：模块被 import 即生效
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', install);
} else {
    install();
}

export function closeMention() { close(); }
