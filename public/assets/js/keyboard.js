/**
 * NAI Studio - Keyboard shortcuts
 */

import { getState } from './state.js';
import { api } from './api.js';
import { toast } from './toast.js';

function isInputFocused() {
    const el = document.activeElement;
    if (!el) return false;
    const tag = el.tagName;
    return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || el.isContentEditable;
}

export function initKeyboard() {
    document.addEventListener('keydown', (e) => {
        // Ctrl+Enter = generate
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            e.preventDefault();
            document.getElementById('generateBtn')?.click();
            return;
        }
        // Ctrl+S = save preset
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            if (isInputFocused()) return;
            e.preventDefault();
            document.getElementById('presetSaveBtn')?.click();
            return;
        }
        // Ctrl+Shift+R = reset workbench
        if ((e.ctrlKey || e.metaKey) && e.shiftKey && (e.key === 'R' || e.key === 'r')) {
            e.preventDefault();
            if (confirm('重置工作台？')) {
                window.dispatchEvent(new CustomEvent('nai:reset-workbench'));
            }
            return;
        }
        // T = open tag picker (when not in input)
        if (e.key === 't' && !e.ctrlKey && !e.metaKey && !isInputFocused()) {
            e.preventDefault();
            document.getElementById('tagPickerBtn')?.click();
            return;
        }
        // Esc = close modals
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-backdrop:not(.hidden)').forEach(m => m.classList.add('hidden'));
            const tp = document.getElementById('tagPicker');
            if (tp && !tp.classList.contains('hidden')) tp.classList.add('hidden');
            const fp = document.getElementById('promptSettingsPanel');
            if (fp && !fp.classList.contains('hidden')) fp.classList.add('hidden');
            const me = document.getElementById('maskEditor');
            if (me && !me.classList.contains('hidden')) me.classList.add('hidden');
            return;
        }
        // ? = show shortcuts
        if (e.key === '?' && !isInputFocused()) {
            showHelp();
        }
    });
}

function showHelp() {
    const help = `
        <h3>快捷键</h3>
        <ul style="line-height:1.8;font-size:13px">
            <li><kbd>Ctrl</kbd>+<kbd>Enter</kbd> — 生图</li>
            <li><kbd>Ctrl</kbd>+<kbd>S</kbd> — 保存为预设</li>
            <li><kbd>Ctrl</kbd>+<kbd>Shift</kbd>+<kbd>R</kbd> — 重置工作台</li>
            <li><kbd>T</kbd> — 打开标签库</li>
            <li><kbd>Esc</kbd> — 关闭弹窗</li>
            <li><kbd>?</kbd> — 显示此帮助</li>
        </ul>
    `;
    toast(help, { type: 'info', duration: 8000 });
}
