/**
 * NAI Studio - AI 写提示词 V4 (互动式)
 *
 * 多轮对话：
 *   - 用户输入 → API → 显示回复
 *   - 检测 ```prompt ... ``` 代码块 → 提取 NAI prompt + 显示"📋 写入主输入框"按钮
 *   - 加载历史对话
 *
 * 目标模型选择（localStorage 持久化）：
 *   - curated → NAI V4.5 Curated（默认，严格 Danbooru + artist:xxx）
 *   - full    → NAI V4.5 Full（通用，写实友好）
 *   - auto    → 自动判断（用通用预设）
 */

import { api } from './api.js';
import { toast } from './toast.js';
import { getState, setState } from './state.js';

let _els = {};
let _history = [];   // [{role, content}, ...]
let _busy = false;
const _MODEL_KEY = 'nai.aiCompose.targetModel';

const _MODELS = {
    curated: { label: 'V4.5 Curated', icon: '🌸', desc: '动漫/插画专精，严格 Danbooru，artist:xxx 必需' },
    full:    { label: 'V4.5 Full',    icon: '🎨', desc: '通用模型，写实/油画/概念艺术友好' },
    auto:    { label: '自动',          icon: '🤖', desc: '让 AI 根据描述自动判断' },
};

function escapeHtml(s) {
    return (s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[c]));
}

/** 把后端 reply 渲染成 HTML（识别 ```prompt ... ``` 代码块、保留普通 markdown） */
function renderReply(raw) {
    let html = escapeHtml(raw);
    // ```prompt ... ``` 高亮提取
    html = html.replace(/```prompt\s*([\s\S]*?)```/gi, (m, code) => {
        return `<div class="acm-prompt-block" contenteditable="false">
            <div class="acm-prompt-label">📋 NAI Prompt</div>
            <pre class="acm-prompt-code">${escapeHtml(code.trim())}</pre>
            <button class="ghost-button small acm-apply-prompt" data-prompt="${escapeHtml(code.trim())}">→ 写入主输入框</button>
        </div>`;
    });
    // 其他 ```...``` 代码块
    html = html.replace(/```([\s\S]*?)```/g, (m, code) => {
        return `<pre class="acm-code">${escapeHtml(code)}</pre>`;
    });
    // 简单加粗
    html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    // 换行 → <br>
    html = html.replace(/\n/g, '<br>');
    return html;
}

function renderMessages() {
    const wrap = _els.messages;
    if (!wrap) return;
    if (_history.length === 0) {
        wrap.innerHTML = '';
        // 重新插入 welcome
        const w = document.createElement('div');
        w.className = 'acm-welcome';
        w.innerHTML = `
            <div class="acm-welcome-icon">✨</div>
            <h3>开始对话</h3>
            <p>描述你想要的画面，AI 会生成 NAI 提示词。你可以：</p>
            <ul>
                <li>🖊 描述场景 → 拿完整 NAI prompt</li>
                <li>🔄 "加个樱花背景" → 增量调整</li>
                <li>💡 "为什么用 ciloranko" → 解释建议</li>
                <li>📋 把生成的 prompt 一键写入主输入框</li>
            </ul>
            <p class="acm-welcome-hint">提示：可到「设置 → AI 顾问」选 DeepSeek V4 Pro / Flash，配 API key</p>
        `;
        wrap.appendChild(w);
        return;
    }
    wrap.innerHTML = '';
    for (const m of _history) {
        const row = document.createElement('div');
        row.className = 'acm-msg acm-msg-' + m.role;
        const bubble = document.createElement('div');
        bubble.className = 'acm-bubble';
        if (m.role === 'user') {
            bubble.textContent = m.content;
        } else {
            bubble.innerHTML = renderReply(m.content);
        }
        row.appendChild(bubble);
        wrap.appendChild(row);
    }
    // 绑定 prompt 应用按钮
    wrap.querySelectorAll('.acm-apply-prompt').forEach(btn => {
        btn.addEventListener('click', () => applyPromptToMain(btn.dataset.prompt));
    });
    // 滚到底
    wrap.scrollTop = wrap.scrollHeight;
}

function setStatus(text, type = '') {
    if (_els.status) {
        _els.status.textContent = text;
        _els.status.className = 'acm-status' + (type ? ' ' + type : '');
    }
}

async function applyPromptToMain(prompt) {
    const ta = document.getElementById('promptInput') || document.getElementById('mainPromptInput') || document.querySelector('.prompt-input');
    if (!ta) { toast('找不到主输入框', { type: 'error' }); return; }
    // 询问：替换 / 追加
    let action = 'replace';
    if (ta.value.trim()) {
        action = confirm('已存在主输入框内容：\n- 点「确定」追加到末尾\n- 点「取消》替换全部') ? 'append' : 'replace';
    }
    if (action === 'replace') {
        ta.value = prompt;
    } else {
        const sep = ta.value.match(/[,，]\s*$/) ? '' : ', ';
        ta.value = ta.value + sep + prompt;
    }
    ta.dispatchEvent(new Event('input', { bubbles: true }));
    toast('✓ 已写入主输入框', { type: 'success' });
}

async function send() {
    if (_busy) return;
    const text = (_els.input?.value || '').trim();
    if (!text) { toast('请输入内容', { type: 'warning' }); return; }
    _els.input.value = '';
    _history.push({ role: 'user', content: text });
    renderMessages();
    _busy = true;
    const model = getTargetModel();
    setStatus('AI 思考中…（目标: ' + _MODELS[model].label + '）', 'thinking');
    try {
        const r = await api.aiCompose(_history, model);
        _history.push({ role: 'assistant', content: r.reply || '（无回复）' });
        setStatus('✓ ' + (r.model || 'AI') + ' · ' + r.ms + 'ms · 目标 ' + _MODELS[r.target || model].label, 'ok');
        renderMessages();
    } catch (e) {
        setStatus('✗ ' + e.message, 'err');
        toast('AI 出错了: ' + e.message, { type: 'error', duration: 6000 });
    } finally {
        _busy = false;
    }
}

function getTargetModel() {
    const saved = localStorage.getItem(_MODEL_KEY);
    return _MODELS[saved] ? saved : 'curated';
}

function setTargetModel(model) {
    if (!_MODELS[model]) return;
    localStorage.setItem(_MODEL_KEY, model);
    renderModelChips();
    setStatus('✓ 已切换目标模型：' + _MODELS[model].label, 'ok');
}

function renderModelChips() {
    const wrap = _els.modelChips;
    if (!wrap) return;
    const current = getTargetModel();
    wrap.innerHTML = '';
    for (const [key, m] of Object.entries(_MODELS)) {
        const btn = document.createElement('button');
        btn.className = 'acm-model-chip' + (key === current ? ' active' : '');
        btn.type = 'button';
        btn.innerHTML = `<span class="acm-model-icon">${m.icon}</span><span class="acm-model-label">${m.label}</span><span class="acm-model-desc">${m.desc}</span>`;
        btn.title = m.desc;
        btn.addEventListener('click', () => setTargetModel(key));
        wrap.appendChild(btn);
    }
}

function open() {
    _els.modal.classList.remove('hidden');
    setTimeout(() => _els.input?.focus(), 50);
}
function close() {
    _els.modal.classList.add('hidden');
}
function clearAll() {
    if (_history.length === 0) return;
    if (!confirm('清空当前对话？')) return;
    _history = [];
    renderMessages();
    setStatus('已清空');
}

export function initAiCompose() {
    _els = {
        modal:       document.getElementById('aiComposeModal'),
        closeBtn:    document.getElementById('closeAiComposeBtn'),
        messages:    document.getElementById('acmMessages'),
        input:       document.getElementById('acmInput'),
        sendBtn:     document.getElementById('acmSendBtn'),
        clearBtn:    document.getElementById('acmClearBtn'),
        status:      document.getElementById('acmStatus'),
        modelChips:  document.getElementById('acmModelChips'),
    };
    if (!_els.modal) return;

    document.getElementById('aiComposeBtn')?.addEventListener('click', open);
    _els.closeBtn.addEventListener('click', close);
    _els.modal.addEventListener('click', e => { if (e.target === _els.modal) close(); });
    _els.sendBtn.addEventListener('click', send);
    _els.clearBtn.addEventListener('click', clearAll);

    _els.input.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            send();
        }
    });

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && !_els.modal.classList.contains('hidden')) close();
    });

    renderModelChips();
    renderMessages();
}
