/**
 * NAI Studio - Reusable "Save Preset" modal
 * Used for both pose and character presets.
 *
 * Usage:
 *   import { openPresetSave } from './preset-modal.js';
 *   openPresetSave({
 *     title: '保存姿势预设',
 *     hint: '...',
 *     defaultName: '站姿 - ...',
 *     showCategory: true,
 *     onSave: async ({ name, category, isFavorite }) => { ... }
 *   });
 */

let _onSave = null;

function show(showIt) {
    const modal = document.getElementById('presetSaveModal');
    if (!modal) return;
    modal.classList.toggle('hidden', !showIt);
    if (showIt) {
        setTimeout(() => document.getElementById('presetSaveName')?.focus(), 50);
    }
}

export function openPresetSave({ title, hint, defaultName = '', defaultCategory = 'custom', showCategory = false, onSave }) {
    const titleEl  = document.getElementById('presetSaveTitle');
    const hintEl   = document.getElementById('presetSaveHint');
    const nameEl   = document.getElementById('presetSaveName');
    const catEl    = document.getElementById('presetSaveCategory');
    const catWrap  = document.getElementById('presetSaveCategoryWrap');
    const favEl    = document.getElementById('presetSaveFavorite');

    if (titleEl) titleEl.textContent = title || '保存预设';
    if (hintEl)  hintEl.textContent  = hint  || '';
    if (nameEl)  nameEl.value        = defaultName;
    if (catEl)   catEl.value         = defaultCategory;
    if (catWrap) catWrap.style.display = showCategory ? '' : 'none';
    if (favEl)   favEl.checked       = false;

    _onSave = onSave;
    show(true);
}

export function closePresetSave() {
    show(false);
    _onSave = null;
}

async function confirmSave() {
    const nameEl = document.getElementById('presetSaveName');
    const catEl  = document.getElementById('presetSaveCategory');
    const favEl  = document.getElementById('presetSaveFavorite');
    const name = (nameEl?.value || '').trim();
    if (!name) {
        nameEl?.focus();
        return;
    }
    const cb = _onSave;
    closePresetSave();
    if (cb) {
        try {
            await cb({
                name,
                category: catEl?.value || 'custom',
                isFavorite: !!favEl?.checked,
            });
        } catch (e) {
            console.error('preset save failed', e);
        }
    }
}

export function initPresetSave() {
    document.getElementById('closePresetSaveBtn')?.addEventListener('click', closePresetSave);
    document.getElementById('cancelPresetSaveBtn')?.addEventListener('click', closePresetSave);
    document.getElementById('confirmPresetSaveBtn')?.addEventListener('click', confirmSave);
    document.getElementById('presetSaveModal')?.addEventListener('click', (e) => {
        if (e.target.id === 'presetSaveModal') closePresetSave();
    });
    document.getElementById('presetSaveName')?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); confirmSave(); }
        else if (e.key === 'Escape') { e.preventDefault(); closePresetSave(); }
    });
}