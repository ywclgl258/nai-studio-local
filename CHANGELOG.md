# Changelog

All notable changes to NAI Studio are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

> 中文版更新日志：[CHANGELOG.zh-CN.md](CHANGELOG.zh-CN.md)

---

## [Unreleased]

## [1.0.2] - 2026-06-29

### 🐛 Bug Fixes
- **三个预设类别视觉上搞混了**：主提示词/角色/姿势的 select 都叫"— 预设 —"，且选项格式一样
  - 修法：每个 preset row 加**颜色徽章**（📋 提示词=紫 / 👤 角色=蓝 / 🧍 姿势=青）+ **左边色条**对齐 tab 主题色
  - placeholder 明确区分：`— 提示词预设 —` / `— 角色预设 —` / `— 姿势预设 —`
  - 注意：三个 API/表/数据本来就**没混**（`/api/prompts.php` / `/api/character_presets.php` / `/api/pose_presets.php`），修的是**UI 可读性**

## [1.0.1] - 2026-06-29

### ✨ Features
- **主提示词框顶部加 inline 预设快捷栏**：下拉 + 保存按钮，1 步载入（不再要点齿轮→浮窗→预设 tab 三步）
- **预设弹窗用 `preset-modal.js` 替代丑的 `prompt()`**：支持自定义名称 + 收藏 toggle
- **收藏预设排前面**：下拉里 ★ 标记 + 排序优先
- **gitignore 加 `storage/screenshots/`**：本地截图不进版本库
- **CSS 版本 bump → v=103**

## [1.0.0] - 2026-06-28

### 🎯 Planned (from Roadmap)
- 小图无限模式（自动选 NAI unlimited 尺寸 + 间隔默认 0）
- 今日已生图统计
- CLI 后台模式（复制 `php generate.php --queue` 命令）
- 标签库增强：批量翻译 Top 500、标签收藏、补全搜索、修预览图 404
- 多用户 basic auth
- PWA 离线支持
- 批量导入 PNG 文件夹

---

## [1.0.0] - 2026-06-29

🎉 **首次发布** — 完整本地化 NovelAI 生图工作台。

### ✨ Features

#### 🎯 队列连续生图（核心优势）
- **普通队列**：N 张 + 间隔秒 + 自动重试（最多 3 轮 × 30s 退避）
- **工程队列**：多组姿势预设 × 不同张数，按队列跑（如"微笑×4 + 大笑×8 + 嘟嘴×2 = 14 张"）
- 队列共享主提示词 / 角色 / 模型参数，每张随机 seed
- 可中途 ⏹ 停止
- 失败自动重试 + Retry-After 退避（NAI 429）

#### 🪄 NAI 全模型支持
- V4.5 Curated / Full
- V4
- V3
- Furry 3

#### 🎯 参考图全功能
- **Vibe Transfer**（风格迁移）：多参考图，调子参考
- **Precise References**（精确参考 V4+）：角色/画风严格还原
- **img2img**：底图改图 + `strength` 控制
- **Inpainting**：局部重绘 + mask editor

#### 🇨🇳 中文友好
- **标签库**：内置 500+ 常用 Danbooru 词条中英对照（TagDict）
- **姿势/动作词库**：187 个 curated 中文词（PoseDict），分 8 类
  - 基础姿势 / 上下肢动作 / 手势 / 表情动作 / 视线方向 / 移动状态 / 战斗动作 / 互动亲密
- **标签库 → 姿势/动作** 虚拟分类：紫色高亮入口
- 中文姿势名 → 英文 tag 自动翻译

#### 🗂 预设统一管理
- **主提示词预设 / 角色预设 / 姿势预设** 三类
- **设置 → 预设** 统一管理（搜索 + 收藏 + 删除）
- **下拉菜单**：常用预设一键载入
- **姿势 tab**：保存 / 载入 / 管理按钮

#### 📦 一键打包下载
- 历史 sidebar 顶部 ↓ 按钮
- 全部 / 收藏 zip 打包
- zip 内含 `manifest.json`（prompt / seed / 模型 / 采样器 / 全部参数）
- README.txt + 命名 `YYYYMMDD_HHMMSS_seedN_WxH.png`

#### 🖼 元数据兼容
- 导入 PNG/iTXt/SD `parameters` 自动回填提示词
- 拖入图片自动检测元数据
- 选择导入哪些字段到工作台

#### 🌗 UI / UX
- 三主题：暗色 / 极夜 / 浅色（自动跟随系统）
- lucide 风格清晰图标（stroke-width 1.8 / round caps）
- 紫蓝青渐变 Logo + favicon.ico + apple-touch-icon
- 顶栏 `∞` Paper 标识（订阅 unlimited）
- 响应式（窄屏自动折叠左侧栏）
- Toast 通知 / 键盘快捷键 / 暗色 mask editor

#### 🔒 安全
- API Key **AES-256-GCM** 加密存库
- 不返显文 Key
- `.htaccess` 阻止敏感文件
- HttpOnly + Strict session cookie

### 🛠 技术栈
- PHP 8.2（NaiStudio\ 命名空间）
- Apache 2.4 + MariaDB 10.4（PDO）
- 原生 ES Modules + CSS（无构建步骤）
- GitHub Actions 友好的 dev 结构

### 📂 Project Structure
- 13 个 PHP 类（`src/lib/`） — Db / NaiApi / Encryption / Settings / TagManager / TagDict / PoseDict / GalleryManager / PromptParser / MetadataExtractor / Translator / Logger / ApiKeyManager
- 23 个 ES Module（`public/assets/js/`）
- 7 个 SQL migration（`schema/`）
- 30+ API endpoints（`public/api/`）

### 🐛 Bug Fixes (历史积累，集成到 v1.0)
- 启用 `php_zip` 后 `NaiStudio` 命名空间下 `new ZipArchive()` 报错
  → 改为 `new \ZipArchive()`（前导反斜杠）
- NAI 5xx 自动重试 2×2s + 状态映射 502
- NAI 429 按 `Retry-After` 头自动重试
- NAI 400 = proxy 端口配错（默认 7897 应为 7890）
- V4 必填字段缺失 → 用 LittleWhiteBox 参数模板
- 历史图下载 `target=_blank` 在 Chrome 失败 → fetch + blob
- `localStorage` 提示词丢失 → 改为显式 localStorage 同步
- 预览图被压缩到方块 → 用 `display:grid; place-items:center` + aspect-ratio
- prompt 输入框 render 覆盖 output → 改为不覆盖

### 📦 Dependencies
- XAMPP 8.2.12（Apache 2.4 + PHP 8.2 + MariaDB 10.4）
- PHP extensions: pdo_mysql / curl / json / openssl / zip
- 浏览器：Chrome / Edge / Firefox（任何现代浏览器）
- NovelAI 订阅：Paper / Tabletop / Opus 任一（含 API Key）

### 🔗 Links
- **Repo**: https://github.com/ywclgl258/nai-studio-local
- **README**: [README.md](README.md)

---

## 版本说明

- **Major (1.x)**: 不兼容的 API 变更或重大架构调整
- **Minor (x.0)**: 向后兼容的新功能 / 大改
- **Patch (x.x.0)**: 向后兼容的 bug 修复

当前为 **1.0.0**（首次发布）。

---

**Made with ❤️ for the local AI art community**