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
    <link rel="stylesheet" href="assets/css/main.css?v=<?= filemtime(__DIR__ . '/assets/css/main.css') ?>">
    <link rel="stylesheet" href="assets/css/components.css?v=<?= filemtime(__DIR__ . '/assets/css/components.css') ?>">
    <link rel="stylesheet" href="assets/css/tag-picker.css?v=<?= filemtime(__DIR__ . '/assets/css/tag-picker.css') ?>">
    <link rel="stylesheet" href="assets/css/gallery.css?v=<?= filemtime(__DIR__ . '/assets/css/gallery.css') ?>">
    <link rel="stylesheet" href="assets/css/mask-editor.css?v=<?= filemtime(__DIR__ . '/assets/css/mask-editor.css') ?>">
    <link rel="stylesheet" href="assets/css/themes.css?v=<?= filemtime(__DIR__ . '/assets/css/themes.css') ?>">
    <script>
        // Boot-time data for the SPA, no extra fetch needed
        window.__NAI_BOOT__ = {
            defaultSettings: <?= json_encode($defaultSettings, JSON_UNESCAPED_UNICODE) ?>,
            models: <?= json_encode($models, JSON_UNESCAPED_UNICODE) ?>,
            samplers: <?= json_encode($samplers, JSON_UNESCAPED_UNICODE) ?>,
            apiKeyPresent: <?= $apiKeyPresent ? 'true' : 'false' ?>,
            ucPresets: <?= json_encode(config('uc_presets'), JSON_UNESCAPED_UNICODE) ?>,
            csrfToken: <?= json_encode(session_id() ?: '') ?>,
            // 版本号：从仓库根的 VERSION 文件读（避免硬编码、tag 后忘了改）
            version: <?= json_encode(trim(@file_get_contents(__DIR__ . '/../VERSION') ?: '1.0.0')) ?>,
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
            <button class="ghost-button" id="aiComposeBtn" title="AI 写提示词（DeepSeek V4 Pro / Flash 互动式）">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/></svg>
                <span>AI 写词</span>
            </button>
            <button class="ghost-button" id="tagPickerBtn" title="打开标签选择器">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.59 13.41 13.42 20.58a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><path d="M7 7h.01"/></svg>
                <span>标签库</span>
            </button>
            <button class="ghost-button" id="openDecomposeBtn" title="提示词拆解（按 12 类自动分类）">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h13M3 12h13M3 18h13"/><path d="M19 6v0M19 12v0M19 18v0"/><circle cx="19" cy="6" r="1.2" fill="currentColor"/><circle cx="19" cy="12" r="1.2" fill="currentColor"/><circle cx="19" cy="18" r="1.2" fill="currentColor"/></svg>
                <span>拆解</span>
            </button>
            <button class="ghost-button" id="openArtistLibBtn" title="画师库（NOOB/NAI 双格式 + 画师串预设）">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/><path d="M16 11l2 2 4-4"/></svg>
                <span>画师库</span>
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
                    <span class="preset-kind-badge" data-kind="prompt" title="画师串预设（多画师组合模板）">🎨 画师串</span>
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
                        <div class="preset-select" id="characterPresetSelect" title="选择角色预设载入"></div>
                        <button class="icon-button small ghost" id="characterPresetManageBtn" title="管理预设（收藏/删除/重命名）">
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
                        <div class="preset-select" id="posePresetSelect" title="选择姿势预设载入"></div>
                        <button class="icon-button small ghost" id="posePresetManageBtn" title="管理预设（收藏/删除/重命名）">
                            <svg viewBox="0 0 24 24" width="14" height="14"><path d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Zm8.5 4a6.8 6.8 0 0 0-.1-1l2-1.5-2-3.4-2.4 1a8.8 8.8 0 0 0-1.7-1L16 3.5h-4l-.4 2.6a8.8 8.8 0 0 0-1.7 1l-2.4-1-2 3.4 2 1.5a6.8 6.8 0 0 0 0 2l-2 1.5 2 3.4 2.4-1a8.8 8.8 0 0 0 1.7 1l.4 2.6h4l.4-2.6a8.8 8.8 0 0 0 1.7-1l2.4 1 2-3.4-2-1.5c.1-.3.1-.6.1-1Z"/></svg>
                        </button>
                    </div>
                    <div class="pose-preset-panel hidden" id="posePresetPanel">
                        <p class="preset-panel-hint">★ = 收藏 · 收藏的预设排在前面</p>
                        <div id="posePresetList"></div>
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

    <!-- Tag picker overlay (在线 Danbooru 搜索，中文自动翻译) -->
    <div class="tag-picker hidden" id="tagPicker">
        <div class="tag-picker-header">
            <div class="tag-picker-title">
                <span class="tag-picker-title-icon">🏷</span>
                <span>标签超市</span>
                <span class="tag-picker-source-badge">🌐 在线 Danbooru</span>
            </div>
            <!-- Tab 切换：搜索 / 本地缓存 -->
            <div class="tag-picker-tabs" role="tablist">
                <button class="tag-picker-tab active" data-tab="search" role="tab" title="在线搜索 Danbooru 标签">
                    🔍 在线搜索
                </button>
                <button class="tag-picker-tab" data-tab="local" role="tab" title="浏览本地已缓存标签（含预览图）">
                    💾 本地缓存 <span class="tag-picker-tab-count" id="tagPickerLocalCount">0</span>
                </button>
                <button class="tag-picker-tab-btn" id="tagPickerBatchTranslateBtn" type="button" title="批量翻译所有未翻译的本地 tag（串行调 MyMemory，每 50ms 一次）" style="margin-left:auto;">
                    🌐 批量翻译
                </button>
            </div>
            <div class="tag-picker-search-wrap">
                <div class="tag-picker-search-row">
                    <svg class="tag-picker-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.35-4.35"/></svg>
                    <input type="search" id="tagPickerSearch" placeholder="输入中文 / 英文 tag — 中→英自动翻译 · 点击标签加入购物车" autocomplete="off">
                    <button class="icon-button ghost" id="tagPickerCloseBtn" title="关闭 (Esc)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>
                    </button>
                </div>
                <!-- 本地下拉：输入时立即显示本地缓存匹配（cn_name + name） -->
                <div class="tag-picker-dropdown hidden" id="tagPickerDropdown"></div>
            </div>
            <div class="tag-picker-translate-bar hidden" id="tagPickerTranslateBar">
                <span class="tpt-icon">🔄</span>
                <span class="tpt-text"></span>
            </div>
        </div>
        <div class="tag-picker-main">
            <!-- 左侧：分类侧栏（根据 activeTab 切换内容：搜索 / 本地缓存） -->
            <aside class="tag-picker-sidebar" id="tagPickerSidebar">
                <!-- ===== 在线搜索 tab 用 ===== -->
                <div class="tag-picker-sidebar-group" data-sidebar-for="search">
                    <button class="tag-picker-cat-btn active" data-cat="all">
                        <span class="cat-icon">🔍</span>
                        <span class="cat-name">全部结果</span>
                        <span class="cat-count" id="tagPickerCatCountAll">0</span>
                    </button>
                    <button class="tag-picker-cat-btn" data-cat="local">
                        <span class="cat-icon">📦</span>
                        <span class="cat-name">本地缓存</span>
                        <span class="cat-count" id="tagPickerCatCountLocal">0</span>
                    </button>
                    <button class="tag-picker-cat-btn" data-cat="online">
                        <span class="cat-icon">🌐</span>
                        <span class="cat-name">Danbooru</span>
                        <span class="cat-count" id="tagPickerCatCountOnline">0</span>
                    </button>
                    <div class="tag-picker-sidebar-divider"></div>
                    <div class="tag-picker-sidebar-label">类别</div>
                    <button class="tag-picker-cat-btn" data-cat="0">
                        <span class="cat-icon">🏷</span>
                        <span class="cat-name">通用</span>
                        <span class="cat-count" id="tagPickerCatCountGeneral">0</span>
                    </button>
                    <button class="tag-picker-cat-btn" data-cat="1">
                        <span class="cat-icon">🎨</span>
                        <span class="cat-name">画师</span>
                        <span class="cat-count" id="tagPickerCatCountArtist">0</span>
                    </button>
                    <button class="tag-picker-cat-btn" data-cat="4">
                        <span class="cat-icon">👤</span>
                        <span class="cat-name">角色</span>
                        <span class="cat-count" id="tagPickerCatCountChar">0</span>
                    </button>
                    <button class="tag-picker-cat-btn" data-cat="3">
                        <span class="cat-icon">©</span>
                        <span class="cat-name">版权</span>
                        <span class="cat-count" id="tagPickerCatCountCopy">0</span>
                    </button>
                    <button class="tag-picker-cat-btn" data-cat="5">
                        <span class="cat-icon">⚙</span>
                        <span class="cat-name">元</span>
                        <span class="cat-count" id="tagPickerCatCountMeta">0</span>
                    </button>
                </div>

                <!-- ===== 本地缓存 tab 用 ===== -->
                <div class="tag-picker-sidebar-group hidden" data-sidebar-for="local">
                    <button class="tag-picker-cat-btn active" data-local-cat="all">
                        <span class="cat-icon">💾</span>
                        <span class="cat-name">全部本地</span>
                        <span class="cat-count" id="tagPickerLocalCatCountAll">0</span>
                    </button>
                    <div class="tag-picker-sidebar-divider"></div>
                    <div class="tag-picker-sidebar-label">预览图</div>
                    <button class="tag-picker-cat-btn" data-local-cat="with-image">
                        <span class="cat-icon">✅</span>
                        <span class="cat-name">已有图</span>
                        <span class="cat-count" id="tagPickerLocalCatCountWith">0</span>
                    </button>
                    <button class="tag-picker-cat-btn" data-local-cat="no-image">
                        <span class="cat-icon">❌</span>
                        <span class="cat-name">缺图</span>
                        <span class="cat-count" id="tagPickerLocalCatCountWithout">0</span>
                    </button>
                    <div class="tag-picker-sidebar-divider"></div>
                    <div class="tag-picker-sidebar-label">翻译</div>
                    <button class="tag-picker-cat-btn" data-local-cat="translated">
                        <span class="cat-icon">✅</span>
                        <span class="cat-name">已翻译</span>
                        <span class="cat-count" id="tagPickerLocalCatCountTranslated">0</span>
                    </button>
                    <button class="tag-picker-cat-btn" data-local-cat="untranslated">
                        <span class="cat-icon">🔤</span>
                        <span class="cat-name">未翻译</span>
                        <span class="cat-count" id="tagPickerLocalCatCountUntranslated">0</span>
                    </button>
                    <div class="tag-picker-sidebar-divider"></div>
                    <div class="tag-picker-sidebar-label">类别</div>
                    <button class="tag-picker-cat-btn" data-local-cat="cat-29">
                        <span class="cat-icon">🏷</span>
                        <span class="cat-name">通用</span>
                        <span class="cat-count" id="tagPickerLocalCatCountGeneral">0</span>
                    </button>
                    <button class="tag-picker-cat-btn" data-local-cat="cat-30">
                        <span class="cat-icon">🎨</span>
                        <span class="cat-name">画师</span>
                        <span class="cat-count" id="tagPickerLocalCatCountArtist">0</span>
                    </button>
                    <button class="tag-picker-cat-btn" data-local-cat="cat-31">
                        <span class="cat-icon">©</span>
                        <span class="cat-name">版权</span>
                        <span class="cat-count" id="tagPickerLocalCatCountCopy">0</span>
                    </button>
                    <button class="tag-picker-cat-btn" data-local-cat="cat-32">
                        <span class="cat-icon">👤</span>
                        <span class="cat-name">角色</span>
                        <span class="cat-count" id="tagPickerLocalCatCountChar">0</span>
                    </button>
                    <button class="tag-picker-cat-btn" data-local-cat="cat-33">
                        <span class="cat-icon">⚙</span>
                        <span class="cat-name">元数据</span>
                        <span class="cat-count" id="tagPickerLocalCatCountMeta">0</span>
                    </button>
                    <div class="tag-picker-sidebar-divider"></div>
                    <div class="tag-picker-sidebar-label">排序</div>
                    <button class="tag-picker-cat-btn" data-local-sort="popular">
                        <span class="cat-icon">🔥</span>
                        <span class="cat-name">热门</span>
                    </button>
                    <button class="tag-picker-cat-btn" data-local-sort="recent">
                        <span class="cat-icon">🕐</span>
                        <span class="cat-name">最近抓图</span>
                    </button>
                    <button class="tag-picker-cat-btn" data-local-sort="name">
                        <span class="cat-icon">🔤</span>
                        <span class="cat-name">名字 A-Z</span>
                    </button>
                    <button class="tag-picker-cat-btn" data-local-sort="random">
                        <span class="cat-icon">🎲</span>
                        <span class="cat-name">随机</span>
                    </button>
                    <div class="tag-picker-sidebar-divider"></div>
                    <button class="tag-picker-cat-btn" id="tagPickerLocalRefreshBtn" title="刷新当前页">
                        <span class="cat-icon">🔄</span>
                        <span class="cat-name">刷新</span>
                    </button>
                </div>
            </aside>
            <!-- 中间：标签网格 -->
            <div class="tag-picker-center tag-picker-center-full">
                <div class="tag-picker-center-header">
                    <div class="tag-picker-center-title" id="tagPickerCenterTitle">在线搜索</div>
                    <div class="tag-picker-center-meta">
                        <span id="tagPickerCount">0</span> 标签
                        <span class="tag-picker-divider">·</span>
                        <span id="tagPickerTotal">0</span> 累计
                    </div>
                </div>
                <div class="tag-picker-body" id="tagPickerBody"></div>
            </div>
            <!-- 右侧：购物车 -->
            <aside class="tag-picker-cart" id="tagPickerCart">
                <div class="tag-picker-cart-header">
                    <div class="tag-picker-cart-title">
                        🛒 购物车
                        <span class="tag-picker-cart-badge" id="tagPickerCartBadge">0</span>
                    </div>
                    <button class="tag-picker-cart-clear ghost-button small" id="tagPickerCartClearBtn" title="清空购物车">🗑 清空</button>
                </div>
                <div class="tag-picker-cart-list" id="tagPickerCartList">
                    <div class="tag-picker-cart-empty">
                        <div class="empty-icon">🛒</div>
                        <div>还没添加标签</div>
                        <div class="empty-hint">点击中间任意标签卡加入购物车</div>
                    </div>
                </div>
                <div class="tag-picker-cart-footer">
                    <button class="primary-button" id="tagPickerCheckoutBtn" title="拼接所有已选 tag（逗号分隔）复制到剪贴板">
                        📋 结算复制（<span id="tagPickerCartCount">0</span>）
                    </button>
                </div>
            </aside>
        </div>
        <div class="tag-picker-footer">
            <div class="tag-picker-footer-info">
                <span>提示：点击标签卡 = <strong>加入购物车</strong> · 点击购物车 ✕ = 移除 · 点击 🛒 结算 = 复制到剪贴板（逗号分隔） · <kbd>Esc</kbd> 关闭</span>
            </div>
            <div class="tag-picker-footer-actions">
                <span class="tag-picker-footer-shortcut" id="tagPickerFooterShortcuts">
                    💡 已选 <strong id="tagPickerFooterCount">0</strong> 个
                </span>
            </div>
        </div>
    </div>

    <!-- AI 写提示词 弹窗 (DeepSeek V4 Pro / Flash) -->
    <div class="modal-backdrop hidden" id="aiComposeModal">
        <section class="ai-compose-modal" role="dialog" aria-modal="true" aria-labelledby="aiComposeTitle">
            <!-- 顶部彩色边 -->
            <div class="acm-accent-bar"></div>
            <button class="modal-close" id="closeAiComposeBtn" title="关闭 (Esc)">×</button>
            <header class="acm-header">
                <div class="acm-header-left">
                    <h2 id="aiComposeTitle">🧠 AI 写提示词 <span class="acm-version">V4 Pro</span></h2>
                    <p class="acm-subtitle">DeepSeek V4 Pro / Flash 互动式 NAI 提示词工程 · 多轮对话 · 一键应用</p>
                </div>
                <div class="acm-header-right">
                    <span class="acm-status" id="acmStatus">未连接</span>
                    <button class="ghost-button small" id="acmClearBtn" title="清空对话">🗑 清空</button>
                </div>
            </header>

            <main class="acm-main">
                <div class="acm-model-bar">
                    <span class="acm-model-label-text">目标 NAI 模型：</span>
                    <div class="acm-model-chips" id="acmModelChips">
                        <!-- chips 由 JS 渲染 -->
                    </div>
                </div>
                <div class="acm-messages" id="acmMessages">
                    <div class="acm-welcome">
                        <div class="acm-welcome-icon">✨</div>
                        <h3>开始对话</h3>
                        <p>描述你想要的画面，AI 会按目标模型生成优化提示词。你可以：</p>
                        <ul>
                            <li>🖊 描述场景 → 拿完整 NAI prompt</li>
                            <li>🔄 "加个樱花背景" → 增量调整</li>
                            <li>💡 "为什么用 ciloranko" → 解释建议</li>
                            <li>📋 把生成的 prompt 一键写入主输入框</li>
                        </ul>
                        <p class="acm-welcome-hint">提示：可到「设置 → AI 顾问」配 API key</p>
                    </div>
                </div>
            </main>

            <footer class="acm-footer">
                <div class="acm-input-row">
                    <textarea id="acmInput" rows="2" placeholder="输入你的需求（Enter 发送 · Shift+Enter 换行）"></textarea>
                    <button class="primary-button" id="acmSendBtn">发送 →</button>
                </div>
            </footer>
        </section>
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

                <h3 style="font-size:13px;color:var(--accent);margin:0 0 8px">🧠 AI 顾问（多 provider 通用）</h3>
                <p style="font-size:11px;color:var(--text-secondary);margin-bottom:8px">
                    支持 DeepSeek、OpenAI、硅基流动（国内免费）、OpenRouter、Ollama（本地）、自定义（任何 OpenAI 兼容服务）。
                    启用后，拆解器可点"🧠 AI 深度分析"，"AI 写提示词"也会用同 provider。
                    <br>🔥 <strong>免费方案</strong>：硅基流动（Qwen2.5/GLM4/Llama3.1）+ OpenRouter（带 <code>:free</code> 标识的模型）+ Ollama 本地。
                </p>
                <label class="toggle-row">
                    <span>启用 AI 顾问</span>
                    <input type="checkbox" id="settingsAiAdvisor">
                </label>
                <label>Provider
                    <select id="settingsAiProvider" style="width:100%;padding:6px 8px;background:var(--bg);color:var(--text);border:1px solid var(--border);border-radius:var(--r-sm)">
                        <option value="deepseek">DeepSeek（官方）</option>
                        <option value="openai">OpenAI（官方）</option>
                        <option value="siliconflow">硅基流动（国内免费）</option>
                        <option value="openrouter">OpenRouter（聚合）</option>
                        <option value="ollama">Ollama（本地）</option>
                        <option value="custom">自定义（OpenAI 兼容）</option>
                    </select>
                </label>
                <label>Base URL
                    <input id="settingsAiBaseUrl" type="text" placeholder="https://api.deepseek.com/v1" autocomplete="off">
                </label>
                <div style="display:flex;gap:8px;align-items:flex-end">
                    <label style="flex:1">
                        Model
                        <input id="settingsAiModel" type="text" placeholder="deepseek-chat" autocomplete="off" list="settingsAiModelList">
                    </label>
                    <datalist id="settingsAiModelList"></datalist>
                    <label id="settingsAiReasoningWrap" style="display:none">
                        推理等级
                        <select id="settingsAiReasoning" style="padding:6px 8px;background:var(--bg);color:var(--text);border:1px solid var(--border);border-radius:var(--r-sm)">
                            <option value="">默认</option>
                            <option value="low">低（快）</option>
                            <option value="medium">中</option>
                            <option value="high">高（慢但细）</option>
                        </select>
                    </label>
                </div>
                <label>API Key <span id="settingsAiKeyHint" style="font-size:10px;color:var(--text-muted);font-weight:normal"></span>
                    <input id="settingsAiKey" type="password" placeholder="sk-..." autocomplete="off">
                </label>
                <p id="settingsAiPresetNote" style="font-size:11px;color:var(--text-secondary);background:var(--bg-elevated-2);padding:8px 10px;border-radius:6px;margin:8px 0"></p>
                <div style="display:flex;align-items:center;gap:8px">
                    <button class="ghost-button small" id="testAiBtn">测试连接</button>
                    <span id="aiTestStatus" style="font-size:11px;color:var(--text-muted)"></span>
                </div>

                <hr style="border:none;border-top:1px solid var(--border);margin:16px 0">

                <h3 style="font-size:13px;color:var(--warning,#f59e0b);margin:0 0 8px">⚠️ 非官方 Google 翻译 fallback</h3>
                <p style="font-size:11px;color:var(--text-secondary);margin-bottom:8px">
                    启用后，MyMemory 限流时会自动 fallback 到 Google 翻译的网页端点（<code>translate.googleapis.com/translate_a/single</code>）。
                    <strong style="color:var(--warning,#f59e0b)">风险</strong>：
                    Google 随时可能改接口、限速或封 IP；你的提示词会经 Google 服务器。
                    标签翻译不涉及敏感内容，<strong>风险可控但不稳定</strong>。
                </p>
                <label class="toggle-row">
                    <span>启用 Google 非官方 fallback</span>
                    <input type="checkbox" id="settingsAggressiveFallback">
                </label>
                <div class="decompose-pane-meta" id="aggressiveFallbackStatus" style="margin-top:4px"></div>

                <hr style="border:none;border-top:1px solid var(--border);margin:16px 0">

                <h3 style="font-size:13px;color:var(--accent);margin:0 0 8px">本地翻译（OPUS-MT / LibreTranslate）</h3>
                <p style="font-size:11px;color:var(--text-secondary);margin-bottom:8px">
                    跑 LibreTranslate / OPUS-MT 本地服务后，填 URL 启用。完全离线，无每日翻译限额。
                </p>
                <label>翻译源
                    <select id="settingsTranslateSource">
                        <option value="fallback">推荐：在线优先 + 本地兜底</option>
                        <option value="off">只使用在线（DeepSeek / MyMemory）</option>
                        <option value="local">只使用本地（完全离线）</option>
                    </select>
                </label>
                <label>服务地址
                    <input id="settingsLocalTranslateUrl" type="text" placeholder="例如 http://127.0.0.1:5555">
                </label>
                <div style="display:flex;align-items:center;gap:8px">
                    <button class="ghost-button small" id="testLocalTranslateBtn">测试连接</button>
                    <span id="localTranslateStatus" style="font-size:11px;color:var(--text-muted)"></span>
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
                    NAI Studio 是 PHP 内置服务器 + SQLite 单文件，<b>无需 XAMPP / MySQL</b>。停止后网站不可访问。
                </p>

                <div class="status-panel" id="statusOverall">
                    <span class="status-dot dot"></span>
                    <span class="label">检测中…</span>
                </div>

                <div class="backend-status-row">
                    <div class="backend-status-card" id="statusServer">
                        <span class="status-dot dot"></span>
                        <span class="label">PHP 内置服务器 (8080)</span>
                    </div>
                    <div class="backend-status-card" id="statusDb">
                        <span class="status-dot dot"></span>
                        <span class="label">SQLite 数据库</span>
                    </div>
                </div>

                <div class="backend-actions">
                    <button class="primary-button" id="actionBackendStart" style="flex:1;padding:14px"
                            title="如服务未启动，请双击 tools\start.bat（或在此页查看路径）">
                        <svg viewBox="0 0 24 24" width="18" height="18" style="vertical-align:-3px;margin-right:6px"><path d="M8 5v14l11-7z" fill="currentColor"/></svg>
                        一键启动
                    </button>
                    <button class="ghost-button" id="actionBackendStop" style="flex:1;padding:14px">
                        <svg viewBox="0 0 24 24" width="18" height="18" style="vertical-align:-3px;margin-right:6px"><rect x="6" y="6" width="12" height="12" fill="currentColor"/></svg>
                        一键停止
                    </button>
                </div>
                <div style="margin-top:10px;padding:10px 12px;background:rgba(168,85,247,0.08);border:1px solid rgba(168,85,247,0.2);border-radius:6px;font-size:12px;color:var(--text-secondary);line-height:1.6">
                    <b>💡 启动服务</b>：本页面无法直接启动服务（因为服务要起才能连到这里）。<br>
                    请双击 <code style="background:rgba(0,0,0,0.3);padding:1px 5px;border-radius:3px">tools\start.bat</code> 启动；停止可点上方按钮或双击 <code style="background:rgba(0,0,0,0.3);padding:1px 5px;border-radius:3px">tools\stop.bat</code>。<br>
                    <b>路径</b>：<code id="statusStartBatPath" style="background:rgba(0,0,0,0.3);padding:1px 5px;border-radius:3px">—</code>
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

                <!-- 标签示例图补全（仿 tags.novelai.dev：构建时预下载） -->
                <div class="action-section" style="margin-top:16px;border-top:1px solid var(--border);padding-top:12px">
                    <h4 style="margin:0 0 8px;font-size:13px">🖼 标签示例图补全</h4>
                    <p style="margin:0 0 8px;font-size:11px;color:var(--text-muted);line-height:1.5">
                        从 Danbooru 预下载热门标签示例图到本地，标签超市的卡片就能显示预览图。<br>
                        <span id="fetchImgCoverage">未查询</span> · 抓图后无需 JS 状态机，纯静态 <code>&lt;img&gt;</code> 加载。
                    </p>
                    <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-bottom:8px">
                        <label style="font-size:11px;display:flex;align-items:center;gap:4px">
                            抓取数量：
                            <input type="number" id="fetchImgLimit" value="500" min="10" max="10000" step="100" style="width:80px;padding:4px 6px;background:var(--bg);border:1px solid var(--border);border-radius:4px;color:var(--text);font-size:11px">
                            (按 post_count 倒序抓热门)
                        </label>
                    </div>
                    <div style="display:flex;gap:6px">
                        <button class="primary-button" id="actionFetchImg" style="flex:1;padding:10px">🚀 开始抓图</button>
                        <button class="ghost-button" id="actionStopFetchImg" style="padding:10px" disabled>停止</button>
                    </div>
                    <div id="fetchImgProgress" style="margin-top:10px;font-size:11px;color:var(--text-secondary);min-height:18px"></div>
                    <div id="fetchImgBar" style="margin-top:6px;height:6px;background:var(--bg-elevated-2);border-radius:3px;overflow:hidden">
                        <div id="fetchImgBarFill" style="height:100%;background:var(--accent);width:0%;transition:width .3s"></div>
                    </div>
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

    <!-- Decompose modal (v3: 全屏工作台) -->
    <div class="modal-backdrop hidden" id="decomposeModal">
        <section class="decompose-modal" role="dialog" aria-modal="true" aria-labelledby="decomposeTitle">
            <!-- 顶部彩色边 + 标题栏 -->
            <header class="dm-header">
                <div class="dm-header-left">
                    <div class="dm-title">
                        <span class="dm-title-icon">🪄</span>
                        <h2 id="decomposeTitle">提示词拆解工作台</h2>
                    </div>
                    <div class="dm-subtitle">自动按 12 大类拆分 · 权重语法 · 一键补翻译 · AI 深度分析</div>
                </div>
                <div class="dm-header-right">
                    <span class="dm-kbd-hint">
                        <kbd>Ctrl</kbd>+<kbd>Enter</kbd> 拆解
                    </span>
                    <button class="modal-close" id="closeDecomposeBtn" title="关闭（Esc）">×</button>
                </div>
            </header>

            <!-- 主工作区：左输入 / 右结果 -->
            <div class="decompose-grid">
                <aside class="decompose-input-pane">
                    <div class="decompose-pane-header">
                        <strong>📝 原始提示词</strong>
                        <span class="decompose-pane-meta" id="decomposeInputMeta">0 字符</span>
                    </div>
                    <textarea id="decomposeInput" placeholder="把一串 NAI 提示词粘贴到这里...&#10;&#10;例如：&#10;masterpiece, 1girl, hatsune_miku, long_hair, twintails, blue_eyes, smile, standing, school_uniform, cherry_blossoms, {artist:ciloranko}"></textarea>
                    <div class="decompose-input-foot">
                        <div class="decompose-input-foot-left">
                            <button class="ghost-button small" id="decomposeSampleBtn" type="button" title="载入示例 prompt">📋 示例</button>
                            <button class="ghost-button small" id="decomposeClearBtn" type="button" title="清空">🗑 清空</button>
                        </div>
                        <div class="decompose-input-foot-right">
                            <label class="decompose-toggle" title="对未翻译的 tag 调一次翻译">
                                <input type="checkbox" id="decomposeAutoTranslate" checked>
                                <span>自动补翻译</span>
                            </label>
                            <button class="primary-button" id="decomposeRunBtn" type="button" title="开始拆解 (Ctrl+Enter)">⚡ 拆解</button>
                        </div>
                    </div>
                </aside>

                <main class="decompose-result-pane" id="decomposeResultPane">
                    <!-- 空状态：填充内容，不让右半边空着 -->
                    <div class="decompose-empty" id="decomposeEmpty">
                        <div class="deco-welcome">
                            <div class="deco-welcome-icon">✨</div>
                            <h3>等待拆解</h3>
                            <p>把 NAI 提示词粘贴到左侧，点"⚡ 拆解"开始</p>
                        </div>
                        <div class="deco-tips">
                            <div class="deco-tip">
                                <span class="deco-tip-icon">🎨</span>
                                <div>
                                    <strong>画师串</strong>
                                    <p>写 <code>{artist:ciloranko}</code> 会被识别为画师，自动给画像+建议</p>
                                </div>
                            </div>
                            <div class="deco-tip">
                                <span class="deco-tip-icon">⚖️</span>
                                <div>
                                    <strong>权重语法</strong>
                                    <p>支持 <code>{tag:1.2}</code> 加大权重 / <code>[tag]</code> 减弱 / <code>(tag)</code> 增强</p>
                                </div>
                            </div>
                            <div class="deco-tip">
                                <span class="deco-tip-icon">🌐</span>
                                <div>
                                    <strong>未识别 tag</strong>
                                    <p>勾选"自动补翻译"会调 MyMemory → DeepSeek 多源兜底</p>
                                </div>
                            </div>
                            <div class="deco-tip">
                                <span class="deco-tip-icon">🧠</span>
                                <div>
                                    <strong>AI 深度分析</strong>
                                    <p>拆解后切到"AI"标签，让 DeepSeek 给语义冲突/冗余/优化建议</p>
                                </div>
                            </div>
                        </div>
                        <div class="deco-recent hidden" id="decoRecentBlock">
                            <div class="deco-recent-title">📚 最近拆解</div>
                            <div class="deco-recent-list" id="decoRecentList"></div>
                        </div>
                    </div>
                    <div class="decompose-loading hidden" id="decomposeLoading">
                        <div class="decompose-spinner"></div>
                        <div>正在拆解…</div>
                    </div>
                    <div class="decompose-result hidden" id="decomposeResult">
                        <!-- 顶部大统计卡片 -->
                        <div class="decompose-stats-cards" id="decomposeStats"></div>

                        <!-- Tab 切换 -->
                        <div class="decompose-tabbar">
                            <button class="decompose-tab active" data-dtab="pairs">
                                <span class="dtab-icon">📋</span>
                                <span class="dtab-label">对照表</span>
                                <span class="dtab-badge" id="dtabBadgePairs">0</span>
                            </button>
                            <button class="decompose-tab" data-dtab="artists">
                                <span class="dtab-icon">🎨</span>
                                <span class="dtab-label">画师</span>
                                <span class="dtab-badge" id="dtabBadgeArtists">0</span>
                            </button>
                            <button class="decompose-tab" data-dtab="ai">
                                <span class="dtab-icon">🧠</span>
                                <span class="dtab-label">AI</span>
                                <span class="dtab-badge" id="dtabBadgeAi">—</span>
                            </button>
                            <div class="decompose-tab-actions">
                                <button class="primary-button small" id="decomposeAiBtn" type="button" title="用 DeepSeek 给整个 prompt 做深度分析">🧠 AI 深度分析</button>
                            </div>
                        </div>

                        <!-- Tab: 对照表 -->
                        <div class="decompose-tabpane active" data-dtabpane="pairs">
                            <div class="decompose-pane-tools">
                                <div class="decompose-pane-tools-left">
                                    <label class="decompose-toggle" title="按 12 大类分组显示">
                                        <input type="checkbox" id="decomposeGroupByCat">
                                        <span>按分类分组</span>
                                    </label>
                                    <span class="decompose-pane-meta" id="decomposeCountMeta"></span>
                                </div>
                                <div class="decompose-pane-tools-right">
                                    <button class="ghost-button small" id="decomposeFillTranslateBtn" type="button" title="翻译所有未翻译的中文栏">🌐 补翻译</button>
                                    <button class="ghost-button small" id="decomposeAddRowBtn" type="button" title="添加一行">＋ 加一行</button>
                                </div>
                            </div>
                            <div class="decompose-pairs">
                                <div class="decompose-pairs-header">
                                    <span>英文</span>
                                    <span>中文</span>
                                    <span>分类</span>
                                    <span></span>
                                </div>
                                <div class="decompose-pairs-body" id="decomposePairsBody"></div>
                            </div>
                            <div class="decompose-untranslated hidden" id="decomposeUntranslatedBlock">
                                <p id="decomposeUntranslatedHint">还有 <strong id="decomposeUntranslatedCount">0</strong> 个 tag 未翻译。</p>
                            </div>
                        </div>

                        <!-- Tab: 画师 -->
                        <div class="decompose-tabpane" data-dtabpane="artists">
                            <div class="decompose-pane-empty" id="decomposeArtistEmpty">
                                <div class="dpe-icon">🎨</div>
                                <div>未检测到已知画师</div>
                                <div class="dpe-hint">需要 prompt 里出现 <code>artist:xxx</code> 或在画像库里的画师名</div>
                            </div>
                            <div class="decompose-artist-pane hidden" id="decomposeArtistContent"></div>
                        </div>

                        <!-- Tab: AI -->
                        <div class="decompose-tabpane" data-dtabpane="ai">
                            <div class="decompose-pane-empty" id="decomposeAiEmpty">
                                <div class="dpe-icon">🧠</div>
                                <div>点击右上角 "🧠 AI 深度分析" 按钮</div>
                                <div class="dpe-hint">让 DeepSeek 给整个 prompt 提结构化建议（语义冲突 / 权重 / 冗余 / 补充）</div>
                            </div>
                            <div class="decompose-ai-pane hidden" id="decomposeAiContent"></div>
                        </div>

                        <!-- 底部固定操作栏 -->
                        <div class="decompose-bottom-actions">
                            <span class="decompose-bottom-hint" id="decomposeBottomHint"></span>
                            <button class="ghost-button" id="decomposeCopyRebuildBtn" type="button">📄 复制英文</button>
                            <button class="ghost-button" id="decomposeCopyBothBtn" type="button">📄 复制双语</button>
                            <button class="primary-button" id="decomposeApplyRebuildBtn" type="button">→ 写入主提示词</button>
                        </div>
                    </div>
                </main>
            </div>
        </section>
    </div>

    <!-- Artist Library Modal -->
    <div class="modal-backdrop hidden" id="artistLibModal">
        <section class="artist-lib-modal" role="dialog" aria-modal="true" aria-labelledby="artistLibTitle">
            <button class="modal-close" id="closeArtistLibBtn" title="关闭">×</button>
            <h2 id="artistLibTitle">🎨 画师库</h2>
            <p class="decompose-hint">
                在线浏览 Danbooru 作者库（热门 + 模糊搜索 + 画风预览），点 ⭐ 收藏到本地。也可切到"我的"管理已收藏画师 + 画师串预设。
            </p>

            <div class="al-tabs">
                <button class="al-tab active" data-al-tab="artists">画师</button>
                <button class="al-tab" data-al-tab="presets">画师串预设</button>
            </div>

            <div class="al-toolbar">
                <div class="al-toolbar-left">
                    <input type="search" id="alSearchInput" class="al-search" placeholder="搜索画师（NAI 名 / 英文名）" />
                </div>
                <div class="al-toolbar-right">
                    <span class="al-source-toggle">
                        <button class="active" data-al-source="danbooru">🌐 Danbooru 在线</button>
                        <button data-al-source="local">📚 我的画师</button>
                    </span>
                    <button class="ghost-button small" id="alRefreshBtn" title="刷新">🔄</button>
                    <button class="primary-button small" id="alAddBtn" title="手动添加新画师">+ 添加画师</button>
                </div>
            </div>

            <!-- 画师 tab -->
            <div class="al-content" id="alArtistsTab">
                <div class="al-artists">
                    <div class="al-artists-list" id="alArtistsList"></div>
                </div>
            </div>

            <!-- 画师串预设 tab -->
            <div class="al-content hidden" id="alPresetsTab">
                <div class="al-presets-list" id="alPresetsList"></div>
                <div class="al-presets-actions">
                    <button class="primary-button small" id="alNewPresetBtn">+ 新建画师串</button>
                </div>
            </div>
        </section>
    </div>

    <!-- Add/Edit Artist Modal -->
    <div class="modal-backdrop hidden" id="artistFormModal">
        <section class="artist-form-modal" role="dialog" aria-modal="true" aria-labelledby="artistFormTitle">
            <button class="modal-close" id="closeArtistFormBtn" title="关闭">×</button>
            <h3 id="artistFormTitle">添加画师</h3>
            <form id="artistForm" class="artist-form">
                <div class="af-row">
                    <label>NAI 格式 <span class="af-hint">裸名，无前缀</span>
                        <input type="text" id="afNameNai" placeholder="如 ciloranko" autocomplete="off">
                    </label>
                    <label>NOOB 格式 <span class="af-hint">自动补全，可手改</span>
                        <input type="text" id="afNameNoob" placeholder="如 artist:ciloranko" autocomplete="off">
                    </label>
                </div>
                <div class="af-row">
                    <label>中文名
                        <input type="text" id="afNameCn" placeholder="可选，如 西洛兰科" autocomplete="off">
                    </label>
                    <label>风格
                        <select id="afStyle">
                            <option value="">(未分类)</option>
                            <option value="thick_anime">厚涂二次元</option>
                            <option value="soft_anime">软萌二次元</option>
                            <option value="realistic">写实派</option>
                            <option value="cinematic">电影感</option>
                            <option value="illustration">插画风</option>
                            <option value="dark">黑暗系</option>
                            <option value="classic">经典派</option>
                        </select>
                    </label>
                </div>
                <label>Danbooru 链接
                    <input type="url" id="afDanbooruLink" placeholder="自动生成：https://danbooru.donmai.us/posts?tags=artist%3Axxx" autocomplete="off">
                </label>
                <label>分类 <span class="af-hint">可多选（按住 Ctrl/Cmd）</span>
                    <select id="afCategories" multiple size="5" class="af-multiselect"></select>
                </label>
                <label>备注
                    <textarea id="afNotes" rows="2" placeholder="如：NAI 社区御用画师，厚涂质感极强"></textarea>
                </label>
                <div class="af-actions">
                    <button type="button" class="ghost-button small" id="afAutoBtn" title="根据已填字段自动补全">⚡ 自动补全</button>
                    <button type="button" class="ghost-button small" id="afFetchBtn" title="从 Danbooru 抓取作品数 + 预览图">🌐 抓取 Danbooru</button>
                    <div class="af-spacer"></div>
                    <button type="button" class="ghost-button" id="afCancelBtn">取消</button>
                    <button type="submit" class="primary-button" id="afSaveBtn">保存</button>
                </div>
            </form>
        </section>
    </div>

    <!-- Preset Form Modal -->
    <div class="modal-backdrop hidden" id="presetFormModal">
        <section class="artist-form-modal" role="dialog" aria-modal="true" aria-labelledby="presetFormTitle">
            <button class="modal-close" id="closePresetFormBtn" title="关闭">×</button>
            <h3 id="presetFormTitle">新建画师串</h3>
            <form id="presetForm" class="artist-form">
                <label>名称
                    <input type="text" id="pfName" placeholder="如 二次元厚涂三件套" required>
                </label>
                <label>描述
                    <input type="text" id="pfDesc" placeholder="可选，如 ciloranko + fuzichoco + huke">
                </label>
                <label>画师 <span class="af-hint">按住 Ctrl/Cmd 多选，NAI 格式按选中顺序拼接</span>
                    <select id="pfArtists" multiple size="8" class="af-multiselect"></select>
                </label>
                <label>NAI 文本预览
                    <textarea id="pfNaiPreview" rows="2" readonly></textarea>
                </label>
                <div class="af-actions">
                    <div class="af-spacer"></div>
                    <button type="button" class="ghost-button" id="pfCancelBtn">取消</button>
                    <button type="submit" class="primary-button" id="pfSaveBtn">保存</button>
                </div>
            </form>
        </section>
    </div>

    <!-- Preset save modal -->
    <div class="modal-backdrop hidden" id="presetSaveModal" role="dialog" aria-modal="true">
        <section class="settings-modal" style="max-width:440px">
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

    <!-- Import map: 给所有 ES module 文件加版本号，避免浏览器缓存旧版本 -->
    <script type="importmap">
    {
        "imports": {
            "./assets/js/app.js": "./assets/js/app.js?v=<?= filemtime(__DIR__ . '/assets/js/app.js') ?>",
            "./assets/js/state.js": "./assets/js/state.js?v=<?= filemtime(__DIR__ . '/assets/js/state.js') ?>",
            "./assets/js/storage.js": "./assets/js/storage.js?v=<?= filemtime(__DIR__ . '/assets/js/storage.js') ?>",
            "./assets/js/api.js": "./assets/js/api.js?v=<?= filemtime(__DIR__ . '/assets/js/api.js') ?>",
            "./assets/js/toast.js": "./assets/js/toast.js?v=<?= filemtime(__DIR__ . '/assets/js/toast.js') ?>",
            "./assets/js/tag-picker.js": "./assets/js/tag-picker.js?v=<?= filemtime(__DIR__ . '/assets/js/tag-picker.js') ?>",
            "./assets/js/actions.js": "./assets/js/actions.js?v=<?= filemtime(__DIR__ . '/assets/js/actions.js') ?>",
            "./assets/js/panel.js": "./assets/js/panel.js?v=<?= filemtime(__DIR__ . '/assets/js/panel.js') ?>",
            "./assets/js/prompt.js": "./assets/js/prompt.js?v=<?= filemtime(__DIR__ . '/assets/js/prompt.js') ?>",
            "./assets/js/ai-settings.js": "./assets/js/ai-settings.js?v=<?= filemtime(__DIR__ . '/assets/js/ai-settings.js') ?>",
            "./assets/js/ai-compose.js": "./assets/js/ai-compose.js?v=<?= filemtime(__DIR__ . '/assets/js/ai-compose.js') ?>",
            "./assets/js/characters.js": "./assets/js/characters.js?v=<?= filemtime(__DIR__ . '/assets/js/characters.js') ?>",
            "./assets/js/pose.js": "./assets/js/pose.js?v=<?= filemtime(__DIR__ . '/assets/js/pose.js') ?>",
            "./assets/js/preset-combobox.js": "./assets/js/preset-combobox.js?v=<?= filemtime(__DIR__ . '/assets/js/preset-combobox.js') ?>",
            "./assets/js/vibe.js": "./assets/js/vibe.js?v=<?= filemtime(__DIR__ . '/assets/js/vibe.js') ?>",
            "./assets/js/precise.js": "./assets/js/precise.js?v=<?= filemtime(__DIR__ . '/assets/js/precise.js') ?>",
            "./assets/js/base-image.js": "./assets/js/base-image.js?v=<?= filemtime(__DIR__ . '/assets/js/base-image.js') ?>",
            "./assets/js/mask-editor.js": "./assets/js/mask-editor.js?v=<?= filemtime(__DIR__ . '/assets/js/mask-editor.js') ?>",
            "./assets/js/gallery.js": "./assets/js/gallery.js?v=<?= filemtime(__DIR__ . '/assets/js/gallery.js') ?>",
            "./assets/js/import.js": "./assets/js/import.js?v=<?= filemtime(__DIR__ . '/assets/js/import.js') ?>",
            "./assets/js/director.js": "./assets/js/director.js?v=<?= filemtime(__DIR__ . '/assets/js/director.js') ?>",
            "./assets/js/settings.js": "./assets/js/settings.js?v=<?= filemtime(__DIR__ . '/assets/js/settings.js') ?>",
            "./assets/js/presets.js": "./assets/js/presets.js?v=<?= filemtime(__DIR__ . '/assets/js/presets.js') ?>",
            "./assets/js/queue.js": "./assets/js/queue.js?v=<?= filemtime(__DIR__ . '/assets/js/queue.js') ?>",
            "./assets/js/project-queue.js": "./assets/js/project-queue.js?v=<?= filemtime(__DIR__ . '/assets/js/project-queue.js') ?>",
            "./assets/js/keyboard.js": "./assets/js/keyboard.js?v=<?= filemtime(__DIR__ . '/assets/js/keyboard.js') ?>",
            "./assets/js/preset-modal.js": "./assets/js/preset-modal.js?v=<?= filemtime(__DIR__ . '/assets/js/preset-modal.js') ?>",
            "./assets/js/decomposer.js": "./assets/js/decomposer.js?v=<?= filemtime(__DIR__ . '/assets/js/decomposer.js') ?>",
            "./assets/js/artist_library.js": "./assets/js/artist_library.js?v=<?= filemtime(__DIR__ . '/assets/js/artist_library.js') ?>",
            "./assets/js/generate-payload.js": "./assets/js/generate-payload.js?v=<?= filemtime(__DIR__ . '/assets/js/generate-payload.js') ?>"
        }
    }
    </script>

    <!-- Scripts -->
    <script type="module" src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/assets/js/app.js') ?>"></script>
</body>
</html>
