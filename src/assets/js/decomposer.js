/**
 * NAI Studio - Prompt Decomposer (v3.1: 全屏工作台)
 *
 * 核心交互：
 *   - 左侧输入 NAI 提示词
 *   - 右侧自动按行渲染"英文 | 中文"对照表
 *   - 每行可：
 *       - 删除（× 按钮，整行删除）
 *       - 编辑英文（onBlur 触发重新分类+翻译）
 *       - 编辑中文（直接生效）
 *   - 删除时联动：任一侧 × 按钮删行 → 另一侧同 index 行也消失
 *   - 拆解时保留 NAI 权重 {tag:1.2}
 *   - 底部"→ 写入主提示词"把英文栏拼接回主提示词
 *   - 顶部 4 张大统计卡 + 3 tab 切换（对照表/画师/AI）
 *   - 最近拆解列表（localStorage 缓存）
 *
 * 数据模型：
 *   state.pairs = [{id, name, weight, cn, category}, ...]
 *   （扁平列表，不分组）
 */

import { api } from './api.js';
import { toast } from './toast.js';
import { getState, setState } from './state.js';

let _els = {};
let _pairs = [];          // 当前拆解结果（扁平）
let _groupByCat = false;  // 是否按分类排序显示
let _idCounter = 0;

const RECENT_KEY = 'nai.decomposer.recent';
const RECENT_MAX = 5;
const CN_OVERRIDE_KEY = 'nai.translations.override';   // 用户改过的翻译：en -> cn 永久缓存

// =================== 翻译 override 缓存 ===================
// 优先于后端翻译（danbooru cache / 字典 / DeepSeek）
// 写时机：cn input 改动时（实时）
// 读时机：拆解完成后、reclassify 后、fillTranslate 时
function _loadCnOverrides() {
    try { return JSON.parse(localStorage.getItem(CN_OVERRIDE_KEY) || '{}'); }
    catch { return {}; }
}
function _saveCnOverride(en, cn) {
    if (!en) return;
    const m = _loadCnOverrides();
    const key = en.toLowerCase().trim();
    if (!key) return;
    if (cn && cn.trim()) {
        m[key] = cn.trim();
    } else {
        // 清空 = 删除
        delete m[key];
    }
    try { localStorage.setItem(CN_OVERRIDE_KEY, JSON.stringify(m)); } catch {}
}
function _applyCnOverrides(pairs) {
    const m = _loadCnOverrides();
    if (!m || Object.keys(m).length === 0) return pairs;
    let hit = 0;
    for (const p of pairs) {
        const key = (p.clean || p.name || '').toLowerCase().trim();
        if (key && m[key]) {
            if (p.cn !== m[key]) {
                p.cn = m[key];
                hit++;
            }
        }
    }
    if (hit > 0) console.log(`[Decomposer] 应用本地 cn override: ${hit} 个`);
    return pairs;
}

function escapeHtml(s) {
    return (s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[c]));
}

function newId() { return 'p' + (++_idCounter); }

// 重新拼接 NAI 权重 tag 的 raw 文本
function rebuildRaw(t) {
    if (t.weight === 0) return `{${t.name}:0}`;
    if (t.weight !== 1.0 && t.weight !== 1.05) {
        return `{${t.name}:${t.weight}}`;
    }
    return t.name;
}

// =================== Modal control ===================
function open() {
    _els.modal.classList.remove('hidden');
    renderRecentList();
    setTimeout(() => _els.input.focus(), 50);
}
function close() {
    _els.modal.classList.add('hidden');
}

// =================== 最近拆解 ===================
function loadRecent() {
    try { return JSON.parse(localStorage.getItem(RECENT_KEY) || '[]'); }
    catch { return []; }
}
function saveRecentEntry(prompt, count) {
    if (!prompt) return;
    const list = loadRecent().filter(x => x.prompt !== prompt);  // 去重
    list.unshift({
        prompt,
        count: count || 0,
        ts: Date.now(),
    });
    if (list.length > RECENT_MAX) list.length = RECENT_MAX;
    try { localStorage.setItem(RECENT_KEY, JSON.stringify(list)); } catch {}
}
function formatTimeAgo(ts) {
    const diff = (Date.now() - ts) / 1000;
    if (diff < 60) return '刚刚';
    if (diff < 3600) return `${Math.floor(diff / 60)} 分钟前`;
    if (diff < 86400) return `${Math.floor(diff / 3600)} 小时前`;
    return `${Math.floor(diff / 86400)} 天前`;
}
function renderRecentList() {
    const block = document.getElementById('decoRecentBlock');
    const listEl = document.getElementById('decoRecentList');
    if (!block || !listEl) return;
    const list = loadRecent();
    if (list.length === 0) {
        block.classList.add('hidden');
        return;
    }
    block.classList.remove('hidden');
    listEl.innerHTML = list.map((item, i) => `
        <div class="deco-recent-item" data-idx="${i}" title="${escapeHtml(item.prompt)}">
            <span class="deco-recent-time">${formatTimeAgo(item.ts)}</span>
            <span class="deco-recent-text">${escapeHtml(item.prompt.length > 60 ? item.prompt.slice(0, 60) + '…' : item.prompt)}</span>
            <span class="deco-recent-count">${item.count} tag</span>
        </div>
    `).join('');
    // 点击载入
    listEl.querySelectorAll('.deco-recent-item').forEach(el => {
        el.addEventListener('click', () => {
            const idx = parseInt(el.dataset.idx);
            const item = loadRecent()[idx];
            if (item) {
                _els.input.value = item.prompt;
                updateInputMeta();
                toast('已载入最近拆解', { type: 'info' });
                _els.input.focus();
            }
        });
    });
}
function updateInputMeta() {
    const len = _els.input.value.length;
    _els.inputMeta.textContent = `${len} 字符`;
}

// =================== API calls ===================
async function loadSample() {
    try {
        const r = await api.decomposeSample();
        _els.input.value = r.prompt || '';
        updateInputMeta();
        toast('示例已载入，点"⚡ 拆解"试试', { type: 'info' });
    } catch (e) {
        toast('载入失败: ' + e.message, { type: 'error' });
    }
}

function clearAll() {
    _els.input.value = '';
    updateInputMeta();
    _els.result.classList.add('hidden');
    _els.empty.classList.remove('hidden');
    _els.loading.classList.add('hidden');
    _pairs = [];
    // 徽标重置
    if (_els.badgePairs) _els.badgePairs.textContent = '0';
    if (_els.badgeArtists) _els.badgeArtists.textContent = '0';
    if (_els.badgeAi) _els.badgeAi.textContent = '—';
    // 大卡重置
    if (_els.statsCards) _els.statsCards.innerHTML = '';
    // 画师/AI 面板重置为 empty
    if (_els.artistEmpty) _els.artistEmpty.classList.remove('hidden');
    if (_els.artistContent) {
        _els.artistContent.classList.add('hidden');
        _els.artistContent.innerHTML = '';
    }
    if (_els.aiEmpty) _els.aiEmpty.classList.remove('hidden');
    if (_els.aiContent) {
        _els.aiContent.classList.add('hidden');
        _els.aiContent.innerHTML = '';
    }
}

async function run() {
    const prompt = _els.input.value.trim();
    if (!prompt) {
        toast('请先粘贴提示词', { type: 'warning' });
        _els.input.focus();
        return;
    }
    _els.empty.classList.add('hidden');
    _els.result.classList.add('hidden');
    _els.loading.classList.remove('hidden');

    try {
        const r = await api.decompose({
            prompt,
            translate: _els.autoTranslate.checked ? 1 : 0,
        });
        // 把分类结果拍平为 pairs
        _pairs = flattenCategories(r);
        // 应用本地 cn override（用户改过的翻译优先）
        _applyCnOverrides(_pairs);
        renderAll(r);
        // 存到最近拆解
        saveRecentEntry(prompt, _pairs.length);
    } catch (e) {
        toast('拆解失败: ' + e.message, { type: 'error' });
        _els.empty.classList.remove('hidden');
    } finally {
        _els.loading.classList.add('hidden');
    }
}

function flattenCategories(result) {
    const out = [];
    const cats = result.categories || {};
    // 按 order 排序后遍历
    const sorted = Object.entries(cats).sort((a, b) => (a[1].order || 99) - (b[1].order || 99));
    for (const [key, cat] of sorted) {
        if (!cat.tags) continue;
        for (const t of cat.tags) {
            out.push({
                id: newId(),
                name: t.name,
                clean: t.clean,
                weight: t.weight,
                cn: t.cn || '',
                category: key,
                catLabel: cat.name,
                catIcon: cat.icon,
                source: t.source,
            });
        }
    }
    return out;
}

// =================== Render ===================
function renderAll(serverResult) {
    const stats = serverResult.stats || {};
    const filled = serverResult.translation_filled || 0;
    const total = stats.total || _pairs.length;
    const classified = stats.classified || Math.max(0, _pairs.length - (stats.unclassified || 0));
    const unclassified = stats.unclassified || 0;

    // 4 张大统计卡
    if (_els.statsCards) {
        _els.statsCards.innerHTML = `
            <div class="dsc dsc-total">
                <span class="dsc-num">${total}</span>
                <span class="dsc-label">总标签</span>
                <span class="dsc-sub">拆分后行数</span>
                <span class="dsc-icon">🏷</span>
            </div>
            <div class="dsc dsc-class">
                <span class="dsc-num">${classified}</span>
                <span class="dsc-label">已分类</span>
                <span class="dsc-sub">${total > 0 ? Math.round(classified / total * 100) : 0}% 命中率</span>
                <span class="dsc-icon">✅</span>
            </div>
            <div class="dsc ${unclassified > 0 ? 'dsc-unclass dsc-warn' : 'dsc-unclass dsc-good'}">
                <span class="dsc-num">${unclassified}</span>
                <span class="dsc-label">未识别</span>
                <span class="dsc-sub">${unclassified > 0 ? '可点行内补充' : '✓ 全部识别'}</span>
                <span class="dsc-icon">${unclassified > 0 ? '⚠️' : '🎯'}</span>
            </div>
            <div class="dsc dsc-ai">
                <span class="dsc-num">${filled}</span>
                <span class="dsc-label">本次补翻译</span>
                <span class="dsc-sub">点击"🧠 AI"得更多</span>
                <span class="dsc-icon">🌐</span>
            </div>
        `;
    }

    // Tab 徽标
    if (_els.badgePairs) _els.badgePairs.textContent = _pairs.length;
    if (_els.badgeArtists) {
        const ac = serverResult.artist_advice?.stats?.count || 0;
        _els.badgeArtists.textContent = ac;
    }
    if (_els.badgeAi) _els.badgeAi.textContent = '—';

    // 计算未翻译数（cn 为空）
    const untranslated = _pairs.filter(p => !p.cn).length;
    if (untranslated > 0) {
        _els.untranslatedCount.textContent = untranslated;
        _els.untranslatedBlock.classList.remove('hidden');
    } else {
        _els.untranslatedBlock.classList.add('hidden');
    }

    _els.countMeta.textContent = `共 ${_pairs.length} 行 · 未翻译 ${untranslated}`;
    if (_els.bottomHint) {
        const warnBit = untranslated > 0 ? ` · <span style="color:var(--warning,#f59e0b)">还有 ${untranslated} 个未翻译</span>` : '';
        _els.bottomHint.innerHTML = `共 ${_pairs.length} 行${warnBit}`;
    }

    renderPairs();
    renderArtistAdvice(serverResult.artist_advice);
    // 默认切到对照表 tab
    switchTab('pairs');
    _els.result.classList.remove('hidden');
}

// 画师建议渲染
function renderArtistAdvice(advice) {
    // 徽标数
    const count = advice?.stats?.count || 0;
    if (_els.badgeArtists) _els.badgeArtists.textContent = count;

    if (!advice || !advice.stats || count === 0) {
        // 空状态
        if (_els.artistEmpty) _els.artistEmpty.classList.remove('hidden');
        if (_els.artistContent) {
            _els.artistContent.classList.add('hidden');
            _els.artistContent.innerHTML = '';
        }
        return;
    }

    const stats = advice.stats;
    const artists = advice.artists || {};
    const conflicts = advice.conflicts || [];
    const recs = advice.recommendations || [];
    const warnings = advice.warnings || [];

    let html = `<div class="daa-header" id="daaHeader">
        <div class="daa-title">
            <span>🎨 画师建议</span>
            <span class="daa-summary">${stats.count} 个画师 · ${stats.unique_styles} 种风格${stats.primary_style !== 'unknown' ? ' · 主风格 ' + styleLabel(stats.primary_style) : ''}</span>
        </div>
        <span class="daa-toggle" id="daaToggle">▾</span>
    </div>`;
    html += `<div class="daa-body" id="daaBody">`;

    // 1) 画师画像列表
    html += `<div class="daa-section">`;
    html += `<div class="daa-section-title">画师画像</div>`;
    html += `<div class="daa-artists">`;
    for (const [clean, info] of Object.entries(artists)) {
        const p = info.profile;
        if (!p) continue;
        const tierBadge = p.tier ? `<span class="daa-tier daa-tier-${p.tier.toLowerCase()}">${p.tier}</span>` : '';
        const styleBadge = p.style ? `<span class="daa-style daa-style-${p.style}">${styleLabel(p.style)}</span>` : '';
        const cnHint = p.cn ? ` · ${escapeHtml(p.cn)}` : '';
        const noteHint = p.notes ? `<div class="daa-note">${escapeHtml(p.notes)}</div>` : '';
        html += `<div class="daa-artist">
            <div class="daa-artist-row">
                <span class="daa-artist-name">${escapeHtml(clean)}${cnHint}</span>
                ${tierBadge}
                ${styleBadge}
                <span class="daa-artist-rank">rank ${p.rank || '?'}</span>
            </div>
            ${noteHint}
        </div>`;
    }
    html += `</div></div>`;

    // 2) 冲突
    if (conflicts.length > 0) {
        html += `<div class="daa-section daa-section-warn">`;
        html += `<div class="daa-section-title">⚠️ 风格冲突（${conflicts.length}）</div>`;
        for (const c of conflicts) {
            html += `<div class="daa-item daa-item-${c.severity}">
                <strong>${escapeHtml(c.a)}</strong> × <strong>${escapeHtml(c.b)}</strong>
                <span class="daa-reason">${escapeHtml(c.reason)}</span>
            </div>`;
        }
        html += `</div>`;
    }

    // 3) 建议
    if (recs.length > 0) {
        html += `<div class="daa-section">`;
        html += `<div class="daa-section-title">💡 优化建议（${recs.length}）</div>`;
        for (const r of recs) {
            const icon = r.level === 'warning' ? '⚠️' : r.level === 'tip' ? '💡' : 'ℹ️';
            let body = escapeHtml(r.message);
            if (r.suggested && Array.isArray(r.suggested)) {
                body += ` <span class="daa-suggested">` +
                    r.suggested.map(s => `<span class="daa-chip" data-artist="${escapeHtml(s)}">+ ${escapeHtml(s)}</span>`).join('') +
                    `</span>`;
            }
            if (r.suggested_syntax) {
                body += ` <code class="daa-syntax">${escapeHtml(r.suggested_syntax)}</code>`;
                body += ` <button class="daa-copy-syntax" data-syntax="${escapeHtml(r.suggested_syntax)}">复制</button>`;
            }
            if (r.note) {
                body += ` <span class="daa-note-inline">(${escapeHtml(r.note)})</span>`;
            }
            html += `<div class="daa-item daa-item-${r.level}">${icon} ${body}</div>`;
        }
        html += `</div>`;
    }

    // 4) 警告
    if (warnings.length > 0) {
        html += `<div class="daa-section daa-section-tip">`;
        html += `<div class="daa-section-title">📊 画师串评估</div>`;
        for (const w of warnings) {
            const icon = w.level === 'warning' ? '⚠️' : w.level === 'tip' ? '💡' : 'ℹ️';
            html += `<div class="daa-item daa-item-${w.level}">${icon} ${escapeHtml(w.message)}</div>`;
        }
        html += `</div>`;
    }

    html += `</div>`;   // end daa-body

    if (_els.artistEmpty) _els.artistEmpty.classList.add('hidden');
    if (_els.artistContent) {
        _els.artistContent.classList.remove('hidden');
        _els.artistContent.innerHTML = html;
    }

    // 绑定事件：折叠
    _els.artistContent.querySelector('#daaHeader')?.addEventListener('click', () => {
        const body = _els.artistContent.querySelector('#daaBody');
        const toggle = _els.artistContent.querySelector('#daaToggle');
        if (body.classList.contains('daa-body-collapsed')) {
            body.classList.remove('daa-body-collapsed');
            toggle.textContent = '▾';
        } else {
            body.classList.add('daa-body-collapsed');
            toggle.textContent = '▸';
        }
    });

    // 绑定：点 + chip 自动加入画师
    _els.artistContent.querySelectorAll('.daa-chip').forEach(chip => {
        chip.addEventListener('click', () => {
            const artist = chip.dataset.artist;
            addArtistByName(artist);
        });
    });

    // 绑定：复制语法
    _els.artistContent.querySelectorAll('.daa-copy-syntax').forEach(btn => {
        btn.addEventListener('click', async () => {
            const syn = btn.dataset.syntax;
            try { await navigator.clipboard.writeText(syn); toast('已复制语法', { type: 'success' }); }
            catch { toast('复制失败', { type: 'error' }); }
        });
    });
}

function styleLabel(key) {
    return ({
        thick_anime: '厚涂二次元', soft_anime: '软萌二次元', realistic: '写实派',
        cinematic: '电影感', illustration: '插画风', dark: '黑暗系', classic: '经典派',
        unknown: '未知',
    })[key] || key;
}

// 按画师名加一行（从建议点击 +xx）
function addArtistByName(name) {
    if (!name) return;
    _pairs.unshift({
        id: newId(),
        name: 'artist:' + name,
        clean: name,
        weight: 1.0,
        cn: '',
        category: 'artist',
        catLabel: '画师串',
        catIcon: '🎨',
        source: 'manual',
    });
    renderPairs();
    updateUntranslatedCount();
    // 同步徽标 + 大卡
    if (_els.badgePairs) _els.badgePairs.textContent = _pairs.length;
    const el = document.querySelector('.dsc-total .dsc-num');
    if (el) el.textContent = _pairs.length;
    // 自动聚焦到新行的英文 input
    setTimeout(() => {
        const inputs = _els.pairsBody.querySelectorAll('.dc-input-en');
        if (inputs[0]) {
            inputs[0].focus();
            inputs[0].select();
        }
    }, 50);
    // 顺便重新触发画师建议
    refreshArtistAdvice();
}

async function refreshArtistAdvice() {
    // 重新调用 advisor（直接传当前 _pairs）
    const flatPairs = _pairs.map(p => ({
        name: p.name, clean: p.clean, weight: p.weight, cn: p.cn, category: p.category,
    }));
    try {
        // 调 decompose.php?action=advise
        const r = await api.decomposeAdvise({ pairs: flatPairs });
        if (r && r.advice) {
            renderArtistAdvice(r.advice);
        }
    } catch (e) {
        // 静默
    }
}

function renderPairs() {
    // 排序
    const sorted = sortPairs(_pairs);

    _els.pairsBody.innerHTML = '';
    for (let i = 0; i < sorted.length; i++) {
        const p = sorted[i];
        const row = document.createElement('div');
        row.className = 'dc-pair';
        row.dataset.id = p.id;
        if (!p.cn) row.classList.add('dc-pair-untranslated');

        const weightHint = (p.weight !== 1.0 && p.weight !== 1.05)
            ? ` <span class="dc-weight" title="权重">×${p.weight}</span>` : '';

        // 两侧各一个 ×：删英文侧的 × 联动删中文；删中文侧的 × 联动删英文
        // 视觉上分两边（用户感知"按列删"），实际都是整行删
        row.innerHTML = `
            <div class="dc-pair-en">
                <input type="text" class="dc-input dc-input-en" value="${escapeHtml(p.name)}" data-id="${p.id}" data-field="name" spellcheck="false" autocomplete="off">
                ${weightHint}
                <button class="dc-cell-del dc-cell-del-en" data-id="${p.id}" data-side="en" title="删除这一行（同时移除英文和中文）">×</button>
            </div>
            <div class="dc-pair-cn">
                <input type="text" class="dc-input dc-input-cn" value="${escapeHtml(p.cn || '')}" data-id="${p.id}" data-field="cn" placeholder="(未翻译)" spellcheck="false" autocomplete="off">
                <button class="dc-cell-del dc-cell-del-cn" data-id="${p.id}" data-side="cn" title="删除这一行（同时移除英文和中文）">×</button>
            </div>
            <div class="dc-pair-cat" title="${escapeHtml(p.catLabel || '')}">
                <span class="dc-cat-icon">${p.catIcon || '🏷'}</span>
                <span>${escapeHtml(p.catLabel || p.category)}</span>
            </div>
        `;
        _els.pairsBody.appendChild(row);
    }
}

function sortPairs(pairs) {
    if (!_groupByCat) return pairs;
    return [...pairs].sort((a, b) => {
        if (a.category !== b.category) return a.category.localeCompare(b.category);
        return 0;
    });
}

// =================== Row interactions ===================
function handlePairsClick(e) {
    const t = e.target;
    // 旧版本：.dc-del-btn (已废弃，保留兼容)
    // 新版本：.dc-cell-del（英文侧 / 中文侧各一个，都能删整行）
    if (t.classList.contains('dc-del-btn') || t.classList.contains('dc-cell-del')) {
        const id = t.dataset.id;
        deletePair(id);
    }
}

async function handlePairsInput(e) {
    const t = e.target;
    if (!t.classList.contains('dc-input')) return;

    const id = t.dataset.id;
    const field = t.dataset.field;
    const p = _pairs.find(x => x.id === id);
    if (!p) return;

    if (field === 'cn') {
        p.cn = t.value;
        // 实时刷新未翻译数
        updateUntranslatedCount();
        // 实时写入本地 override（用户改了就存，下次不再走 API 翻译）
        _saveCnOverride(p.clean || p.name, t.value);
    }
    // 英文编辑：onBlur 时再处理（避免每次输入都触发翻译）
}

async function handlePairsBlur(e) {
    const t = e.target;
    if (!t.classList.contains('dc-input')) return;

    const id = t.dataset.id;
    const field = t.dataset.field;
    const p = _pairs.find(x => x.id === id);
    if (!p) return;

    if (field === 'name') {
        const newName = t.value.trim();
        if (!newName) {
            // 清空就当删除
            deletePair(id);
            return;
        }
        if (newName === p.name) return;
        // 旧 cn 跟着旧 en 走；新 en 走 override / API
        const oldEn = p.name;
        p.name = newName;
        // 重新分类 + 翻译
        await reclassifyOne(p);
        // 旧 en 的 override 失效前先保留（用户可能想恢复）；不删除
    } else if (field === 'cn') {
        // 中文 blur：刷新未翻译 + 再次确认写入 override
        p.cn = t.value;
        _saveCnOverride(p.clean || p.name, t.value);
        updateUntranslatedCount();
    }
}

async function reclassifyOne(p) {
    try {
        const r = await api.lookupTag(p.clean || p.name);
        if (r && r.category !== undefined) {
            p.category = mapDanbooruCat(r.category);
            p.catLabel = getCatLabel(p.category);
            p.catIcon = getCatIcon(p.category);
        }
        if (r && r.cn) {
            p.cn = r.cn;
        } else {
            // 没缓存就调 en→zh
            const trR = await fetch(`/api/danbooru.php?action=translate&q=${encodeURIComponent(p.name)}`).then(r => r.json());
            if (trR && trR.cn) p.cn = trR.cn;
        }
        // 用户的本地 override 优先（防被后端旧翻译覆盖）
        const overrides = _loadCnOverrides();
        const key = (p.clean || p.name || '').toLowerCase().trim();
        if (key && overrides[key]) {
            p.cn = overrides[key];
        }
        renderPairs();
        updateUntranslatedCount();
    } catch (e) {
        // silent
    }
}

function mapDanbooruCat(dbCat) {
    const map = { 1: 'artist', 3: 'character', 4: 'character', 5: 'meta' };
    return map[dbCat] || 'uncategorized';
}

function getCatLabel(cat) {
    return ({
        artist: '画师串', character: '角色', subject: '人物描述',
        hair: '头发', eyes: '眼睛', expression: '表情', pose: '姿势动作',
        hands: '手部动作', clothing: '服装配饰', body: '身体特征',
        background: '背景场景', meta: '视角/质量', uncategorized: '未识别',
    })[cat] || '其他';
}

function getCatIcon(cat) {
    return ({
        artist: '🎨', character: '👤', subject: '🧍', hair: '💇', eyes: '👁️',
        expression: '😊', pose: '🏃', hands: '✋', clothing: '👗', body: '🧬',
        background: '🌳', meta: '✨', uncategorized: '❓',
    })[cat] || '🏷';
}

function deletePair(id) {
    _pairs = _pairs.filter(p => p.id !== id);
    renderPairs();
    updateUntranslatedCount();
    // 顺手更新 v3 大统计卡的总标签数
    const el = document.querySelector('.dsc-total .dsc-num');
    if (el) el.textContent = _pairs.length;
    if (_els.badgePairs) _els.badgePairs.textContent = _pairs.length;
    if (_els.bottomHint) {
        const untranslated = _pairs.filter(p => !p.cn).length;
        const warnBit = untranslated > 0 ? ` · <span style="color:var(--warning,#f59e0b)">还有 ${untranslated} 个未翻译</span>` : '';
        _els.bottomHint.innerHTML = `共 ${_pairs.length} 行${warnBit}`;
    }
}

function updateUntranslatedCount() {
    const untranslated = _pairs.filter(p => !p.cn).length;
    if (untranslated > 0) {
        _els.untranslatedCount.textContent = untranslated;
        _els.untranslatedBlock.classList.remove('hidden');
    } else {
        _els.untranslatedBlock.classList.add('hidden');
    }
    _els.countMeta.textContent = `共 ${_pairs.length} 行 · 未翻译 ${untranslated}`;
}

function addBlankRow() {
    _pairs.push({
        id: newId(),
        name: '',
        weight: 1.0,
        cn: '',
        category: 'uncategorized',
        catLabel: '未识别',
        catIcon: '❓',
        source: 'manual',
    });
    renderPairs();
    // 焦点到新行
    setTimeout(() => {
        const inputs = _els.pairsBody.querySelectorAll('.dc-input-en');
        const last = inputs[inputs.length - 1];
        if (last) last.focus();
    }, 50);
}

async function fillTranslate() {
    // 先用本地 override 二次过滤（虽然 run 时已经覆盖过，但防止 reclassify 后又丢了）
    const overrides = _loadCnOverrides();
    const need = _pairs.filter(p => {
        if (!p.name) return false;
        if (p.cn) return false;
        // 查 override（理论上不会有，没翻译就不会进 override）
        return true;
    });
    if (need.length === 0) {
        toast('没有需要补译的 tag', { type: 'info' });
        return;
    }
    _els.fillTranslateBtn.disabled = true;
    _els.fillTranslateBtn.textContent = `🌐 翻译中 0/${need.length}`;
    let done = 0;
    let fromLocal = 0;
    for (const p of need) {
        // 先查本地
        const key = (p.clean || p.name || '').toLowerCase().trim();
        if (overrides[key]) {
            p.cn = overrides[key];
            fromLocal++;
        } else {
            try {
                const r = await fetch(`/api/danbooru.php?action=translate&q=${encodeURIComponent(p.name)}`).then(r => r.json());
                if (r && r.cn) p.cn = r.cn;
            } catch {}
        }
        done++;
        _els.fillTranslateBtn.textContent = `🌐 翻译中 ${done}/${need.length}`;
    }
    _els.fillTranslateBtn.disabled = false;
    _els.fillTranslateBtn.textContent = '🌐 补翻译';
    renderPairs();
    updateUntranslatedCount();
    const tip = fromLocal > 0 ? `（${fromLocal} 个走本地缓存）` : '';
    toast(`已补译 ${need.length} 个 tag${tip}`, { type: 'success' });
}

// =================== Export ===================
function buildEnglishText() {
    // 用 NAI 权重 raw 拼接
    return _pairs.map(p => rebuildRaw(p)).join(', ');
}
function buildBilingualText() {
    return _pairs
        .map(p => p.cn ? `${p.name}(${p.cn})` : p.name)
        .join(', ');
}

async function copyEnglish() {
    const text = buildEnglishText();
    if (!text) { toast('没有可复制的内容', { type: 'warning' }); return; }
    try {
        await navigator.clipboard.writeText(text);
        toast(`已复制 ${_pairs.length} 个英文 tag`, { type: 'success' });
    } catch { toast('复制失败', { type: 'error' }); }
}

async function copyBilingual() {
    const text = buildBilingualText();
    if (!text) { toast('没有可复制的内容', { type: 'warning' }); return; }
    try {
        await navigator.clipboard.writeText(text);
        toast(`已复制 ${_pairs.length} 个双语 tag`, { type: 'success' });
    } catch { toast('复制失败', { type: 'error' }); }
}

async function applyToMain() {
    const text = buildEnglishText();
    if (!text) { toast('没有可写入的内容', { type: 'warning' }); return; }
    const mainTextarea = document.getElementById('promptTextarea') ||
                          document.getElementById('mainPromptInput') ||
                          document.querySelector('.prompt-textarea');
    if (mainTextarea) {
        mainTextarea.value = text;
        mainTextarea.dispatchEvent(new Event('input', { bubbles: true }));
        toast('已写入主提示词', { type: 'success' });
    } else {
        setState({ prompt: text });
        try { localStorage.setItem('nai.prompt', text); } catch {}
        toast('已写入 state', { type: 'info' });
    }
    close();
}

// =================== Init ===================
export function openDecomposer(initialPrompt) {
    open();
    if (initialPrompt) {
        _els.input.value = initialPrompt;
        updateInputMeta();
    }
}

export function initDecomposer() {
    _els = {
        modal:        document.getElementById('decomposeModal'),
        input:        document.getElementById('decomposeInput'),
        inputMeta:    document.getElementById('decomposeInputMeta'),
        sampleBtn:    document.getElementById('decomposeSampleBtn'),
        clearBtn:     document.getElementById('decomposeClearBtn'),
        runBtn:       document.getElementById('decomposeRunBtn'),
        closeBtn:     document.getElementById('closeDecomposeBtn'),
        autoTranslate:document.getElementById('decomposeAutoTranslate'),
        empty:        document.getElementById('decomposeEmpty'),
        loading:      document.getElementById('decomposeLoading'),
        result:       document.getElementById('decomposeResult'),
        // v3: 大统计卡
        statsCards:   document.getElementById('decomposeStats'),
        // Tab
        tabbar:       document.querySelector('.decompose-tabbar'),
        tabs:         document.querySelectorAll('.decompose-tab'),
        tabPanes:     document.querySelectorAll('.decompose-tabpane'),
        badgePairs:   document.getElementById('dtabBadgePairs'),
        badgeArtists: document.getElementById('dtabBadgeArtists'),
        badgeAi:      document.getElementById('dtabBadgeAi'),
        // 对照表
        countMeta:    document.getElementById('decomposeCountMeta'),
        pairsBody:    document.getElementById('decomposePairsBody'),
        untranslatedBlock: document.getElementById('decomposeUntranslatedBlock'),
        untranslatedCount: document.getElementById('decomposeUntranslatedCount'),
        fillTranslateBtn: document.getElementById('decomposeFillTranslateBtn'),
        addRowBtn:    document.getElementById('decomposeAddRowBtn'),
        groupByCat:   document.getElementById('decomposeGroupByCat'),
        // 画师 / AI 面板
        artistContent:document.getElementById('decomposeArtistContent'),
        artistEmpty:  document.getElementById('decomposeArtistEmpty'),
        aiContent:    document.getElementById('decomposeAiContent'),
        aiEmpty:      document.getElementById('decomposeAiEmpty'),
        aiBtn:        document.getElementById('decomposeAiBtn'),
        // 底部固定栏
        bottomHint:   document.getElementById('decomposeBottomHint'),
        copyRebuildBtn: document.getElementById('decomposeCopyRebuildBtn'),
        copyBothBtn:  document.getElementById('decomposeCopyBothBtn'),
        applyRebuildBtn:document.getElementById('decomposeApplyRebuildBtn'),
    };
    if (!_els.modal) return;

    // 顶栏按钮
    document.getElementById('openDecomposeBtn')?.addEventListener('click', open);

    // 弹窗内
    _els.closeBtn?.addEventListener('click', close);
    _els.modal.addEventListener('click', (e) => { if (e.target === _els.modal) close(); });
    // Esc 关闭
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !_els.modal.classList.contains('hidden')) close();
    });
    _els.sampleBtn?.addEventListener('click', loadSample);
    _els.clearBtn?.addEventListener('click', clearAll);
    _els.runBtn?.addEventListener('click', run);
    _els.input?.addEventListener('input', updateInputMeta);
    _els.input?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
            e.preventDefault();
            run();
        }
    });
    _els.addRowBtn?.addEventListener('click', addBlankRow);
    _els.fillTranslateBtn?.addEventListener('click', fillTranslate);
    _els.copyRebuildBtn?.addEventListener('click', copyEnglish);
    _els.copyBothBtn?.addEventListener('click', copyBilingual);
    _els.applyRebuildBtn?.addEventListener('click', applyToMain);
    _els.groupByCat?.addEventListener('change', (e) => {
        _groupByCat = e.target.checked;
        renderPairs();
    });
    _els.pairsBody?.addEventListener('click', handlePairsClick);
    _els.pairsBody?.addEventListener('input', handlePairsInput);
    _els.pairsBody?.addEventListener('blur', handlePairsBlur, true);  // capture: 抓住 input 的 blur
    _els.aiBtn?.addEventListener('click', runAiAnalysis);

    // Tab 切换
    _els.tabs.forEach(tab => {
        tab.addEventListener('click', () => switchTab(tab.dataset.dtab));
    });
}

// =================== Tab 切换 ===================
function switchTab(name) {
    _els.tabs.forEach(t => t.classList.toggle('active', t.dataset.dtab === name));
    _els.tabPanes.forEach(p => p.classList.toggle('active', p.dataset.dtabpane === name));
}

// =================== AI 深度分析（按目标 NAI 模型切换预设）===================
async function runAiAnalysis() {
    const prompt = _els.input.value.trim();
    if (!prompt) { toast('请先输入 prompt', { type: 'warning' }); return; }

    // 切到 AI tab
    switchTab('ai');

    // 显示加载中
    if (_els.aiEmpty) _els.aiEmpty.classList.add('hidden');
    if (_els.aiContent) {
        _els.aiContent.classList.remove('hidden');
        _els.aiContent.innerHTML = '<div class="daa-header"><div class="daa-title">🧠 AI 深度分析中…</div></div>';
    }
    if (_els.badgeAi) _els.badgeAi.textContent = '⏳';

    // 读目标模型（与 AI 写提示词共用 localStorage 状态）
    const targetModel = localStorage.getItem('nai.aiCompose.targetModel') || 'curated';

    try {
        const r = await api.aiAnalyze({ prompt, model: targetModel });
        if (!r._meta?.mock) {
            console.log('DeepSeek AI 完成', r._meta);
        }
        renderAiAdvice(r);
        // 完成后给徽标加个 ✓
        if (_els.badgeAi) _els.badgeAi.textContent = '✓';
    } catch (e) {
        if (_els.aiContent) {
            _els.aiContent.innerHTML = `<div class="daa-header"><div class="daa-title">❌ AI 分析失败</div></div>
                <div class="daa-body" style="padding:12px;color:var(--danger)">${escapeHtml(e.message)}<br><br>请到"设置 → 网络 → DeepSeek"配 API key 并启用 AI 顾问</div>`;
        }
        if (_els.badgeAi) _els.badgeAi.textContent = '✗';
        toast('AI 分析失败: ' + e.message, { type: 'error' });
    }
}

function renderAiAdvice(r) {
    const score = r.score || 0;
    const scoreColor = score >= 8 ? 'var(--success)' : score >= 5 ? 'var(--warning,#f59e0b)' : 'var(--danger)';
    const ms = r._meta?.ms || 0;
    const model = r._meta?.model || 'deepseek';
    const isMock = !!r._meta?.mock;

    let html = `<div class="daa-header">
        <div class="daa-title">
            <span>🧠 AI 深度分析</span>
            <span class="daa-summary">综合评分 <strong style="color:${scoreColor}">${score}/10</strong> · ${ms}ms · ${escapeHtml(model)}</span>
        </div>
        <span class="daa-toggle">▾</span>
    </div>`;

    if (isMock) {
        html += `<div style="background:rgba(245,158,11,0.15);border:1px solid rgba(245,158,11,0.3);padding:8px 12px;font-size:12px;color:var(--warning,#f59e0b);margin:0 12px;border-radius:var(--r-sm,4px);margin-top:8px">
            ⚠️ 当前是 <strong>本地启发式 demo</strong>，建议到 <a href="javascript:void(0)" onclick="document.getElementById('openSettingsBtn').click()" style="color:var(--accent)">设置 → DeepSeek</a> 配 API key 获得真正 AI 智能建议（每次约 0.01-0.02 元）
        </div>`;
    }

    html += `<div class="daa-body">`;

    if (r.summary) {
        html += `<div class="daa-section"><div class="daa-section-title">📝 场景总结</div>
            <div class="daa-item">${escapeHtml(r.summary)}</div></div>`;
    }

    if (r.tags_breakdown) {
        const tb = r.tags_breakdown;
        html += `<div class="daa-section"><div class="daa-section-title">🏷️ Tag 分类</div><div class="daa-tags-grid">`;
        const labels = { character: '角色', pose_action: '姿势/动作', clothing: '服装', scene: '背景/场景', mood: '氛围', technical: '技术' };
        for (const [k, v] of Object.entries(tb)) {
            if (!v) continue;
            html += `<div class="daa-tag-cat"><span class="daa-tag-cat-label">${labels[k] || k}</span><span class="daa-tag-cat-value">${escapeHtml(v)}</span></div>`;
        }
        html += `</div></div>`;
    }

    if (r.issues && r.issues.length) {
        html += `<div class="daa-section daa-section-warn"><div class="daa-section-title">⚠️ 问题（${r.issues.length}）</div>`;
        for (const i of r.issues) {
            const sev = i.severity || 'medium';
            const icon = sev === 'high' ? '🔴' : sev === 'low' ? '🟢' : '🟡';
            html += `<div class="daa-item daa-item-${sev}">${icon} <strong>${escapeHtml(i.type || '')}</strong>${i.tag ? ` <code>${escapeHtml(i.tag)}</code>` : ''} - ${escapeHtml(i.message || '')}</div>`;
        }
        html += `</div>`;
    }

    if (r.suggestions && r.suggestions.length) {
        html += `<div class="daa-section"><div class="daa-section-title">💡 建议（${r.suggestions.length}）</div>`;
        for (const s of r.suggestions) {
            const actionIcon = { remove: '🗑', add: '➕', replace: '🔄', reweight: '⚖', reorder: '↕' }[s.action] || '·';
            let body = `<strong>${actionIcon} ${escapeHtml(s.action || '')}</strong>`;
            if (s.current) body += ` <code>${escapeHtml(s.current)}</code>`;
            if (s.suggested) body += ` → <code class="daa-suggested-code">${escapeHtml(s.suggested)}</code>`;
            if (s.reason) body += ` <span class="daa-note-inline">(${escapeHtml(s.reason)})</span>`;
            html += `<div class="daa-item daa-item-tip">${body}</div>`;
        }
        html += `</div>`;
    }

    if (r.optimized_prompt) {
        html += `<div class="daa-section"><div class="daa-section-title">✨ 优化后的 prompt</div>
            <textarea class="daa-optimized-text" readonly>${escapeHtml(r.optimized_prompt)}</textarea>
            <div style="margin-top:6px;display:flex;gap:6px">
                <button class="ghost-button small daa-copy-optimized" type="button">📄 复制</button>
                <button class="primary-button small daa-apply-optimized" type="button">→ 替换主输入</button>
            </div></div>`;
    }

    html += `</div>`;   // end daa-body

    if (_els.aiContent) _els.aiContent.innerHTML = html;

    // 折叠
    _els.aiContent.querySelector('.daa-header')?.addEventListener('click', () => {
        const body = _els.aiContent.querySelector('.daa-body');
        const toggle = _els.aiContent.querySelector('.daa-toggle');
        if (body) {
            body.classList.toggle('daa-body-collapsed');
            toggle.textContent = body.classList.contains('daa-body-collapsed') ? '▸' : '▾';
        }
    });

    // 复制
    _els.aiContent.querySelector('.daa-copy-optimized')?.addEventListener('click', async () => {
        const text = r.optimized_prompt || '';
        try { await navigator.clipboard.writeText(text); toast('已复制', { type: 'success' }); }
        catch { toast('复制失败', { type: 'error' }); }
    });

    // 应用到主输入
    _els.aiContent.querySelector('.daa-apply-optimized')?.addEventListener('click', () => {
        _els.input.value = r.optimized_prompt || '';
        updateInputMeta();
        toast('已替换主输入', { type: 'success' });
    });
}
