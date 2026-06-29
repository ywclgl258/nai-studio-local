/**
 * NAI Studio - Prompt editor with NAI syntax highlighting
 * Supports: {tag}, {tag:weight}, (tag), [tag], and Unicode
 */

import { getState, setState, subscribe } from './state.js';

const QUALITY_TAGS = [
    'masterpiece, best quality, amazing quality, very aesthetic, absurdres',
    'masterpiece, best quality, highres, original, detailed background',
    'high quality, detailed, beautiful, aesthetic, score_9, score_8_up, score_7_up',
];

const RE_TOKEN = /(\{[^}]*\}|\([^)]*\)|\[[^\]]*\]|,\s*|[^,{}()[\]]+)/g;

function highlight(text) {
    if (!text) return '';
    // Escape HTML
    const safe = text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    return safe.replace(RE_TOKEN, (m) => {
        if (m.startsWith('{')) {
            // positive emphasis
            const inner = m.slice(1, -1);
            const colonIdx = inner.lastIndexOf(':');
            if (colonIdx > 0) {
                return `<span class="ph-weight">{</span>${escapeHtmlExceptTags(inner.slice(0, colonIdx))}<span class="ph-weight">:${escapeHtmlExceptTags(inner.slice(colonIdx + 1))}}</span>`;
            }
            return `<span class="ph-weight">{</span>${escapeHtmlExceptTags(inner)}<span class="ph-weight">}</span>`;
        }
        if (m.startsWith('(')) {
            const inner = m.slice(1, -1);
            const colonIdx = inner.lastIndexOf(':');
            if (colonIdx > 0) {
                return `<span class="ph-weight">(</span>${escapeHtmlExceptTags(inner.slice(0, colonIdx))}<span class="ph-weight">:${escapeHtmlExceptTags(inner.slice(colonIdx + 1))}}</span>`;
            }
            return `<span class="ph-weight">(</span>${escapeHtmlExceptTags(inner)}<span class="ph-weight">)</span>`;
        }
        if (m.startsWith('[')) {
            const inner = m.slice(1, -1);
            const colonIdx = inner.lastIndexOf(':');
            if (colonIdx > 0) {
                return `<span class="ph-weight">[</span>${escapeHtmlExceptTags(inner.slice(0, colonIdx))}<span class="ph-weight">:${escapeHtmlExceptTags(inner.slice(colonIdx + 1))}</span></span>`;
            }
            return `<span class="ph-weight">[</span>${escapeHtmlExceptTags(inner)}<span class="ph-weight">]</span>`;
        }
        if (/^\s*,\s*$/.test(m)) {
            return `<span class="ph-text">${m}</span>`;
        }
        // Plain text — check for quality tags
        const isQuality = /(masterpiece|best quality|high quality|amazing quality|absurdres)/i.test(m);
        if (isQuality) {
            return `<span class="ph-quality">${m}</span>`;
        }
        return `<span class="ph-text">${m}</span>`;
    });
}

function escapeHtmlExceptTags(s) {
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function updateHighlight(textarea, highlightEl, enabled) {
    if (!enabled) {
        highlightEl.innerHTML = '';
        return;
    }
    const text = textarea.value;
    const html = highlight(text) + '\n';
    if (highlightEl.innerHTML !== html) {
        highlightEl.innerHTML = html;
    }
    // Sync scroll
    highlightEl.scrollTop = textarea.scrollTop;
    highlightEl.scrollLeft = textarea.scrollLeft;
}

export function initPromptEditor() {
    const promptInput = document.getElementById('promptInput');
    const negativeInput = document.getElementById('negativeInput');
    const promptHighlight = document.getElementById('promptHighlight');
    const negativeHighlight = document.getElementById('negativeHighlight');

    if (!promptInput || !negativeInput) return;

    // Set initial state
    const s = getState();
    promptInput.value = s.prompt || '';
    negativeInput.value = s.negativePrompt || '';

    // Apply quality tags on first interaction if empty
    function maybeApplyQuality() {
        if (!s.qualityToggle) return;
        if (!promptInput.value) {
            promptInput.value = 'masterpiece, best quality, ';
        }
    }

    promptInput.addEventListener('input', () => {
        setState({ prompt: promptInput.value });
        updateHighlight(promptInput, promptHighlight, getState().emphasisHighlight);
    });
    promptInput.addEventListener('scroll', () => {
        promptHighlight.scrollTop = promptInput.scrollTop;
        promptHighlight.scrollLeft = promptInput.scrollLeft;
    });
    promptInput.addEventListener('focus', maybeApplyQuality);

    negativeInput.addEventListener('input', () => {
        setState({ negativePrompt: negativeInput.value });
        updateHighlight(negativeInput, negativeHighlight, getState().emphasisHighlight);
    });
    negativeInput.addEventListener('scroll', () => {
        negativeHighlight.scrollTop = negativeInput.scrollTop;
        negativeHighlight.scrollLeft = negativeInput.scrollLeft;
    });

    // Quality weight toggle / preset
    const qualityBtn = document.getElementById('qualityPresetBtn');
    const qualityWeight = document.getElementById('qualityWeight');
    qualityWeight.addEventListener('input', () => {
        setState({ qualityWeight: parseFloat(qualityWeight.value) });
    });
    qualityBtn.addEventListener('click', () => {
        qualityBtn.classList.toggle('active');
        const idx = qualityBtn.classList.contains('active') ? 0 : -1;
        if (idx >= 0 && !promptInput.value.includes('masterpiece')) {
            promptInput.value = QUALITY_TAGS[idx] + (promptInput.value ? ', ' + promptInput.value : '');
        }
        setState({ prompt: promptInput.value });
        updateHighlight(promptInput, promptHighlight, getState().emphasisHighlight);
    });

    // Tab switching
    document.querySelectorAll('.prompt-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            const target = tab.dataset.promptTab;
            document.querySelectorAll('.prompt-tab').forEach(t => t.classList.toggle('active', t === tab));
            // #promptPresetRow 现在放在 #promptEditor 内部，所以跟 promptEditor 一起 toggle
            document.getElementById('promptEditor').classList.toggle('hidden', target !== 'prompt');
            document.getElementById('negativeEditor').classList.toggle('hidden', target !== 'negative');
            document.getElementById('charactersEditor').classList.toggle('hidden', target !== 'characters');
            document.getElementById('poseEditor')?.classList.toggle('hidden', target !== 'pose');
        });
    });

    // Subscribe to state changes that affect highlighting
    subscribe(['emphasisHighlight', 'prompt', 'negativePrompt'], () => {
        const s2 = getState();
        updateHighlight(promptInput, promptHighlight, s2.emphasisHighlight);
        updateHighlight(negativeInput, negativeHighlight, s2.emphasisHighlight);
    });

    // Initial highlight
    updateHighlight(promptInput, promptHighlight, s.emphasisHighlight);
    updateHighlight(negativeInput, negativeHighlight, s.emphasisHighlight);
    qualityWeight.value = s.qualityWeight;
}

export function insertAtCursor(text, value) {
    const start = text.selectionStart;
    const end = text.selectionEnd;
    const before = text.value.slice(0, start);
    const after = text.value.slice(end);
    const sep = (before && !before.endsWith(',') && !before.endsWith(' ')) ? ', ' : '';
    const newText = before + sep + value + after;
    text.value = newText;
    const newPos = start + sep.length + value.length;
    text.setSelectionRange(newPos, newPos);
    text.focus();
    text.dispatchEvent(new Event('input', { bubbles: true }));
}
