/**
 * NAI Studio - Toast notifications
 */

const ICONS = {
    info:    'ⓘ',
    success: '✓',
    warning: '⚠',
    error:   '✕',
};

let _stack;
function getStack() {
    if (!_stack) _stack = document.getElementById('toastStack');
    return _stack;
}

export function toast(message, { type = 'info', duration = 4000, actions = null } = {}) {
    const el = document.createElement('div');
    el.className = `toast ${type}`;
    el.innerHTML = `
        <span class="toast-icon">${ICONS[type] || ICONS.info}</span>
        <span class="toast-text"></span>
        <button class="toast-close" type="button">×</button>
    `;
    el.querySelector('.toast-text').textContent = message;
    if (actions) {
        const actionsEl = document.createElement('div');
        actionsEl.className = 'toast-actions';
        actionsEl.style.display = 'flex';
        actionsEl.style.gap = '4px';
        actions.forEach(a => {
            const btn = document.createElement('button');
            btn.className = 'link-button';
            btn.textContent = a.label;
            btn.onclick = () => { try { a.onClick(); } finally { dismiss(); } };
            actionsEl.appendChild(btn);
        });
        el.appendChild(actionsEl);
    }
    function dismiss() {
        el.classList.add('dismissing');
        setTimeout(() => el.remove(), 200);
    }
    el.querySelector('.toast-close').onclick = dismiss;
    getStack().appendChild(el);
    if (duration > 0) {
        setTimeout(dismiss, duration);
    }
    return { dismiss };
}
