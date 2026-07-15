/**
 * NAI Studio - Real-ESRGAN 无损放大前端
 *
 * 入口：
 *   - 主图 FAB 上的 🔍 按钮 (mainUpscaleBtn) → 打开放大弹窗
 *   - 弹窗：选 2x/4x/8x → 点击开始 → 进度条 → 结果预览 + 下载
 *
 * 设计：API 同步返回（5-15s），弹窗直接显示进度。简单可靠。
 */

import { api } from './api.js';
import { getState } from './state.js';
import { toast } from './toast.js';

let _currentItem = null;   // 当前放大原图
let _selectedScale = 4;
let _busy = false;

// 模拟进度动画（因为同步请求没真进度，用 0→90 模拟，剩下 10% 在响应后填满）
let _progressTimer = null;

function baseDir() {
    return location.pathname.replace(/\/[^/]*$/, '/');
}
function fullUrl(path) {
    if (!path) return '';
    return path.startsWith('/') ? baseDir() + path.slice(1) : baseDir() + path;
}

function fmtSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / 1024 / 1024).toFixed(2) + ' MB';
}

/** 打开放大弹窗 */
export async function openUpscaleModal() {
    const s = getState();
    const item = s.activeImage;
    if (!item || !item.image_path) {
        toast('请先在画廊选一张图', { type: 'warning' });
        return;
    }
    _currentItem = item;
    _selectedScale = 4;
    _busy = false;

    // 1) 检查后端是否就绪
    let st;
    try {
        const r = await api.upscaleStatus();
        st = r.status;
    } catch (e) {
        toast('检查 Real-ESRGAN 状态失败: ' + e.message, { type: 'error' });
        return;
    }

    // 填充原图预览
    const srcImg = document.getElementById('upscaleSrcImg');
    if (srcImg) srcImg.src = fullUrl(item.image_path);
    const srcMeta = document.getElementById('upscaleSrcMeta');
    if (srcMeta) srcMeta.textContent = `${item.width || '?'}×${item.height || '?'} · ${fmtSize(item.image_size_bytes || 0)}`;

    // 重置结果预览
    document.getElementById('upscaleResultImg')?.classList.add('hidden');
    const ph = document.getElementById('upscalePlaceholder');
    if (ph) { ph.classList.remove('hidden'); ph.textContent = '放大后这里显示'; }
    document.getElementById('upscaleResultMeta').textContent = '—';

    // scale 按钮高亮
    document.querySelectorAll('.upscale-scale-btn').forEach(b => {
        b.classList.toggle('active', parseInt(b.dataset.scale) === _selectedScale);
    });

    // 未就绪 → 按钮禁用 + 提示去下载
    const confirmBtn = document.getElementById('confirmUpscaleBtn');
    const scaleRow = document.getElementById('upscaleScaleRow');
    if (!st.ready) {
        confirmBtn.disabled = true;
        confirmBtn.textContent = '⚠ Real-ESRGAN 未安装';
        scaleRow.style.opacity = '0.5';
        // 弹窗内显示未就绪提示
        const placeholder = document.getElementById('upscalePlaceholder');
        if (placeholder) {
            placeholder.classList.remove('hidden');
            placeholder.innerHTML = `Real-ESRGAN 未安装<br>去「设置 → 一键操作」下载安装包 (~50MB)`;
        }
    } else {
        confirmBtn.disabled = false;
        confirmBtn.textContent = '🔍 开始放大';
        scaleRow.style.opacity = '1';
        // 更新 scale 描述里的目标尺寸
        const w = parseInt(item.width || 0);
        const h = parseInt(item.height || 0);
        ['2', '4', '8'].forEach(s => {
            const el = document.getElementById('upscaleScaleInfo' + s);
            if (!el) return;
            const factor = parseInt(s);
            if (w && h) el.textContent = `${w * factor}×${h * factor}`;
            else el.textContent = `${factor} 倍放大`;
        });
    }

    // 隐藏进度条
    const prog = document.getElementById('upscaleProgress');
    if (prog) prog.classList.add('hidden');
    document.getElementById('upscaleProgressFill').style.width = '0%';

    // 显示弹窗
    document.getElementById('upscaleModal')?.classList.remove('hidden');
}

function closeUpscaleModal() {
    if (_busy) {
        if (!confirm('正在处理中，确定关闭？')) return;
    }
    stopProgressAnim();
    document.getElementById('upscaleModal')?.classList.add('hidden');
    _currentItem = null;
}

function startProgressAnim() {
    stopProgressAnim();
    let pct = 5;
    _progressTimer = setInterval(() => {
        // 渐近到 90%，越接近越慢
        if (pct < 60) pct += 5;
        else if (pct < 80) pct += 2;
        else if (pct < 90) pct += 1;
        else return;  // 停在这里等真实响应
        const fill = document.getElementById('upscaleProgressFill');
        if (fill) fill.style.width = pct + '%';
        const txt = document.getElementById('upscaleProgressText');
        if (txt) {
            const tip = _selectedScale === 8 ? '（4×AI + 2× 采样，请稍等）' : '';
            txt.textContent = `处理中… ${pct}% ${tip}`;
        }
    }, 250);
}
function stopProgressAnim() {
    if (_progressTimer) { clearInterval(_progressTimer); _progressTimer = null; }
}

async function confirmUpscale() {
    if (_busy || !_currentItem) return;
    const saveToGallery = document.getElementById('upscaleSaveToGallery')?.checked ?? true;

    _busy = true;
    const confirmBtn = document.getElementById('confirmUpscaleBtn');
    confirmBtn.disabled = true;
    confirmBtn.textContent = '处理中…';

    // 显示进度
    document.getElementById('upscaleProgress')?.classList.remove('hidden');
    document.getElementById('upscaleProgressFill').style.width = '0%';
    document.getElementById('upscaleProgressText').textContent = '启动 Real-ESRGAN…';
    startProgressAnim();

    try {
        const r = await api.upscaleImage(_currentItem.id, _selectedScale, saveToGallery);
        stopProgressAnim();
        document.getElementById('upscaleProgressFill').style.width = '100%';
        document.getElementById('upscaleProgressText').textContent = '✓ 完成';

        // 显示结果
        const resultImg = document.getElementById('upscaleResultImg');
        const placeholder = document.getElementById('upscalePlaceholder');
        if (resultImg) {
            resultImg.src = fullUrl(r.output_url);
            resultImg.classList.remove('hidden');
        }
        if (placeholder) placeholder.classList.add('hidden');
        const meta = document.getElementById('upscaleResultMeta');
        if (meta) meta.textContent = `${r.width_after}×${r.height_after} · ${fmtSize(r.size_bytes)} · ${(r.duration_ms / 1000).toFixed(1)}s`;

        // 按钮改 "下载"
        confirmBtn.textContent = '⬇ 下载';
        confirmBtn.disabled = false;
        confirmBtn.onclick = () => {
            const a = document.createElement('a');
            a.href = fullUrl(r.output_url) + '?download=1';
            a.download = r.output_filename || `upscaled_${r.scale}x.png`;
            document.body.appendChild(a);
            a.click();
            a.remove();
        };

        // 如果保存到画廊，刷新画廊
        if (r.saved_to_gallery && r.id) {
            const gallery = await import('./gallery.js');
            gallery.reloadGallery();
            toast(`✓ 已放大 ${r.scale}× · ${r.width_after}×${r.height_after} · 用时 ${(r.duration_ms / 1000).toFixed(1)}s · 已加入画廊`, { type: 'success', duration: 5000 });
        } else {
            toast(`✓ 已放大 ${r.scale}× · ${r.width_after}×${r.height_after} · 用时 ${(r.duration_ms / 1000).toFixed(1)}s`, { type: 'success', duration: 4000 });
        }
    } catch (e) {
        stopProgressAnim();
        document.getElementById('upscaleProgressText').textContent = '✗ 失败: ' + e.message;
        toast('放大失败: ' + e.message, { type: 'error', duration: 6000 });
        confirmBtn.disabled = false;
        confirmBtn.textContent = '🔍 重试';
    } finally {
        _busy = false;
        // 重置 onclick 回原函数（避免下载按钮 onClick 残留）
        // 注意：下载按钮逻辑会覆盖 confirmUpscale.onclick，所以下次开弹窗时会重置
    }
}

export function initUpscale() {
    document.getElementById('mainUpscaleBtn')?.addEventListener('click', openUpscaleModal);
    document.getElementById('closeUpscaleBtn')?.addEventListener('click', closeUpscaleModal);
    document.getElementById('cancelUpscaleBtn')?.addEventListener('click', closeUpscaleModal);
    document.getElementById('confirmUpscaleBtn')?.addEventListener('click', confirmUpscale);

    // scale 按钮切换
    document.querySelectorAll('.upscale-scale-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            if (_busy) return;
            const s = parseInt(btn.dataset.scale);
            _selectedScale = s;
            document.querySelectorAll('.upscale-scale-btn').forEach(b => {
                b.classList.toggle('active', parseInt(b.dataset.scale) === s);
            });
        });
    });

    // Esc 关闭
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !document.getElementById('upscaleModal')?.classList.contains('hidden')) {
            closeUpscaleModal();
        }
    });
}
