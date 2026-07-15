/**
 * NAI Studio - Mask Editor for inpainting
 * Canvas-based brush/erase with size, feather, square brush
 */

let _state = {
    open: false,
    image: null,         // HTMLImageElement
    canvas: null,        // HTMLCanvasElement
    ctx: null,
    drawing: false,
    mode: 'brush',       // 'brush' | 'erase'
    brushSize: 48,
    square: false,
    feather: 0,
    lastPos: null,
};

let _onSave = null;
let _els = {};

function open(imageDataURL, onSave) {
    _state.open = true;
    _onSave = onSave;
    _els.editor.classList.remove('hidden');
    _state.image = new Image();
    _state.image.onload = () => {
        _els.baseImg.src = imageDataURL;
        // Match canvas size to image natural size
        _els.canvas.width = _state.image.naturalWidth;
        _els.canvas.height = _state.image.naturalHeight;
        // Reset mask
        _state.ctx = _els.canvas.getContext('2d');
        _state.ctx.clearRect(0, 0, _els.canvas.width, _els.canvas.height);
    };
    _state.image.src = imageDataURL;
}

function close() {
    _state.open = false;
    _els.editor.classList.add('hidden');
}

function save() {
    if (!_state.ctx) return;
    const dataURL = _els.canvas.toDataURL('image/png');
    close();
    if (_onSave) _onSave(dataURL);
}

function pointerPos(e) {
    const rect = _els.canvas.getBoundingClientRect();
    const scaleX = _els.canvas.width / rect.width;
    const scaleY = _els.canvas.height / rect.height;
    return {
        x: (e.clientX - rect.left) * scaleX,
        y: (e.clientY - rect.top) * scaleY,
    };
}

function drawAt(x, y) {
    const ctx = _state.ctx;
    if (_state.mode === 'brush') {
        ctx.globalCompositeOperation = 'source-over';
        ctx.fillStyle = 'rgba(255, 255, 255, 1)';
    } else {
        ctx.globalCompositeOperation = 'destination-out';
    }
    const scaleX = _els.canvas.width / _els.canvas.getBoundingClientRect().width;
    const r = (_state.brushSize / 2) * scaleX;
    if (_state.square) {
        ctx.fillRect(x - r, y - r, r * 2, r * 2);
    } else {
        ctx.beginPath();
        ctx.arc(x, y, r, 0, Math.PI * 2);
        ctx.fill();
    }
}

function drawLine(from, to) {
    const dx = to.x - from.x;
    const dy = to.y - from.y;
    const dist = Math.sqrt(dx * dx + dy * dy);
    const steps = Math.max(1, Math.ceil(dist / 2));
    for (let i = 0; i <= steps; i++) {
        const t = i / steps;
        drawAt(from.x + dx * t, from.y + dy * t);
    }
}

function clearMask() {
    if (!_state.ctx) return;
    _state.ctx.clearRect(0, 0, _els.canvas.width, _els.canvas.height);
}

function invertMask() {
    if (!_state.ctx) return;
    const imgData = _state.ctx.getImageData(0, 0, _els.canvas.width, _els.canvas.height);
    const d = imgData.data;
    for (let i = 0; i < d.length; i += 4) {
        // Alpha: 255 -> 0, 0 -> 255
        if (d[i + 3] > 0) d[i + 3] = 0;
        else d[i + 3] = 255;
    }
    _state.ctx.putImageData(imgData, 0, 0);
}

export function initMaskEditor() {
    _els = {
        editor:     document.getElementById('maskEditor'),
        canvas:     document.getElementById('maskCanvas'),
        baseImg:    document.getElementById('maskBaseImage'),
        brushSize:  document.getElementById('maskBrushSize'),
        brushVal:   document.getElementById('maskBrushValue'),
        featherSize: document.getElementById('maskFeatherSize'),
        featherVal:  document.getElementById('maskFeatherValue'),
        square:     document.getElementById('maskSquareBrush'),
        brushBtn:   document.getElementById('maskBrushBtn'),
        eraseBtn:   document.getElementById('maskEraseBtn'),
        clearBtn:   document.getElementById('maskClearBtn'),
        invertBtn:  document.getElementById('maskInvertBtn'),
        saveBtn:    document.getElementById('saveMaskBtn'),
        closeBtn:   document.getElementById('closeMaskBtn'),
    };
    if (!_els.editor) return;

    _els.brushSize.addEventListener('input', () => {
        _state.brushSize = parseInt(_els.brushSize.value);
        _els.brushVal.textContent = _state.brushSize;
    });
    _els.featherSize.addEventListener('input', () => {
        _state.feather = parseInt(_els.featherSize.value);
        _els.featherVal.textContent = _state.feather;
    });
    _els.square.addEventListener('change', () => { _state.square = _els.square.checked; });

    function setMode(mode) {
        _state.mode = mode;
        _els.canvas.classList.toggle('brush', mode === 'brush');
        _els.canvas.classList.toggle('erase', mode === 'erase');
        _els.brushBtn.classList.toggle('active', mode === 'brush');
        _els.eraseBtn.classList.toggle('active', mode === 'erase');
    }
    _els.brushBtn.addEventListener('click', () => setMode('brush'));
    _els.eraseBtn.addEventListener('click', () => setMode('erase'));
    _els.clearBtn.addEventListener('click', clearMask);
    _els.invertBtn.addEventListener('click', invertMask);
    _els.saveBtn.addEventListener('click', save);
    _els.closeBtn.addEventListener('click', close);

    // Pointer events
    _els.canvas.addEventListener('pointerdown', (e) => {
        _state.drawing = true;
        _els.canvas.setPointerCapture(e.pointerId);
        const p = pointerPos(e);
        _state.lastPos = p;
        drawAt(p.x, p.y);
    });
    _els.canvas.addEventListener('pointermove', (e) => {
        if (!_state.drawing) return;
        const p = pointerPos(e);
        if (_state.lastPos) drawLine(_state.lastPos, p);
        _state.lastPos = p;
    });
    _els.canvas.addEventListener('pointerup', (e) => {
        _state.drawing = false;
        _state.lastPos = null;
        try { _els.canvas.releasePointerCapture(e.pointerId); } catch {}
    });
    _els.canvas.addEventListener('pointercancel', () => {
        _state.drawing = false;
        _state.lastPos = null;
    });
}

export function openMaskEditor(imageDataURL, onSave) {
    open(imageDataURL, onSave);
}
