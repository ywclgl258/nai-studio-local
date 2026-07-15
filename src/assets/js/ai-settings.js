/**
 * NAI Studio - AI settings: steps, scale, sampler, seed, advanced
 */

import { getState, setState, subscribe } from './state.js';
import { saveLocal } from './storage.js';

const SAMPLER_LABELS = {
    k_euler_ancestral:    'Euler Ancestral',
    k_euler:             'Euler',
    k_dpmpp_2s_ancestral: 'DPM++ 2S Ancestral',
    k_dpmpp_2m:          'DPM++ 2M',
    k_dpmpp_2m_sde:      'DPM++ 2M SDE',
    k_dpmpp_sde:         'DPM++ SDE',
    ddim:                'DDIM',
};

const NOISE_LABELS = {
    karras: 'karras',
    native: 'native',
    exponential: 'exponential',
    polyexponential: 'polyexponential',
};

export function initAiSettings() {
    const s = getState();
    const $ = (id) => document.getElementById(id);

    // Steps
    const stepsInput = $('stepsInput');
    const stepsValue = $('stepsValue');
    stepsInput.value = s.steps;
    stepsValue.textContent = s.steps;
    stepsInput.addEventListener('input', () => {
        stepsValue.textContent = stepsInput.value;
        setState({ steps: parseInt(stepsInput.value) });
        saveLocal();
    });

    // Scale
    const scaleInput = $('scaleInput');
    const scaleValue = $('scaleValue');
    scaleInput.value = s.scale;
    scaleValue.textContent = parseFloat(s.scale).toFixed(1);
    scaleInput.addEventListener('input', () => {
        scaleValue.textContent = parseFloat(scaleInput.value).toFixed(1);
        setState({ scale: parseFloat(scaleInput.value) });
        saveLocal();
    });

    // Sampler
    const samplerSelect = $('samplerSelect');
    samplerSelect.value = s.sampler;
    samplerSelect.addEventListener('change', () => {
        setState({ sampler: samplerSelect.value });
        saveLocal();
    });

    // Seed
    const seedInput = $('seedInput');
    seedInput.value = s.seed || '';
    seedInput.addEventListener('input', () => {
        setState({ seed: parseInt(seedInput.value) || 0 });
        saveLocal();
    });
    seedInput.addEventListener('dblclick', () => {
        seedInput.value = Math.floor(Math.random() * 4294967295);
        seedInput.dispatchEvent(new Event('input'));
    });

    // Advanced: CFG rescale
    const cfgInput = $('cfgInput');
    const cfgValue = $('cfgValue');
    cfgInput.value = s.cfgRescale;
    cfgValue.textContent = parseFloat(s.cfgRescale).toFixed(2);
    cfgInput.addEventListener('input', () => {
        cfgValue.textContent = parseFloat(cfgInput.value).toFixed(2);
        setState({ cfgRescale: parseFloat(cfgInput.value) });
        saveLocal();
    });

    // Noise schedule
    const noiseSelect = $('noiseScheduleSelect');
    noiseSelect.value = s.noiseSchedule;
    noiseSelect.addEventListener('change', () => {
        setState({ noiseSchedule: noiseSelect.value });
        saveLocal();
    });

    // Reset button
    $('resetSettingsBtn')?.addEventListener('click', () => {
        if (!confirm('重置 AI 设置到默认值？')) return;
        const defaults = window.__NAI_BOOT__?.defaultSettings || {};
        const patch = {
            steps: 28, scale: 5, cfgRescale: 0, noiseSchedule: 'karras', sampler: 'k_euler_ancestral', seed: 0,
        };
        Object.assign(patch, defaults);
        setState(patch);
        stepsInput.value = patch.steps; stepsValue.textContent = patch.steps;
        scaleInput.value = patch.scale; scaleValue.textContent = parseFloat(patch.scale).toFixed(1);
        cfgInput.value = patch.cfgRescale; cfgValue.textContent = parseFloat(patch.cfgRescale).toFixed(2);
        noiseSelect.value = patch.noiseSchedule;
        samplerSelect.value = patch.sampler;
        seedInput.value = '';
        saveLocal();
        toast('已重置 AI 设置', { type: 'success' });
    });
}
