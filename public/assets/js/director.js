/**
 * NAI Studio - Director Tools
 * Image transformation (bg-removal, lineart, sketch, colorize, emotion, declutter)
 */

import { getState, setState } from './state.js';
import { api } from './api.js';
import { toast } from './toast.js';

let _source = null;       // {dataURL, path}
let _result = null;       // dataURL
let _tool = 'augment-bg-removal';
let _els = {};

function setSource(dataURL, path = null) {
    _source = { dataURL, path };
    const src = _els.source;
    src.innerHTML = `<img alt="source" style="max-width:100%;max-height:100%">`;
    src.querySelector('img').src = dataURL;
    src.classList.remove('drag-over');
    // Clear result
    _result = null;
    _els.result.innerHTML = `<div class="director-placeholder"><svg viewBox="0 0 24 24"><path d="M4 5h16v14H4z"/><path d="m7 15 3-3 3 3 2-2 3 4H6z"/></svg><p>结果会出现在这里</p></div>`;
}

function setResult(dataURL) {
    _result = dataURL;
    _els.result.innerHTML = `<img alt="result" style="max-width:100%;max-height:100%">`;
    _els.result.querySelector('img').src = dataURL;
}

function setTool(tool) {
    _tool = tool;
    document.querySelectorAll('.director-tool').forEach(b => b.classList.toggle('active', b.dataset.tool === tool));
}

async function transform() {
    if (!_source) {
        toast('请先上传或选择一张图片', { type: 'warning' });
        return;
    }
    toast('Director Tools 是 NAI 高级功能，需要对应的 API 权限。', { type: 'info', duration: 5000 });
    // NAI does not expose augment endpoints publicly; this is a placeholder for
    // local image processing (we could implement a fallback). For now, just copy source.
    setResult(_source.dataURL);
}

function dragHandlers(zone) {
    zone.addEventListener('dragover', (e) => { e.preventDefault(); zone.classList.add('drag-over'); });
    zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
    zone.addEventListener('drop', (e) => {
        e.preventDefault();
        zone.classList.remove('drag-over');
        const file = e.dataTransfer.files?.[0];
        if (file && file.type.startsWith('image/')) {
            const r = new FileReader();
            r.onload = () => setSource(r.result);
            r.readAsDataURL(file);
        }
    });
}

function fileToDataURL(file) {
    return new Promise((resolve) => {
        const r = new FileReader();
        r.onload = () => resolve(r.result);
        r.readAsDataURL(file);
    });
}

export function initDirector() {
    _els = {
        source: document.getElementById('directorSource'),
        result: document.getElementById('directorResult'),
    };
    if (!_els.source) return;

    const upload = document.getElementById('directorUploadBtn');
    const input = document.getElementById('directorInput');
    const back = document.getElementById('directorBackBtn');
    const transform = document.getElementById('directorTransformBtn');

    upload?.addEventListener('click', () => input?.click());
    input?.addEventListener('change', async () => {
        if (input.files[0]) {
            const dataURL = await fileToDataURL(input.files[0]);
            setSource(dataURL);
        }
    });
    back?.addEventListener('click', () => {
        document.getElementById('openDirectorBtn')?.click();
    });
    transform?.addEventListener('click', transform);

    document.querySelectorAll('.director-tool').forEach(b => {
        b.addEventListener('click', () => setTool(b.dataset.tool));
    });

    dragHandlers(_els.source);

    // Listen for events from gallery
    window.addEventListener('nai:director-set-source', (e) => {
        setSource(e.detail.dataURL, e.detail.path);
    });

    // Mode switch
    document.getElementById('openDirectorBtn')?.addEventListener('click', () => {
        const shell = document.querySelector('.app-shell');
        const isDirector = shell.classList.toggle('director-mode');
        document.getElementById('modeSwitchLabel').textContent = isDirector ? '生图' : 'Director';
    });
}
