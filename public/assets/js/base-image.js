/**
 * NAI Studio - Base image (img2img / inpaint) manager
 */

import { getState, setState } from './state.js';
import { openMaskEditor } from './mask-editor.js';
import { toast } from './toast.js';
import { saveLocal } from './storage.js';

function clearBaseImage() {
    setState({ baseImage: null, mask: null });
    document.getElementById('baseImageSlot')?.classList.remove('hidden');
    document.getElementById('baseImageActive')?.classList.add('hidden');
    saveLocal();
}

function setBaseImageFromUpload(file) {
    const r = new FileReader();
    r.onload = () => {
        setState({ baseImage: { path: null, base64: r.result, dataURL: r.result } });
        showBaseImage(r.result);
        saveLocal();
    };
    r.readAsDataURL(file);
}

function showBaseImage(src) {
    const slot = document.getElementById('baseImageSlot');
    const active = document.getElementById('baseImageActive');
    const preview = document.getElementById('baseImagePreview');
    if (!slot || !active) return;
    slot.classList.add('hidden');
    active.classList.remove('hidden');
    preview.src = src;
}

export function initBaseImage() {
    const input = document.getElementById('baseImageInput');
    const uploadBtn = document.getElementById('baseImageUploadBtn');
    const slot = document.getElementById('baseImageSlot');
    const clear = document.getElementById('clearBaseImageBtn');
    const inpaintMode = document.getElementById('inpaintModeBtn');
    const strength = document.getElementById('strengthInput');
    const noise = document.getElementById('noiseInput');
    const strengthVal = document.getElementById('strengthValue');
    const noiseVal = document.getElementById('noiseValue');

    uploadBtn?.addEventListener('click', () => input?.click());
    slot?.addEventListener('click', (e) => {
        if (e.target === slot || e.target === slot.firstElementChild) input?.click();
    });
    input?.addEventListener('change', () => {
        if (input.files[0]) setBaseImageFromUpload(input.files[0]);
    });
    clear?.addEventListener('click', clearBaseImage);

    inpaintMode?.addEventListener('click', () => {
        const s = getState();
        if (!s.baseImage) {
            toast('请先上传底图', { type: 'warning' });
            return;
        }
        if (s.baseImageMode === 'inpaint') {
            setState({ baseImageMode: 'img2img' });
            inpaintMode.classList.remove('active');
            inpaintMode.textContent = '局部重绘';
        } else {
            setState({ baseImageMode: 'inpaint' });
            inpaintMode.classList.add('active');
            inpaintMode.textContent = '图生图';
            // Open mask editor
            openMaskEditor(s.baseImage.dataURL, (maskDataURL) => {
                setState({ mask: maskDataURL });
                toast('已保存遮罩', { type: 'success' });
            });
        }
        saveLocal();
    });

    strength?.addEventListener('input', () => {
        const v = parseFloat(strength.value);
        strengthVal.textContent = v.toFixed(2);
        setState({ strength: v });
        saveLocal();
    });
    noise?.addEventListener('input', () => {
        const v = parseFloat(noise.value);
        noiseVal.textContent = v.toFixed(2);
        setState({ noise: v });
        saveLocal();
    });

    // Restore from state
    const s = getState();
    if (s.strength !== undefined && strength) {
        strength.value = s.strength;
        strengthVal.textContent = s.strength.toFixed(2);
    }
    if (s.noise !== undefined && noise) {
        noise.value = s.noise;
        noiseVal.textContent = s.noise.toFixed(2);
    }
    if (s.baseImage) {
        showBaseImage(s.baseImage.dataURL);
        if (s.baseImageMode === 'inpaint' && inpaintMode) {
            inpaintMode.classList.add('active');
            inpaintMode.textContent = '图生图';
        }
    }
}
