feat(egui-ui): 仿 PHP v0.8 三栏布局 (左 280 + 中 + 右 280)

之前的设计 (Linear 全屏 + Ctrl+K) 跟原 PHP 网页体验差太多。
这次完全照搬 NAI Studio PHP v0.8 的 app-shell[v2] 布局,
改用 egui 重做, 用户切换到新 GUI 完全无缝。

## 布局对齐 PHP v0.8

完全照搬 PHP CSS:
- topbar 56px (ns-topbar-h)
- left 280px (ns-sidebar-w) - 4 tab + 大输入 + 模型参数 + 预设
- main 1fr - 当前 view (生图 / 画廊 / 标签 / 设置)
- history 280px (ns-history-w) - 历史画廊 2 列 2:3 缩略图
- statusbar 24px

## 设计 token 全部对齐

配色 (ns-bg / ns-text / ns-accent 系列):
- bg: #0a0c14 (v0.8 略紫黑)
- bg-soft: #11141f (面板)
- bg-elevated: #171b29 (卡片)
- accent: #7c5cff (v0.8 紫)
- accent-2: #22d3ee (青)
- text: #e6ebf5
- text-2: #a3acc2
- text-3: #6c768e
- line: rgba(255,255,255,0.06) 淡白边框
- accent-soft: rgba(124,92,255,0.14)
- success: #34d399
- danger: #fb7185

间距 (4 网格 ns-1..6):
- ns-1: 4 / ns-2: 8 / ns-3: 12 / ns-4: 16 / ns-5: 24 / ns-6: 32

## 新增文件

- src-tauri/src/ui/views/left_panel.rs    左栏 (4 tab + 大输入 + 模型参数 + 预设)
- src-tauri/src/ui/views/history_strip.rs 右栏 (头部 + 4 tab + 2 列 2:3 缩略图 + hover meta)

## 重写

- app.rs: 完全照搬 PHP grid 三栏布局
- theme.rs: 全部 token 用 ns-* v0.8, helper 函数 (line/accent_soft) 处理 const 限制
- home.rs (生图): 中央大预览 (2:3 比例) + 进度条 + FAB 风格生成按钮
- gallery.rs: 全屏网格
- tags.rs: 搜索 + 分类 chip + 标签云
- settings.rs: 双栏卡片 (NAI Key / AI 助手 / 代理 vs 数据 / 主题 / 关于)

## 视觉细节

- 4 tab 切换仿 history-tabs 风格 (active: ACCENT 文字 + ACCENT_SOFT 背景 + LINE_ACCENT 边框)
- 缩略图 hover 显示 seed + model 浮层 (BG 0.85 alpha)
- 卡片 BG_SOFT 背景 + LINE 1px 边框 (PHP 风格)
- 顶栏迷你生成按钮 (FAB 风格, accent 紫)
- 状态条 24px 极薄, 显示当前 view + 后端状态

## 兼容

- 后端 API 零修改 (30+ handlers 全保留)
- Ctrl+K 命令面板 仍可用
- 数字键 1-4 切换 tab 仍可用

Release EXE: 9.1 MB
