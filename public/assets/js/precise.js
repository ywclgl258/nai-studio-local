/**
 * NAI Studio - Precise References (V4+ character / style references)
 */

import { getState, setState, subscribe } from './state.js?v=104';
import { api } from './api.js?v=104';
import { toast } from './toast.js?v=104';
import { saveLocal } from './storage.js?v=104';

function newId() { return 'p_' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6); }

function renderPrecise(item) {
    const div = document.createElement('div');
    div.className = 'precise-item';
    div.innerHTML = `
        <img alt="precise">
        <div class="info">
            <div class="name"></div>
            <div class="strength-row">
                <span>类型</span>
                <select class="type-select">
                    <option value="character">角色</option>
                    <option value="style">画风</option>
                </select>
                <span>强度</span>
                <input type="range" min="0" max="1" step="0.05" value="${item.strength ?? 0.6}">
                <strong class="strength-val">${(item.strength ?? 0.6).toFixed(2)}</strong>
            </div>
        </div>
        <div class="actions">
            <button class="icon-button small ghost" data-action="remove" title="移除">
                <svg viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12"/></svg>
            </button>
        </div>
    `;
    const img = div.querySelector('img');
    img.src = item.path;
    div.querySelector('.name').textContent = item.name || item.path.split('/').pop();
    const select = div.querySelector('.type-select');
    select.value = item.type || 'character';
    select.addEventListener('change', () => { item.type = select.value; saveLocal(); });
    const range = div.querySelector('input');
    const valEl = div.querySelector('.strength-val');
    range.addEventListener('input', () => {
        const v = parseFloat(range.value);
        item.strength = v;
        valEl.textContent = v.toFixed(2);
        saveLocal();
    });
    div.querySelector('[data-action="remove"]').addEventListener('click', () => {
        setState({ preciseRefs: getState().preciseRefs.filter(p => p.id !== item.id) });
        saveLocal();
    });
    return div;
}

function renderAll() {
    const list = document.getElementById('preciseList');
    if (!list) return;
    list.innerHTML = '';
    for (const p of getState().preciseRefs) list.appendChild(renderPrecise(p));
}

async function addFile(file) {
    try {
        const r = await api.upload(file);
        const item = {
            id: newId(),
            path: r.path,
            name: file.name.replace(/\.[^.]+$/, ''),
            type: 'character',
            strength: 0.6,
            info_extracted: r.info || null,
        };
        setState({ preciseRefs: [...getState().preciseRefs, item] });
        saveLocal();
        toast('已添加精确参考', { type: 'success' });
    } catch (e) {
        toast('上传失败: ' + e.message, { type: 'error' });
    }
}

export function initPrecise() {
    const input = document.getElementById('preciseInput');
    const addBtn = document.getElementById('addPreciseBtn');
    addBtn?.addEventListener('click', () => input?.click());
    input?.addEventListener('change', () => {
        for (const f of input.files) addFile(f);
        input.value = '';
    });

    const panel = document.getElementById('precisePanel');
    if (panel) {
        panel.addEventListener('dragover', (e) => { e.preventDefault(); panel.classList.add('drag-over'); });
        panel.addEventListener('dragleave', () => panel.classList.remove('drag-over'));
        panel.addEventListener('drop', (e) => {
            e.preventDefault();
            panel.classList.remove('drag-over');
            for (const f of e.dataTransfer.files) {
                if (f.type.startsWith('image/')) addFile(f);
            }
        });
    }

    subscribe(['preciseRefs'], renderAll);
    renderAll();
}
