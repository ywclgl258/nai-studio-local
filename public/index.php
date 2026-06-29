<?php
/**
 * NAI Studio - Main entry. Serves the SPA shell.
 */
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use NaiStudio\Settings;

// Load settings — tolerate DB-down so user can still open the page and re-start MySQL
try {
    $settings = Settings::get();
} catch (\Throwable $e) {
    $settings = [];
}
$apiKeyPresent = !empty($settings['api_key_plain']);
$dbOnline = !empty($settings);

// Load default settings for the client
$defaultSettings = [
    'model'           => $settings['default_model']    ?? 'nai-diffusion-4-5-curated',
    'sampler'         => $settings['default_sampler']  ?? 'k_euler_ancestral',
    'steps'           => (int)($settings['default_steps']    ?? 28),
    'scale'           => (float)($settings['default_scale']  ?? 5.0),
    'cfg_rescale'     => (float)($settings['default_cfg_rescale'] ?? 0),
    'noise_schedule'  => $settings['default_noise_schedule']  ?? 'karras',
    'size'            => $settings['default_size']     ?? '832x1216',
    'uc_preset'       => (int)($settings['default_uc_preset']  ?? 0),
    'quality_toggle'  => (bool)($settings['quality_toggle']    ?? true),
    'emphasis_highlight' => (bool)($settings['emphasis_highlight'] ?? true),
    'theme'           => $settings['theme']            ?? 'dark',
    'anlas'           => $settings['anlas_balance']    ?? null,
];
$models = config('generation.allowed_models');
$samplers = config('generation.allowed_samplers');

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
?><!doctype html>
<html lang="zh-CN" data-theme="<?= htmlspecialchars($defaultSettings['theme']) ?>" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#0f1117">
    <title>NAI Studio · 本地生图工作台</title>
    <link rel="icon" type="image/svg+xml" href="favicon.svg?v=100">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32.png?v=100">
    <link rel="icon" type="image/x-icon" href="favicon.ico?v=100">
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png?v=100">
    <link rel="stylesheet" href="assets/css/main.css?v=104">
    <link rel="stylesheet" href="assets/css/components.css?v=104">
    <link rel="stylesheet" href="assets/css/tag-picker.css?v=104">
    <link rel="stylesheet" href="assets/css/gallery.css?v=104">
    <link rel="stylesheet" href="assets/css/mask-editor.css?v=104">
    <link rel="stylesheet" href="assets/css/themes.css?v=104">
    <script>
        // Boot-time data for the SPA, no extra fetch needed
        window.__NAI_BOOT__ = {
            defaultSettings: <?= json_encode($defaultSettings, JSON_UNESCAPED_UNICODE) ?>,
            models: <?= json_encode($models, JSON_UNESCAPED_UNICODE) ?>,
            samplers: <?= json_encode($samplers, JSON_UNESCAPED_UNICODE) ?>,
            apiKeyPresent: <?= $apiKeyPresent ? 'true' : 'false' ?>,
            ucPresets: <?= json_encode(config('uc_presets'), JSON_UNESCAPED_UNICODE) ?>,
            csrfToken: <?= json_encode(session_id() ?: '') ?>,
            version: '1.0.0',
        };
    </script>
</head>
<body>
    <div id="app" class="app-shell">
        <!-- Splash shown until JS hydrates -->
        <div class="boot-splash" id="bootSplash">
            <div class="boot-logo">N</div>
            <div class="boot-text">NAI Studio 加载中…</div>
        </div>
    </div>

    <!-- ============ Top bar ============ -->
    <template id="tpl-topbar">
        <header class="topbar" id="topbar">
            <button class="icon-button mobile-menu" id="mobileMenuBtn" title="展开/收缩">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
            </button>
            <div class="topbar-title">
                <span class="topbar-logo" aria-hidden="true">
                    <svg viewBox="0 0 32 32" width="28" height="28">
                        <defs>
                            <linearGradient id="logoGrad" x1="0" y1="0" x2="32" y2="32" gradientUnits="userSpaceOnUse">
                                <stop offset="0" stop-color="#a855f7"/>
                                <stop offset="0.5" stop-color="#6366f1"/>
                                <stop offset="1" stop-color="#06b6d4"/>
                            </linearGradient>
                            <linearGradient id="logoStroke" x1="0" y1="0" x2="0" y2="32" gradientUnits="userSpaceOnUse">
                                <stop offset="0" stop-color="#fff" stop-opacity="0.95"/>
                                <stop offset="1" stop-color="#fff" stop-opacity="0.7"/>
                            </linearGradient>
                        </defs>
                        <rect x="2" y="2" width="28" height="28" rx="7" fill="url(#logoGrad)"/>
                        <rect x="5.5" y="5.5" width="21" height="21" rx="4" fill="none" stroke="url(#logoStroke)" stroke-width="1.2" opacity="0.55"/>
                        <path d="M16 9v14M9 16h14" stroke="white" stroke-width="2" stroke-linecap="round"/>
                        <circle cx="16" cy="16" r="2.5" fill="white"/>
                    </svg>
                </span>
                <span class="topbar-brand">NAI Studio</span>
                <span class="topbar-sub" id="topbarSub">本地生图工作台</span>
            </div>
            <div class="topbar-anlas" id="anlasStatus" title="Anlas 余额（点击刷新）">
                <svg class="anlas-icon" viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v10M9 10h4a2 2 0 0 1 0 4H9"/></svg>
                <span class="anlas-dot"></span>
                <span class="anlas-value">--</span>
            </div>
            <button class="ghost-button" id="importImageBtn" title="导入图片（含元数据）">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5-5 5 5"/><path d="M12 5v12"/></svg>
                <span>导入图片</span>
            </button>
            <button class="ghost-button" id="tagPickerBtn" title="打开标签选择器">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.59 13.41 13.42 20.58a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><path d="M7 7h.01"/></svg>
                <span>标签库</span>
            </button>
            <button class="ghost-button" id="openSettingsBtn" title="设置">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
                <span>设置</span>
            </button>
            <button class="primary-button" id="openDirectorBtn" title="切换到 Director Tools">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 12-8.5 8.5a4.95 4.95 0 1 1-7-7L8 5"/><path d="m18 16 4-4"/><path d="m22 18-3-3"/><path d="m9 12 4-4"/><path d="m15 12 4 4"/></svg>
                <span id="modeSwitchLabel">Director</span>
            </button>
        </header>
    </template>

    <input type="file" id="importImageInput" accept="image/png,image/jpeg,image/webp" hidden>

    <!-- ============ Left panel (settings) ============ -->
    <template id="tpl-leftpanel">
        <aside class="left-panel" id="leftPanel">
            <button class="reset-workbench-button" id="resetWorkbenchBtn" type="button">
                <svg viewBox="0 0 24 24"><path d="M4 12a8 8 0 1 0 3-6.2M4 4v6h6"/></svg>
                <span>重置工作台</span>
            </button>

            <section class="api-key-card" id="apiKeyCard">
                <label for="apiKeyInput">API 密钥</label>
                <div class="api-key-input-wrap">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 7a4 4 0 1 0-3.2 3.9L5 17.7V21h3.3l1-1H12v-2.7l2.1-2.1A4 4 0 0 0 15 7Z"/><path d="M16 7h.01"/></svg>
                    <input id="apiKeyInput" type="password" placeholder="sk-..." autocomplete="off">
                    <button class="icon-button small ghost" id="toggleApiKeyVisibility" title="显示/隐藏">
                        <svg viewBox="0 0 24 24"><path d="M2 12s3.5-7 10-7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
                <p class="hint" id="apiKeyHint"></p>
            </section>

            <section class="model-card panel-card">
                <label>模型</label>
                <div class="custom-select-wrap">
                    <button class="custom-select-trigger" id="modelSelectTrigger" type="button" aria-expanded="false">
                        <span id="modelSelectLabel">NAI Diffusion V4.5 Curated</span>
                        <svg viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></svg>
                    </button>
                    <div class="custom-select-menu hidden" id="modelSelectMenu"></div>
                </div>
            </section>

            <section class="prompt-card panel-card">
                <div class="prompt-tabs">
                    <button class="prompt-tab active" data-prompt-tab="prompt">提示词</button>
                    <button class="prompt-tab" data-prompt-tab="negative">负面</button>
                    <button class="prompt-tab" data-prompt-tab="characters">角色</button>
                    <button class="prompt-tab" data-prompt-tab="pose">姿势</button>
                    <button class="icon-button small ghost" id="promptSettingsBtn" title="提示词设置（质量/片段/UC 预设）">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Zm8.5 4a6.8 6.8 0 0 0-.1-1l2-1.5-2-3.4-2.4 1a8.8 8.8 0 0 0-1.7-1L16 3.5h-4l-.4 2.6a8.8 8.8 0 0 0-1.7 1l-2.4-1-2 3.4 2 1.5a6.8 6.8 0 0 0 0 2l-2 1.5 2 3.4 2.4-1a8.8 8.8 0 0 0 1.7 1l.4 2.6h4l.4-2.6a8.8 8.8 0 0 0 1.7-1l2.4 1 2-3.4-2-1.5c.1-.3.1-.6.1-1Z"/></svg>
                    </button>
                </div>
                <!-- 主提示词预设快捷栏（独立行：tabs 下、editor 上）
                     必须在 .prompt-editor 外部 — 内部的 .prompt-highlight 是 absolute inset:0，会盖住 row
                     prompt.js 切 tab 时同步 toggle hidden（target !== 'prompt' 时隐藏） -->
                <div class="prompt-preset-row preset-row-kind-prompt" id="promptPresetRow" data-kind="prompt">
                    <span class="preset-kind-badge" data-kind="prompt" title="提示词预设">📋 提示词</span>
                    <select class="preset-select" id="promptPresetQuickSelect" title="载入提示词预设（同时设置主+负面+模型）">
                        <option value="">— 提示词预设 —</option>
                    </select>
                    <button class="ghost-button small" id="promptPresetQuickSaveBtn" title="把当前主+负面+模型保存为提示词预设">
                        <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 3h14v4H5V3zm0 6h14v12H5V9zm2 2v8h10v-8H7z"/></svg>
                        <span>保存</span>
                    </button>
                </div>
                <div class="prompt-editor" id="promptEditor">
                    <div class="prompt-highlight" id="promptHighlight" aria-hidden="true"></div>
                    <textarea id="promptInput" class="prompt-input" rows="4" spellcheck="false" placeholder="1girl, masterpiece, best quality, ..."></textarea>
                </div>
                <div class="prompt-editor hidden" id="negativeEditor">
                    <div class="prompt-highlight" id="negativeHighlight" aria-hidden="true"></div>
                    <textarea id="negativeInput" class="prompt-input" rows="3" spellcheck="false" placeholder="lowres, bad anatomy, ..."></textarea>
                </div>
                <div class="prompt-editor hidden" id="charactersEditor">
                    <p class="pose-hint-text">最终提示词 = 提示词 + 角色提示词 + 姿势提示词 · 最多 3 个角色</p>
                    <div id="characterPromptsList" class="character-prompts-list">
                        <!-- 动态生成 1-3 个角色提示词 textarea -->
                    </div>
                    <div class="character-prompts-actions">
                        <span class="char-count-hint">第 <span id="characterCount">1</span>/3 个</span>
                        <button class="link-button" id="characterAddBtn" title="新增一个角色（最多 3 个）">
                            <svg viewBox="0 0 24 24" width="13" height="13"><path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="2"/></svg>
                            添加角色
                        </button>
                    </div>
                    <div class="pose-action-row preset-row-kind-character" data-kind="character">
                        <span class="preset-kind-badge" data-kind="character" title="角色预设">👤 角色</span>
                        <button class="link-button" id="characterSavePresetBtn" title="把当前所有角色提示词存为预设">
                            <svg viewBox="0 0 24 24" width="13" height="13"><path d="M5 3h14v4H5V3zm0 6h14v12H5V9zm2 2v8h10v-8H7z" fill="currentColor"/></svg>
                            保存为预设
                        </button>
                        <select class="preset-select" id="characterPresetSelect" title="选择角色预设载入">
                            <option value="">— 角色预设 —</option>
                        </select>
                        <button class="icon-button small ghost" id="characterPresetManageBtn" title="管理预设（收藏/删除）">
                            <svg viewBox="0 0 24 24" width="14" height="14"><path d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Zm8.5 4a6.8 6.8 0 0 0-.1-1l2-1.5-2-3.4-2.4 1a8.8 8.8 0 0 0-1.7-1L16 3.5h-4l-.4 2.6a8.8 8.8 0 0 0-1.7 1l-2.4-1-2 3.4 2 1.5a6.8 6.8 0 0 0 0 2l-2 1.5 2 3.4 2.4-1a8.8 8.8 0 0 0 1.7 1l.4 2.6h4l.4-2.6a8.8 8.8 0 0 0 1.7-1l2.4 1 2-3.4-2-1.5c.1-.3.1-.6.1-1Z"/></svg>
                        </button>
                    </div>
                    <div class="character-preset-panel hidden" id="characterPresetPanel">
                        <p class="preset-panel-hint">★ = 收藏 · 收藏的预设排在前面</p>
                        <div id="characterPresetList"></div>
                    </div>
                </div>
                <div class="prompt-editor hidden" id="poseEditor">
                    <p class="pose-hint-text">默认追加到主提示词末尾 · 可保存为预设方便一键载入</p>
                    <textarea id="poseInput" class="prompt-input pose-input" rows="3" spellcheck="false" placeholder="例：standing, hands at sides, looking at viewer, ..."></textarea>
                    <div class="pose-action-row preset-row-kind-pose" data-kind="pose">
                        <span class="preset-kind-badge" data-kind="pose" title="姿势预设">🧍 姿势</span>
                        <button class="link-button" id="poseSavePresetBtn" title="把当前姿势提示词存为预设">
                            <svg viewBox="0 0 24 24" width="13" height="13"><path d="M5 3h14v4H5V3zm0 6h14v12H5V9zm2 2v8h10v-8H7z" fill="currentColor"/></svg>
                            保存为预设
                        </button>
                        <select class="preset-select" id="posePresetSelect" title="选择姿势预设载入">
                            <option value="">— 姿势预设 —</option>
                        </select>
                        <button class="icon-button small ghost" id="posePresetManageBtn" title="打开设置 → 预设 tab 管理收藏 / 删除">
                            <svg viewBox="0 0 24 24" width="14" height="14"><path d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Zm8.5 4a6.8 6.8 0 0 0-.1-1l2-1.5-2-3.4-2.4 1a8.8 8.8 0 0 0-1.7-1L16 3.5h-4l-.4 2.6a8.8 8.8 0 0 0-1.7 1l-2.4-1-2 3.4 2 1.5a6.8 6.8 0 0 0 0 2l-2 1.5 2 3.4 2.4-1a8.8 8.8 0 0 0 1.7 1l.4 2.6h4l.4-2.6a8.8 8.8 0 0 0 1.7-1l2.4 1 2-3.4-2-1.5c.1-.3.1-.6.1-1Z"/></svg>
                        </button>
                    </div>
                </div>

                <div class="quality-row">
                    <button class="mini-icon" id="qualityPresetBtn" title="质量标签">
                        <svg viewBox="0 0 24 24"><path d="m12 3 1.8 5.4H20l-5 3.6 1.9 5.7L12 14l-4.9 3.7L9 12 4 8.4h6.2L12 3Z"/></svg>
                    </button>
                    <input id="qualityWeight" type="range" min="0" max="1" step="0.05" value="0.18">
                    <button class="mini-icon" id="promptFragmentsBtn" title="提示词片段">
                        <svg viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h10"/></svg>
                    </button>
                </div>
            </section>

            <section class="module-card panel-card" id="vibePanel">
                <div class="module-title">
                    <svg viewBox="0 0 24 24"><path d="M8 7h8m-8 5h8m-8 5h5M4 4h16v16H4z"/></svg>
                    <div>
                        <h2>风格迁移 <span id="vibeCount">(0)</span></h2>
                        <p>改变画面观感，保留大致构图。</p>
                    </div>
                    <input type="file" id="vibeInput" accept="image/*" hidden>
                    <button class="icon-button small" id="addVibeBtn" title="添加参考图">
                        <svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
                    </button>
                </div>
                <label class="check-line compact">
                    <input type="checkbox" id="normalizeVibe" checked>
                    <span>归一化参考强度</span>
                </label>
                <div id="vibeList"></div>
            </section>

            <section class="module-card panel-card" id="precisePanel">
                <div class="module-title">
                    <svg viewBox="0 0 24 24"><path d="M4 5h16v14H4zM8 9h8M8 13h5"/></svg>
                    <div>
                        <h2>精确参考（V4+）</h2>
                        <p>为角色或画风添加参考图。</p>
                    </div>
                    <input type="file" id="preciseInput" accept="image/*" hidden>
                    <button class="icon-button small" id="addPreciseBtn" title="添加精确参考">
                        <svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
                    </button>
                </div>
                <div id="preciseList"></div>
            </section>

            <section class="image-settings">
                <h2>图像设置</h2>
                <div class="size-grid">
                    <div class="custom-select-wrap size-select-wrap">
                        <button class="custom-select-trigger" id="sizePresetTrigger" type="button" aria-expanded="false">
                            <span id="sizePresetLabel">普通竖图</span>
                            <svg viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></svg>
                        </button>
                        <div class="custom-select-menu hidden" id="sizePresetMenu"></div>
                    </div>
                    <div class="size-readout">
                        <span id="widthValue">832</span>
                        <span>×</span>
                        <span id="heightValue">1216</span>
                    </div>
                </div>
                <div class="sample-tabs" id="sampleTabs">
                    <button data-count="1" class="active">1</button>
                    <button data-count="2">2</button>
                    <button data-count="3">3</button>
                    <button data-count="4">4</button>
                </div>
            </section>

            <section class="ai-settings panel-card">
                <div class="settings-header">
                    <span>AI 设置</span>
                    <button class="icon-button small ghost" id="resetSettingsBtn" title="重置设置">
                        <svg viewBox="0 0 24 24"><path d="M4 12a8 8 0 1 0 3-6.2M4 4v6h6"/></svg>
                    </button>
                </div>
                <label>步数：<strong id="stepsValue">28</strong></label>
                <input id="stepsInput" type="range" min="1" max="50" value="28">
                <label>提示词引导：<strong id="scaleValue">5.0</strong></label>
                <input id="scaleInput" type="range" min="1" max="20" step="0.1" value="5">
                <div class="settings-grid">
                    <label>
                        <span>种子</span>
                        <input id="seedInput" type="number" min="0" placeholder="输入种子" value="">
                    </label>
                    <label>
                        <span>采样器</span>
                        <select id="samplerSelect">
                            <option value="k_euler_ancestral">Euler Ancestral</option>
                            <option value="k_euler">Euler</option>
                            <option value="k_dpmpp_2s_ancestral">DPM++ 2S Ancestral</option>
                            <option value="k_dpmpp_2m">DPM++ 2M</option>
                            <option value="k_dpmpp_2m_sde">DPM++ 2M SDE</option>
                            <option value="k_dpmpp_sde">DPM++ SDE</option>
                            <option value="ddim">DDIM</option>
                        </select>
                    </label>
                </div>
                <details>
                    <summary>高级设置</summary>
                    <label>CFG Rescale：<strong id="cfgValue">0.00</strong></label>
                    <input id="cfgInput" type="range" min="0" max="1" step="0.02" value="0">
                    <label>Noise Schedule</label>
                    <select id="noiseScheduleSelect">
                        <option value="karras">karras</option>
                        <option value="native">native</option>
                        <option value="exponential">exponential</option>
                        <option value="polyexponential">polyexponential</option>
                    </select>
                </details>
            </section>

            <div class="generate-row">
                <button class="generate-button" id="generateBtn">
                    <span class="generate-label" id="generateLabel">生成 1 张图片</span>
                    <span class="generate-shortcut">Ctrl + Enter</span>
                </button>
                <button class="queue-button" id="queueGenerateBtn" title="加入队列（4 张 / 每 20 秒）">
                    <svg viewBox="0 0 24 24" width="20" height="20"><path d="M3 6h18M6 6v12h12V6M9 9l3 3 3-3" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><rect x="3" y="3" width="18" height="4" fill="currentColor" opacity="0.3"/></svg>
                </button>
            </div>
            <div class="queue-hint">队列生图：4 张 · 每 20s</div>
            <div class="queue-mode-row">
                <button class="queue-mode-btn" id="queueCustomBtn" title="设置张数 / 间隔 / 重试次数">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M6 6v12h12V6M9 9l3 3 3-3"/><rect x="3" y="3" width="18" height="4" fill="currentColor" opacity="0.25"/></svg>
                    <div class="queue-mode-text">
                        <div class="queue-mode-title">普通队列</div>
                        <div class="queue-mode-desc">N 张 · 间隔秒 · 自动重试</div>
                    </div>
                </button>
                <button class="queue-mode-btn queue-mode-btn-highlight" id="openProjectQueueBtn" title="用姿势预设 × 不同张数批量生成">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><path d="M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v0a2 2 0 0 1-2 2h-2a2 2 0 0 1-2-2z"/><path d="M9 12h.01M9 16h.01M13 12h2M13 16h2"/></svg>
                    <div class="queue-mode-text">
                        <div class="queue-mode-title">🧪 工程队列</div>
                        <div class="queue-mode-desc">姿势 × 不同张数</div>
                    </div>
                </button>
            </div>
        </aside>
    </template>

    <!-- ============ Main gallery area ============ -->
    <template id="tpl-main">
        <main class="form-area gallery-area" id="generationPage">
            <aside class="preview-panel" id="previewPanel">
                <div class="preview-panel-header">
                    <span class="title">预览</span>
                    <span class="meta" id="previewMeta"></span>
                </div>
                <div class="preview-panel-body">
                    <section class="gallery-focus" id="galleryFocus">
                        <div class="empty-gallery-message" id="emptyGalleryMessage">
                            <svg viewBox="0 0 24 24" width="48" height="48" stroke="currentColor" fill="none" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.5-3.5L9 19"/></svg>
                            <h3>这里会陈列你的生成结果</h3>
                            <p>在左侧写下提示词，按 <kbd>Ctrl</kbd>+<kbd>Enter</kbd> 开始。</p>
                        </div>
                        <div class="gallery-main-image hidden" id="galleryMainImage" tabindex="0">
                            <img id="galleryMainImg" alt="当前生成图片">
                            <div class="gallery-main-fab">
                                <button id="mainDownloadBtn" title="下载原图 (Ctrl+D)">
                                    <svg viewBox="0 0 24 24"><path d="M12 3v12m0 0 4-4m-4 4-4-4M5 21h14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </button>
                                <button id="mainApplyBtn" title="应用提示词到表单">
                                    <svg viewBox="0 0 24 24"><path d="m5 12 5 5 9-9" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </button>
                                <button id="mainDirectorBtn" title="发送到 Director">
                                    <svg viewBox="0 0 24 24"><path d="M4 20h16M6 16l8-8 4 4-8 8H6v-4Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </button>
                                <button id="mainCopyPromptBtn" title="复制提示词">
                                    <svg viewBox="0 0 24 24"><rect x="9" y="9" width="11" height="11" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                                </button>
                                <button id="mainFavoriteBtn" title="收藏 / 取消">
                                    <svg viewBox="0 0 24 24"><path d="m12 3 2.7 6 6.3.6-4.8 4.4 1.5 6.4L12 17l-5.7 3.4 1.5-6.4L3 9.6 9.3 9z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>
                                </button>
                                <button class="danger" id="mainDeleteBtn" title="删除">
                                    <svg viewBox="0 0 24 24"><path d="M6 7h12m-10 0 .7 13h6.6L16 7M10 7V5h4v2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </button>
                            </div>
                            <div class="gallery-main-info" id="galleryMainInfo">
                                <div class="gallery-main-prompt" id="galleryMainPrompt"></div>
                                <div class="gallery-main-meta">
                                    <span id="galleryMainModel"></span>
                                    <span id="galleryMainSeed"></span>
                                    <span id="galleryMainSize"></span>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>
            </aside>
        </main>

        <!-- Right history sidebar (vertical thumbnails) -->
        <aside class="gallery-history-strip" id="galleryHistorySidebar">
            <div class="strip-header">
                <span class="strip-title">历史 <span class="badge" id="galleryCount">0</span></span>
                <div class="strip-actions">
                    <button id="galleryFilterFavBtn" title="只看收藏">
                        <svg viewBox="0 0 24 24"><path d="m12 3 2.7 6 6.3.6-4.8 4.4 1.5 6.4L12 17l-5.7 3.4 1.5-6.4L3 9.6 9.3 9z"/></svg>
                    </button>
                    <button id="galleryZipBtn" title="一键打包下载（zip）">
                        <svg viewBox="0 0 24 24"><path d="M12 3v12m0 0 4-4m-4 4-4-4M5 21h14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                    <button id="galleryRefreshBtn" title="刷新">
                        <svg viewBox="0 0 24 24"><path d="M4 12a8 8 0 0 1 14-5.3L21 4M20 4v5h-5M20 12a8 8 0 0 1-14 5.3L3 20M4 20v-5h5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                    <button class="danger" id="galleryClearBtn" title="清空全部历史">
                        <svg viewBox="0 0 24 24"><path d="M6 7h12m-10 0 .7 13h6.6L16 7M10 7V5h4v2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <span>清空</span>
                    </button>
                </div>
            </div>
            <div class="gallery-history-list" id="galleryGrid"></div>
            <div class="gallery-load-more hidden" id="galleryLoadMore">
                <button id="galleryLoadMoreBtn">加载更多</button>
            </div>
        </aside>

        <main class="director-page hidden" id="directorPage">
            <div class="director-rail">
                <button class="director-upload-button" id="directorUploadBtn" title="上传图片">
                    <svg viewBox="0 0 24 24"><path d="M12 16V4m0 0 4 4m-4-4-4 4M5 20h14"/></svg>
                </button>
                <input type="file" id="directorInput" accept="image/*" hidden>
                <button class="director-back" id="directorBackBtn" title="返回生图">
                    <svg viewBox="0 0 24 24"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                </button>
            </div>
            <section class="director-workspace">
                <div class="director-canvas" id="directorSource">
                    <div class="director-placeholder">
                        <svg viewBox="0 0 24 24"><path d="M4 5h16v14H4z"/><path d="m7 15 3-3 3 3 2-2 3 4H6z"/></svg>
                        <p>把图片拖到这里</p>
                    </div>
                </div>
                <div class="director-canvas" id="directorResult">
                    <div class="director-placeholder">
                        <svg viewBox="0 0 24 24"><path d="M4 5h16v14H4z"/><path d="m7 15 3-3 3 3 2-2 3 4H6z"/></svg>
                        <p>结果会出现在这里</p>
                    </div>
                </div>
            </section>
            <section class="director-controls">
                <div class="director-tool-options" id="directorToolOptions"></div>
                <div class="director-toolbar">
                    <button class="director-tool active" data-tool="augment-bg-removal" title="去背景">去背景</button>
                    <button class="director-tool" data-tool="augment-lineart" title="线稿">线稿</button>
                    <button class="director-tool" data-tool="augment-sketch" title="草图">草图</button>
                    <button class="director-tool" data-tool="augment-colorize" title="上色">上色</button>
                    <button class="director-tool" data-tool="augment-emotion" title="表情">表情</button>
                    <button class="director-tool" data-tool="augment-declutter" title="去杂物">去杂物</button>
                    <button class="director-transform" id="directorTransformBtn">转换</button>
                </div>
            </section>
        </main>
    </template>

    <!-- ============ Modals & overlays ============ -->
    <div class="floating-panel hidden" id="promptSettingsPanel">
        <div class="mini-tabs">
            <button class="active" data-tab="settings">设置</button>
            <button data-tab="snippets">片段</button>
            <button data-tab="presets">预设</button>
        </div>
        <div class="mini-panel" data-pane="settings">
            <label class="toggle-row">
                <span>添加质量标签</span>
                <input type="checkbox" id="qualitySettingsToggle" checked>
            </label>
            <label>
                <span>负面内容预设</span>
                <select id="ucPreset">
                    <option value="0">重度 (heavy)</option>
                    <option value="1">轻度 (light)</option>
                    <option value="2">人像 (portrait)</option>
                    <option value="3">无 (none)</option>
                </select>
            </label>
            <label class="toggle-row">
                <span>高亮强调语法</span>
                <input type="checkbox" id="emphasisHighlightToggle" checked>
            </label>
        </div>
        <div class="mini-panel hidden" data-pane="snippets">
            <textarea id="snippetsArea" rows="10" placeholder="每行一段：标题 | 内容"></textarea>
            <div class="mini-actions">
                <button class="ghost-button small" id="snippetsAddBtn">插入选中到提示词</button>
                <button class="ghost-button small" id="snippetsSaveBtn">保存</button>
            </div>
        </div>
        <div class="mini-panel hidden" data-pane="presets">
            <div style="display:flex;gap:6px;margin-bottom:8px">
                <select class="preset-select" id="presetSelectPane" style="flex:1">
                    <option value="">— 选择预设载入 —</option>
                </select>
            </div>
            <details>
                <summary style="cursor:pointer;color:var(--text-secondary);font-size:12px;user-select:none">管理预设</summary>
                <div id="presetsList" style="margin-top:8px"></div>
            </details>
            <div class="mini-actions">
                <button class="ghost-button small" id="presetSaveBtn">保存当前为预设</button>
            </div>
        </div>
    </div>

    <div class="gallery-action-menu hidden" id="galleryActionMenu">
        <button type="button" data-gallery-action="prompt">应用提示词到表单</button>
        <button type="button" data-gallery-action="director">应用到 Director</button>
        <button type="button" data-gallery-action="download">下载图片</button>
        <button type="button" data-gallery-action="favorite">收藏 / 取消</button>
        <button type="button" data-gallery-action="copy-prompt">复制提示词</button>
        <button type="button" data-gallery-action="copy-seed">复制种子</button>
        <button type="button" class="danger" data-gallery-action="delete">删除图片</button>
    </div>

    <!-- Tag picker overlay (仿 tags.novelai.dev 三栏布局) -->
    <div class="tag-picker hidden" id="tagPicker">
        <div class="tag-picker-header">
            <div class="tag-picker-title">
                <span class="tag-picker-title-icon">🏷</span>
                <span>标签超市</span>
                <span class="tag-picker-source-tabs">
                    <button class="active" data-source="local">本地库</button>
                    <button data-source="danbooru">在线 (Danbooru)</button>
                </span>
            </div>
            <div class="tag-picker-search-row">
                <svg class="tag-picker-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.35-4.35"/></svg>
                <input type="search" id="tagPickerSearch" placeholder="搜索标签 — 中文 / 英文 / 分类" autocomplete="off">
                <button class="icon-button ghost" id="tagPickerCloseBtn" title="关闭 (Esc)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>
                </button>
            </div>
        </div>
        <div class="tag-picker-main">
            <!-- 左侧：分类侧栏 -->
            <aside class="tag-picker-sidebar" id="tagPickerCategories"></aside>
            <!-- 中间：标签网格 -->
            <div class="tag-picker-center">
                <div class="tag-picker-center-header">
                    <div class="tag-picker-center-title" id="tagPickerCenterTitle">全部</div>
                    <div class="tag-picker-center-meta">
                        <span id="tagPickerCount">0</span> 标签
                        <span class="tag-picker-divider">·</span>
                        <span id="tagPickerTotal">0</span> 累计
                    </div>
                </div>
                <div class="tag-picker-body" id="tagPickerBody"></div>
                <div class="tag-picker-loadmore">
                    <button class="ghost-button" id="tagPickerLoadMoreBtn">加载更多 ↓</button>
                </div>
            </div>
            <!-- 右侧：已选面板 -->
            <aside class="tag-picker-selected">
                <div class="tag-picker-selected-header">
                    <span>已选</span>
                    <span class="tag-picker-selected-badge" id="tagPickerSelectedCount">0</span>
                </div>
                <div class="tag-picker-selected-list" id="tagPickerSelectedList"></div>
                <div class="tag-picker-selected-empty" id="tagPickerSelectedEmpty">
                    <div class="tag-picker-selected-empty-icon">⊕</div>
                    <div>点击左侧标签卡即可添加</div>
                    <div class="tag-picker-selected-empty-hint">长按/右键标签可调权重</div>
                </div>
            </aside>
        </div>
        <div class="tag-picker-footer">
            <div class="tag-picker-footer-info">
                <span>提示：按 <kbd>Enter</kbd> 插入 · <kbd>Esc</kbd> 关闭</span>
            </div>
            <div class="tag-picker-footer-actions">
                <button class="ghost-button" id="tagPickerClearBtn">清空选择</button>
                <button class="primary-button" id="tagPickerInsertBtn">插入到提示词</button>
            </div>
        </div>
    </div>

    <!-- Settings modal -->
    <div class="modal-backdrop hidden" id="settingsModal">
        <section class="settings-modal" role="dialog" aria-modal="true" aria-labelledby="settingsTitle">
            <button class="modal-close" id="closeSettingsBtn" title="关闭">×</button>
            <h2 id="settingsTitle">设置</h2>
            <div class="settings-tabs">
                <button class="active" data-settings-tab="general">常规</button>
                <button data-settings-tab="ui">界面</button>
                <button data-settings-tab="presets">预设</button>
                <button data-settings-tab="actions">一键操作</button>
                <button data-settings-tab="data">数据</button>
                <button data-settings-tab="about">关于</button>
            </div>
            <div class="settings-pane" data-pane="general">
                <h3 style="font-size:13px;color:var(--accent);margin:0 0 8px">网络代理</h3>
                <p style="font-size:11px;color:var(--text-secondary);margin-bottom:8px">
                    遇到「连不上 API / 500 / 超时」时使用代理。代理开启后，NAI 生图和 Danbooru 标签查询都会走代理。
                </p>
                <label class="toggle-row">
                    <span>启用代理</span>
                    <input type="checkbox" id="settingsProxyEnabled">
                </label>
                <label>代理地址
                    <input id="settingsProxy" type="text" placeholder="例如 http://127.0.0.1:7890">
                </label>
                <div style="display:flex;align-items:center;gap:8px">
                    <button class="ghost-button small" id="testProxyBtn">测试连接</button>
                    <span id="proxyTestStatus" style="font-size:11px;color:var(--text-muted)"></span>
                </div>

                <hr style="border:none;border-top:1px solid var(--border);margin:16px 0">

                <h3 style="font-size:13px;color:var(--accent);margin:0 0 8px">默认参数</h3>
                <label>默认模型
                    <select id="settingsDefaultModel"></select>
                </label>
                <label>默认尺寸
                    <select id="settingsDefaultSize">
                        <option value="832x1216">832 × 1216（普通竖图）</option>
                        <option value="1024x1024">1024 × 1024（方图）</option>
                        <option value="1216x832">1216 × 832（普通横图）</option>
                        <option value="1024x1536">1024 × 1536（大竖图）</option>
                        <option value="1536x1024">1536 × 1024（大横图）</option>
                    </select>
                </label>
                <label>默认步数 <input id="settingsDefaultSteps" type="number" min="1" max="50" value="28"></label>
                <label>默认 CFG <input id="settingsDefaultScale" type="number" min="1" max="20" step="0.1" value="5"></label>
            </div>
            <div class="settings-pane hidden" data-pane="ui">
                <label>主题
                    <select id="settingsTheme">
                        <option value="dark">深色</option>
                        <option value="midnight">极夜</option>
                        <option value="light">浅色</option>
                    </select>
                </label>
                <label class="toggle-row">
                    <span>强调语法高亮</span>
                    <input type="checkbox" id="settingsEmphasis">
                </label>
                <label class="toggle-row">
                    <span>添加质量标签</span>
                    <input type="checkbox" id="settingsQuality">
                </label>
            </div>
            <div class="settings-pane hidden" data-pane="presets">
                <p style="font-size:12px;color:var(--text-secondary);margin:0 0 12px">
                    统一管理主提示词 / 角色 / 姿势三种预设。点击 ✕ 单独删除，点击 ★ 收藏。
                </p>
                <input type="search" id="presetSearch" placeholder="搜索预设…" style="width:100%;padding:6px 10px;background:var(--bg);color:var(--text);border:1px solid var(--border);border-radius:var(--r);font-size:12px;margin-bottom:12px">
                <h3 style="margin:0 0 8px;font-size:13px;color:var(--accent)">主提示词预设 <span id="presetCountPrompt" style="color:var(--text-muted);font-weight:normal">(0)</span></h3>
                <div id="presetManagerListPrompt" style="display:flex;flex-direction:column;gap:4px;margin-bottom:16px;max-height:180px;overflow-y:auto"></div>
                <h3 style="margin:0 0 8px;font-size:13px;color:var(--accent)">角色预设 <span id="presetCountChar" style="color:var(--text-muted);font-weight:normal">(0)</span></h3>
                <div id="presetManagerListChar" style="display:flex;flex-direction:column;gap:4px;margin-bottom:16px;max-height:180px;overflow-y:auto"></div>
                <h3 style="margin:0 0 8px;font-size:13px;color:var(--accent)">姿势预设 <span id="presetCountPose" style="color:var(--text-muted);font-weight:normal">(0)</span></h3>
                <div id="presetManagerListPose" style="display:flex;flex-direction:column;gap:4px;max-height:180px;overflow-y:auto"></div>
            </div>
            <div class="settings-pane hidden" data-pane="actions">
                <h3 style="margin:0 0 8px;font-size:14px;color:var(--accent)">后端服务状态</h3>
                <p style="font-size:12px;color:var(--text-secondary);margin-bottom:12px">
                    控制本机 XAMPP（Apache + MySQL）服务的启停。停止后网站不可访问。
                </p>

                <div class="status-panel" id="statusOverall">
                    <span class="status-dot dot"></span>
                    <span class="label">检测中…</span>
                </div>

                <div class="backend-status-row">
                    <div class="backend-status-card" id="statusApache">
                        <span class="status-dot dot"></span>
                        <span class="label">Apache</span>
                    </div>
                    <div class="backend-status-card" id="statusMysql">
                        <span class="status-dot dot"></span>
                        <span class="label">MySQL</span>
                    </div>
                </div>

                <div class="backend-actions">
                    <button class="primary-button" id="actionBackendStart" style="flex:1;padding:14px">
                        <svg viewBox="0 0 24 24" width="18" height="18" style="vertical-align:-3px;margin-right:6px"><path d="M8 5v14l11-7z" fill="currentColor"/></svg>
                        一键启动
                    </button>
                    <button class="ghost-button" id="actionBackendStop" style="flex:1;padding:14px">
                        <svg viewBox="0 0 24 24" width="18" height="18" style="vertical-align:-3px;margin-right:6px"><rect x="6" y="6" width="12" height="12" fill="currentColor"/></svg>
                        一键停止
                    </button>
                </div>
                <button class="ghost-button small" id="actionBackendRefresh" style="width:100%;margin-top:8px">
                    <svg viewBox="0 0 24 24" width="14" height="14" style="vertical-align:-2px;margin-right:4px"><path d="M4 12a8 8 0 0 1 14-5.3L20 5v6h-6l2.4-2.4A6 6 0 0 0 6 12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M20 12a8 8 0 0 1-14 5.3L4 19v-6h6l-2.4 2.4A6 6 0 0 0 18 12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    刷新状态
                </button>

                <hr style="border:none;border-top:1px solid var(--border);margin:20px 0">

                <h3 style="margin:0 0 8px;font-size:14px;color:var(--danger)">一键清理</h3>
                <p style="font-size:12px;color:var(--text-secondary);margin-bottom:12px">
                    清理画廊历史、缓存、孤立文件、日志。可选择保留收藏。
                </p>
                <div class="cleanup-options">
                    <label class="toggle-row">
                        <span>保留收藏的图片</span>
                        <input type="checkbox" id="cleanupKeepFav" checked>
                    </label>
                </div>
                <button class="danger-button" id="actionCleanup" style="width:100%;margin-top:8px;padding:14px">
                    <svg viewBox="0 0 24 24" width="18" height="18" style="vertical-align:-3px;margin-right:6px"><path d="M6 7h12m-10 0 .7 13h6.6L16 7M10 7V5h4v2M3 7h18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    一键清理
                </button>

                <!-- 标签库扩充（仿 tags.novelai.dev：批量预下载） -->
                <div class="action-section" style="margin-top:16px;border-top:1px solid var(--border);padding-top:12px">
                    <h4 style="margin:0 0 8px;font-size:13px">🏷 标签库扩充</h4>
                    <p style="margin:0 0 8px;font-size:11px;color:var(--text-muted);line-height:1.5">
                        从 Danbooru 拉取热门标签，自动翻译中文 + 预下载示例图。<br>
                        跑一次终身离线用，约 10-30 分钟。
                    </p>
                    <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-bottom:8px">
                        <label style="font-size:11px;display:flex;align-items:center;gap:4px">
                            最低 post 数：
                            <input type="number" id="expandMinPosts" value="100" min="1" style="width:70px;padding:4px 6px;background:var(--bg);border:1px solid var(--border);border-radius:4px;color:var(--text);font-size:11px">
                        </label>
                        <label style="font-size:11px;display:flex;align-items:center;gap:4px">
                            最多页数：
                            <input type="number" id="expandMaxPages" value="20" min="1" max="100" style="width:60px;padding:4px 6px;background:var(--bg);border:1px solid var(--border);border-radius:4px;color:var(--text);font-size:11px">
                            (×1000)
                        </label>
                        <label style="font-size:11px;display:flex;align-items:center;gap:4px">
                            <input type="checkbox" id="expandWithImages" checked>下载示例图
                        </label>
                    </div>
                    <div style="display:flex;gap:6px">
                        <button class="primary-button" id="actionExpandTags" style="flex:1;padding:10px">开始扩充</button>
                        <button class="ghost-button" id="actionStopExpand" style="padding:10px">停止</button>
                    </div>
                    <div id="expandProgress" style="margin-top:10px;font-size:11px;color:var(--text-secondary);min-height:18px"></div>
                    <div id="expandBar" style="margin-top:6px;height:6px;background:var(--bg-elevated-2);border-radius:3px;overflow:hidden">
                        <div id="expandBarFill" style="height:100%;background:var(--accent);width:0%;transition:width .3s"></div>
                    </div>
                </div>

                <div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--border)">
                    <h4 style="margin:0 0 8px;font-size:13px">🌐 导入 Danbooru 全部标签</h4>
                    <p style="margin:0 0 8px;font-size:11px;color:var(--text-muted);line-height:1.5">
                        一把拉全量约 <strong>30 万 tag</strong> 入库（不用翻页筛选项）。<br>
                        翻译：内置字典秒级命中；其余留英文（MyMemory 免费额度不可能全翻）。<br>
                        <span style="color:var(--warning)">一次性后台跑 1-2 小时，可关浏览器。</span>
                    </p>
                    <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-bottom:8px">
                        <label style="font-size:11px;display:flex;align-items:center;gap:4px">
                            最低 post 数：
                            <input type="number" id="importAllMinPosts" value="1" min="1" style="width:70px;padding:4px 6px;background:var(--bg);border:1px solid var(--border);border-radius:4px;color:var(--text);font-size:11px">
                        </label>
                        <label style="font-size:11px;display:flex;align-items:center;gap:4px">
                            最多页数：
                            <input type="number" id="importAllMaxPages" value="500" min="1" max="2000" style="width:70px;padding:4px 6px;background:var(--bg);border:1px solid var(--border);border-radius:4px;color:var(--text);font-size:11px">
                            (×1000)
                        </label>
                    </div>
                    <div style="display:flex;gap:6px">
                        <button class="primary-button" id="actionImportAll" style="flex:1;padding:10px">开始导入</button>
                        <button class="ghost-button" id="actionStopImportAll" style="padding:10px">停止</button>
                    </div>
                    <div id="importAllProgress" style="margin-top:10px;font-size:11px;color:var(--text-secondary);min-height:18px"></div>
                    <div id="importAllBar" style="margin-top:6px;height:6px;background:var(--bg-elevated-2);border-radius:3px;overflow:hidden">
                        <div id="importAllBarFill" style="height:100%;background:var(--warning);width:0%;transition:width .3s"></div>
                    </div>
                </div>
            </div>
            <div class="settings-pane hidden" data-pane="data">
                <p>所有数据存于本地 MySQL 数据库 <code>nai_studio</code>，图片存于 <code>public/storage/</code>。</p>
                <div class="settings-stats" id="settingsStats"></div>
                <div class="mini-actions">
                    <button class="ghost-button" id="exportAllBtn">导出全部（JSON）</button>
                    <button class="danger-button" id="clearGalleryBtn">清空画廊</button>
                </div>
            </div>
            <div class="settings-pane hidden" data-pane="about">
                <h3>NAI Studio</h3>
                <p>本地生图工作台 · v<span id="aboutVersion"></span></p>
                <p>栈：Apache + MySQL/MariaDB + PHP 8.2 + Perl 5.32</p>
                <p>本工具仅作个人创作辅助，所有数据本地存储，请妥善保管你的 API Key。</p>
            </div>
            <div class="modal-footer">
                <button class="ghost-button" id="cancelSettingsBtn">取消</button>
                <button class="primary-button" id="saveSettingsBtn">保存</button>
            </div>
        </section>
    </div>

    <!-- Import modal -->
    <div class="modal-backdrop hidden" id="imageImportModal">
        <section class="image-import-modal" role="dialog" aria-modal="true" aria-labelledby="imageImportTitle">
            <button class="modal-close" id="closeImageImportBtn" title="关闭">×</button>
            <h2 id="imageImportTitle">要如何使用这张图片？</h2>
            <div class="import-preview-frame">
                <img id="importPreview" alt="导入预览">
            </div>
            <div class="import-action-row">
                <button id="importAsImg2ImgBtn">图生图</button>
                <button id="importAsVibeBtn">风格迁移</button>
                <button id="importAsPreciseBtn">精确参考</button>
            </div>
            <div class="metadata-panel" id="metadataPanel">
                <h3>检测到元数据</h3>
                <p>勾选要导入的项目，值会写入当前工作台。</p>
                <div class="metadata-grid">
                    <label><input type="checkbox" id="metaPromptCheck" checked> 提示词 <strong id="metaPromptValue">-</strong></label>
                    <label><input type="checkbox" id="metaNegativeCheck" checked> 负面提示词 <strong id="metaNegativeValue">-</strong></label>
                    <label><input type="checkbox" id="metaSettingsCheck" checked> 设置 <strong id="metaSettingsValue">-</strong></label>
                    <label><input type="checkbox" id="metaSeedCheck" checked> 种子 <strong id="metaSeedValue">-</strong></label>
                    <label><input type="checkbox" id="metaAppendCheck"> 追加到现有提示词</label>
                </div>
                <button class="primary-modal-btn" id="importMetadataBtn">导入元数据</button>
            </div>
            <div class="metadata-panel hidden" id="metadataEmptyPanel">
                <h3>没有检测到可导入元数据</h3>
                <p>仍可作为图生图、风格迁移或精确参考使用。</p>
            </div>
        </section>
    </div>

    <!-- Mask editor -->
    <div class="mask-editor hidden" id="maskEditor">
        <div class="mask-topbar">
            <div class="mask-tool-card">
                <button class="mask-tool active" id="maskBrushBtn" type="button">画笔</button>
                <label>笔刷：<strong id="maskBrushValue">48</strong></label>
                <input id="maskBrushSize" type="range" min="4" max="160" value="48">
                <label class="check-line compact"><input type="checkbox" id="maskSquareBrush"> 方形笔刷</label>
            </div>
            <div class="mask-tool-card">
                <button class="mask-tool" id="maskEraseBtn" type="button">擦除</button>
                <label>边缘羽化：<strong id="maskFeatherValue">0</strong></label>
                <input id="maskFeatherSize" type="range" min="0" max="64" value="0">
            </div>
            <div class="mask-tool-card">
                <button class="mask-tool" id="maskClearBtn" type="button">清空</button>
                <button class="mask-tool" id="maskInvertBtn" type="button">反选</button>
            </div>
            <div class="mask-editor-actions">
                <button class="primary-modal-btn" id="saveMaskBtn">保存并关闭</button>
                <button class="icon-button small" id="closeMaskBtn" title="关闭">×</button>
            </div>
        </div>
        <div class="mask-stage-wrap">
            <div class="mask-stage" id="maskStage">
                <img id="maskBaseImage" alt="局部重绘底图">
                <canvas id="maskCanvas"></canvas>
            </div>
        </div>
    </div>

    <!-- Batch generation modal -->
    <div class="modal-backdrop hidden" id="batchModal">
        <section class="settings-modal" role="dialog" aria-modal="true" aria-labelledby="batchTitle" style="max-width:480px">
            <button class="modal-close" id="closeBatchBtn" title="关闭">×</button>
            <h2 id="batchTitle">自定义批量生图</h2>
            <p style="font-size:12px;color:var(--text-secondary);margin-bottom:16px">
                提交后会按顺序入队，逐张跑，间隔秒数。带随机种子选项。
            </p>
            <div class="batch-form" style="padding:0">
                <label>生图数量
                    <input id="batchCount" type="number" min="1" max="20" value="4">
                </label>
                <label>间隔秒数
                    <input id="batchInterval" type="number" min="0" max="600" step="1" value="20">
                </label>
                <label class="toggle-row">
                    <span>每张使用随机种子</span>
                    <input type="checkbox" id="batchRandomSeed" checked>
                </label>
                <label class="toggle-row" title="失败的图自动进入下一轮重试，最多 3 轮，间隔 30s">
                    <span>失败自动重试</span>
                    <input type="checkbox" id="batchAutoRetry">
                </label>
                <label>单次生成数量
                    <select id="batchN">
                        <option value="1">1 张/次</option>
                        <option value="2">2 张/次</option>
                        <option value="3">3 张/次</option>
                        <option value="4">4 张/次</option>
                    </select>
                </label>
                <div style="background:var(--bg-elevated-2);border-radius:var(--r);padding:12px;margin-top:8px;font-size:12px;color:var(--text-secondary)">
                    <strong style="color:var(--text)">预估消耗：</strong>
                    <span id="batchEstimate">~4 次 NAI 请求</span>
                </div>
            </div>
            <div class="modal-footer">
                <button class="ghost-button" id="cancelBatchBtn">取消</button>
                <button class="primary-button" id="confirmBatchBtn">开始生图</button>
            </div>
        </section>
    </div>

    <!-- Cleanup confirm modal -->
    <div class="modal-backdrop hidden" id="cleanupModal">
        <section class="settings-modal" role="dialog" aria-modal="true" aria-labelledby="cleanupTitle" style="max-width:480px">
            <button class="modal-close" id="closeCleanupBtn" title="关闭">×</button>
            <h2 id="cleanupTitle">一键清理</h2>
            <p style="font-size:12px;color:var(--text-secondary);margin-bottom:16px">
                将清理以下内容，<strong style="color:var(--danger)">操作不可撤销</strong>：
            </p>
            <ul style="font-size:13px;line-height:1.8;color:var(--text);background:var(--bg-elevated-2);border-radius:var(--r);padding:12px 24px;list-style:disc;margin:0 0 16px">
                <li>画廊历史图片 <span id="cleanupRows">0</span> 张</li>
                <li>API 响应缓存</li>
                <li>孤立文件（DB 无引用的图片/缩略图）</li>
                <li>过期日志</li>
            </ul>
            <label class="toggle-row" style="margin-bottom:16px">
                <span><strong>保留收藏的图片</strong></span>
                <input type="checkbox" id="cleanupKeepFavModal" checked>
            </label>
            <div class="modal-footer">
                <button class="ghost-button" id="cancelCleanupBtn">取消</button>
                <button class="danger-button" id="confirmCleanupBtn">确认清理</button>
            </div>
        </section>
    </div>

    <!-- Preset save modal -->
    <div class="modal-backdrop hidden" id="presetSaveModal">
        <section class="settings-modal" role="dialog" aria-modal="true" style="max-width:440px">
            <button class="modal-close" id="closePresetSaveBtn" title="关闭">×</button>
            <h2 id="presetSaveTitle">保存预设</h2>
            <p style="font-size:12px;color:var(--text);margin-bottom:16px;padding:8px 12px;background:var(--bg-elevated-2);border-radius:var(--r-sm)" id="presetSaveHint"></p>
            <div style="display:flex;flex-direction:column;gap:14px">
                <label style="font-size:13px;color:var(--text)">名称
                    <input id="presetSaveName" type="text" placeholder="给这个预设起个名字" maxlength="60" class="preset-input" style="margin-top:4px">
                </label>
                <label id="presetSaveCategoryWrap" style="font-size:13px;color:var(--text)">分类
                    <select id="presetSaveCategory" class="preset-input" style="margin-top:4px">
                        <option value="custom">自定义</option>
                        <option value="standing">站姿</option>
                        <option value="sitting">坐姿</option>
                        <option value="lying">躺姿</option>
                        <option value="action">动作</option>
                        <option value="expression">表情</option>
                    </select>
                </label>
                <label class="toggle-row">
                    <span style="font-size:13px;color:var(--text)">加入收藏</span>
                    <input type="checkbox" id="presetSaveFavorite">
                </label>
            </div>
            <div class="modal-footer">
                <button class="ghost-button" id="cancelPresetSaveBtn">取消</button>
                <button class="primary-button" id="confirmPresetSaveBtn">保存</button>
            </div>
        </section>
    </div>

    <!-- Queue / progress -->
    <div class="queue-tray hidden" id="queueTray">
        <div class="queue-header">
            <span class="queue-title">生图队列</span>
            <button class="icon-button small ghost" id="queueAbortBtn" title="取消队列">⏹</button>
            <button class="icon-button small ghost" id="queueClearBtn" title="清除已完成">×</button>
        </div>
        <div class="queue-list" id="queueList"></div>
    </div>

    <!-- 工程队列 modal -->
    <div class="modal-backdrop hidden" id="projectQueueModal" role="dialog" aria-modal="true">
        <section class="settings-modal" style="max-width:680px">
            <button class="modal-close" id="projectQueueCancelBtn" title="关闭">×</button>
            <h2>🧪 工程队列</h2>
            <div class="modal-body" style="padding:14px 16px;display:flex;flex-direction:column;gap:12px;max-height:70vh;overflow:auto">
                <p style="margin:0;font-size:12px;color:var(--text-secondary);line-height:1.5">
                    指定 <strong>多个姿势预设 × 不同张数</strong>，按队列跑。共享当前的主提示词 / 负面 / 角色 / 模型参数，<strong>每张用对应的姿势预设</strong>，每张随机 seed。
                    <br><span style="color:var(--text-muted)">例：微笑 × 4 + 大笑 × 8 = 12 张</span>
                </p>
                <div>
                    <div style="display:grid;grid-template-columns:1fr 90px 60px 24px;gap:6px;padding:0 6px;font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px">
                        <span>姿势预设</span>
                        <span>张数</span>
                        <span title="每张间隔（秒）">间隔s</span>
                        <span></span>
                    </div>
                    <div id="projectQueueRows"></div>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px">
                        <button class="link-button" id="projectQueueAddBtn">+ 空行</button>
                        <button class="link-button" id="projectQueueQuickBtn" title="一键加：站/坐/蹲/躺/看/笑（每行 4 张）">⚡ 常用姿势</button>
                        <button class="link-button" id="projectQueuePresetBtn" title="从已有姿势预设里选">📚 从预设选</button>
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:12px;padding:10px;background:var(--bg-elevated-2);border-radius:var(--r);font-size:12px">
                    <span style="color:var(--text-secondary)">合计</span>
                    <strong style="font-size:18px;color:var(--accent);font-family:var(--font-mono)" id="projectQueueTotal">0</strong>
                    <span style="color:var(--text-muted)">张（按各自间隔依次生成，可中途停止）</span>
                </div>
            </div>
            <footer class="modal-footer" style="display:flex;justify-content:flex-end;gap:8px;padding:10px 16px;border-top:1px solid var(--border)">
                <button class="ghost-button" id="projectQueueCancelBtn2">取消</button>
                <button class="primary-button" id="projectQueueStartBtn" disabled>开始生成</button>
            </footer>
        </section>
    </div>

    <div class="toast-stack" id="toastStack"></div>

    <!-- Scripts -->
    <script type="module" src="assets/js/app.js?v=104"></script>
</body>
</html>
