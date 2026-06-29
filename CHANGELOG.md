# Changelog

All notable changes to NAI Studio are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

> 中文版更新日志：[CHANGELOG.zh-CN.md](CHANGELOG.zh-CN.md)

---

## [Unreleased]

P26-06-29

> 🎉 **重大版本：AI 全面接入 + 标签超市购物车化 + SQLite 独立数据库**
> 累计新增 5 大功能模块、12 个新文件、7 个 bug 修复、19 张表 / 25,551 行数据迁移到 SQLite

### ✨ AI 写提示词（DeepSeek V4 Pro / Flash 互动式）

- **🧠 全屏弹窗工作台**：顶栏（标题 + 状态 + 清空对话）+ 中部（多轮消息流 + 输入框）+ 底部（应用 + 复制）
- **3 个目标模型 chip 一键切换**：
  - 🌸 **V4.5 Curated**：强 `{artist:xxx}` 语法、严格 Danbooru、分类顺序敏感、不容忍 emoji/颜文字
  - 🎨 **V4.5 Full**：photorealistic/写实友好、`{nsfw}` 防降级、风格标签敏感、长 prompt 支持
  - 🤖 **自动**：兜底通用预设
- **3 个预设 prompt 拆分**（按目标模型特性）：
  - `composePromptCuratedSystem()` —— V4.5 Curated 专精
  - `composePromptFullSystem()` —— V4.5 Full 专精
  - `composePromptAutoSystem()` —— 自动判断
- **localStorage 持久化** `nai.aiCompose.targetModel`，刷新页面保留目标模型
- **状态栏实时显示** `✓ deepseek-v4-pro · 5926ms · 目标 V4.5 Full`
- **实测响应**：V4 Full ≈ 5926ms / V4 Curated ≈ 4715ms

### ✨ AI 拆解分析（按 NAI 模型给不同建议）

- **`analyzeV4System($model)` 专用预设**：
  - Curated 专属 7 项检查（artist:/character: 语法、分类顺序、禁 emoji、Danbooru 严格性、DDIM 不支持 AND、832×1216 推荐）
  - Full 专属 5 项检查（风格标签、避免动漫化、避免写实化、长 prompt 优势、深度提示词语法）
  - 通用 8 项检查（质量词覆盖、tag 数量、拼写、语义冲突、权重、画师搭配、冗余、风格冲突）
- **响应里带 `target` 字段**，前端可显示「分析目标：V4.5 Curated」
- **issue.type 新增 `curated_specific` / `full_specific`** 区分显示

### ✨ 多 Provider AI 通用化

- **`AiProvider` 类**：6 个预设 — DeepSeek / OpenAI / 硅基流动 / OpenRouter / Ollama / 自定义
- **3 个免费方案**：硅基流动（Qwen2.5/GLM4/Llama3.1）+ OpenRouter `:free` + Ollama 本地
- **DeepSeek preset 更新到 V4 系列**：
  - `deepseek-v4-pro`（默认，旗舰）
  - `deepseek-v4-flash`（更快更便宜）
  - 旧 `deepseek-chat` / `deepseek-reasoner` 自动归一化到 `deepseek-v4-pro`
- **数据库迁移 010**：加 `ai_provider` / `ai_base_url` / `ai_api_key` / `ai_model` / `ai_reasoning_effort` 字段
- **前端设置页**：6 个 provider 下拉 + 自动测试连接 + 4 个免费方案标识

### ✨ 拆解器（Decompose Modal v3.1 全屏工作台）

- **96vw × 92vh** 全屏 modal
- **顶部彩色边** + 标题 + 4 个 tip 卡片空状态（画师 / 权重语法 / 未识别 tag / AI 深度分析）
- **最近拆解 localStorage**：自动保存每次拆解结果，方便回看
- **3 大 tab 切换**：配对表 / 画师 / AI
- **4 大统计卡**：总 tag 数 / 已识别 / 未识别 / 权重分布
- **底部固定栏**：💡 提示 + 复制英文 / 复制双语 / 写入主提示词
- **`TagClassifier.php`** 自动 12 大类分类（人物/数量/头发/眼睛/表情/姿势/服装/视角/背景/物种/质量/常见元素）
- **`Splitter.php`** 权重语法支持 `{tag:1.2}` / `[tag]` / `(tag)`

### ✨ 画师库（Artist Library）

- **5 张表**：`artists` / `artist_categories` / `artist_category_map` / `artist_presets` / `artist_preset_items`
- **默认走 Danbooru artists.json 在线**（`DanbooruArtistFetcher`）
- **画师串预设**：NAI 格式拼接 + NOOB 格式 + 收藏 + 分类
- **搜索**：NAI 格式 / 英文名 / 中文名
- **手动添加 + 自动补全**：输入 NAI 名 → 自动生成 NOOB 格式 + Danbooru 链接
- **本地缓存 + 在线搜索**：避免重复请求 Danbooru

### ✨ 标签超市（Tag Picker 购物车模式）

- **3 栏布局**：左 sidebar（全部结果/本地缓存/Danbooru/通用/画师/角色/版权/元，8 个分类带计数） / 中 标签网格（图片 + 名称 + 中文 + 类别 + 计数） / 右 购物车
- **交互逻辑彻底改**：
  - ❌ 旧：点击标签卡 = 复制单个到剪贴板
  - ✅ 新：点击标签卡 = **加入购物车**（toggle，再点取消）
- **购物车**：
  - 每行显示：序号 + 英文名 + 中文 + ✕ 删除按钮
  - 顶部「🗑 清空」一键清空
  - 底部「📋 结算复制（N）」按钮 → `cart.map(t => t.name).join(', ')` → 剪贴板
- **视觉反馈**：
  - 已选卡片：紫边框 + 右下角 🛒 标记
  - 本地下拉行：`+ 加购` / `✕ 移除` 状态实时切换
- **解决之前的问题**："右边空太多"（原来 1 栏布局现在 3 栏填满）

### ✨ SQLite 独立数据库（重大架构变更）

> 摆脱 XAMPP MySQL，单文件 SQLite + PHP 内置 server 即可启动

- **`Db.php` 双模式**：
  - `driver='sqlite'`（默认，单文件 `data/nai-studio.db`）
  - `driver='mysql'`（兜底，XAMPP 兼容）
  - 自动判 driver → 创建对应 PDO 实例
- **SQLite 性能优化**：
  - `PRAGMA journal_mode = WAL`（写并发不阻塞读）
  - `PRAGMA synchronous = NORMAL`
  - `PRAGMA foreign_keys = ON`（启用外键）
  - `PRAGMA busy_timeout = 5000`
- **`normalizeSql()` shim**：自动转译 MySQL 专有函数到 SQLite 等价物
  - `LEFT(s, n)` → `substr(s, 1, n)`
  - `NOW()` → `CURRENT_TIMESTAMP`
- **19 张表 schema 完整迁移**（`data/schema_sqlite.sql`）：
  - `AUTO_INCREMENT` → `INTEGER PRIMARY KEY AUTOINCREMENT`
  - `enum(...)` → `TEXT CHECK(col IN (...))`
  - `timestamp` → `DATETIME`
  - 索引名加表名前缀（避免全局冲突）
- **迁移工具**：
  - `tools/migrate_mysql_to_sqlite.php` —— 正向（含 mysqldump 自动备份）
  - `tools/migrate_sqlite_to_mysql.php` —— 反向（兜底回退）
- **`public/router.php`**：剥 `/nai-studio/` 前缀 + 自服务静态文件（CSS/JS/图片）
- **一键启动器** `tools/start.bat`：清端口 + 起 PHP server + 自动开浏览器

### 🐛 Bug 修复

- **画风预设保存弹窗按钮无反应**：HTML 缺 `<div class="modal-backdrop hidden" id="presetSaveModal">` wrapper，导致 `getElementById` 返 null。补 wrapper + preset-modal.js 加 console.error 防御
- **`prompts.php` 500 错误**：用了 `LEFT(positive, 200)`（MySQL 专有），SQLite 不支持。改为 `substr(positive, 1, 200)`
- **schema 索引名全局冲突**：`idx_post_count` / `idx_created` / `idx_name` / `idx_favorite_used` / `idx_use_count` / `idx_order` 在多个表上有同名索引 → 全部加表名前缀
- **PHP 内置 server 找不到静态文件**：router.php 没处理 /nai-studio/ 前缀下的 .css/.js/图片请求
- **PHP 内置 server 把 .php 当静态 readfile**：router 加 `&& !preg_match('/\.php$/', $uri)` 判断
- **`NOW()` 函数 SQLite 不支持**：admin/import-all-tags.php 仍用 `NOW()`，用 shim 自动转 `CURRENT_TIMESTAMP`

### 📁 新增文件清单

**后端 PHP**：
- `src/lib/AiAdvisor.php` —— AI 写提示词 / 拆解分析
- `src/lib/AiProvider.php` —— 多 Provider 抽象
- `src/lib/DeepSeekHelper.php` —— DeepSeek 调用封装
- `src/lib/TagClassifier.php` —— 标签 12 大类自动分类
- `src/lib/Splitter.php` —— 权重语法解析
- `src/lib/ArtistManager.php` —— 画师库 CRUD
- `src/lib/ArtistAdvisor.php` —— 画师建议
- `src/lib/DanbooruArtistFetcher.php` —— Danbooru artists.json 抓取
- `public/api/ai_analyze.php` —— AI 写提示词 + 拆解分析 endpoint
- `public/api/artist_presets.php` —— 画师串预设 CRUD
- `public/api/artists.php` —— 画师 CRUD
- `public/api/decompose.php` —— 拆解 endpoint
- `public/api/settings_ai.php` —— AI 设置 endpoint
- `public/router.php` —— PHP 内置 server 路由脚本
- `tools/migrate_mysql_to_sqlite.php` —— 正向迁移
- `tools/migrate_sqlite_to_mysql.php` —— 反向迁移
- `tools/start.bat` —— 一键启动器

**数据库**：
- `schema/007_decompose_translate.sql` —— 拆解器相关
- `schema/008_artist_library.sql` —— 画师库 5 张表
- `schema/009_deepseek.sql` —— DeepSeek 字段
- `schema/010_ai_provider.sql` —— AI provider 字段
- `data/schema_sqlite.sql` —— SQLite schema
- `data/nai-studio.db` —— SQLite 单文件数据库（3.83 MB）

**前端 JS**：
- `public/assets/js/ai-compose.js` —— AI 写提示词
- `public/assets/js/decomposer.js` —— 拆解器
- `public/assets/js/artist_library.js` —— 画师库

**数据**：
- `storage/tag-previews/...` —— 标签预览图（按 hash 分目录）

### 📊 性能与质量

- **启动时间**：1 分钟（XAMPP 控制面板 → Start Apache → Start MySQL）→ **3 秒**（双击 `tools/start.bat`）
- **数据规模**：19 张表 / 25,551 行（25,266 tags + 226 danbooru 缓存 + 17 姿势 + 2 角色 + 3 画师串 + 2 画风 + 1 API key + 1 设置 + 12 生图历史）
- **数据可移植**：单文件 `data/nai-studio.db` 即可打包带走
- **备份策略**：迁移时自动 mysqldump 到 `data/backups/pre-migration-{时间}.sql`

### 🔒 安全性

- **API key 加密格式 100% 保留**：`api_key_encrypted` BLOB 字段在 SQLite 原样可用，AES-256-GCM 解密无需改动
- **指纹比对不变**：`9HWS`（前 4 位）正常显示
- **本地攻击面更小**：SQLite 无网络监听端口，3306 端口可关闭

### 📚 关键学习笔记（教训）

- **PHP 内置 server 必须配 router.php** 才支持 `/nai-studio/` 前缀，否则只能 `http://localhost:8080/` 直接访问
- **SQLite 索引名全局**：不是按表 scope，必须用 `table_idx_name` 前缀
- **MySQL → SQLite 转换核心是 schema 重建 + 数据按公共列复制**，不要尝试全自动化
- **PHP 内置 server 用 `php -S 127.0.0.1:8080 -t public public/router.php`** 是组合启动的最佳方式

## [1.0.7] - 2026-06-29

### 🐛 Critical Bug Fix
- 同 1.0.6 修复：主提示词预设栏重叠（v1.0.5 移进 #promptEditor 内部导致 .prompt-highlight 把它盖住）。修法：移回 .prompt-editor **外部**，JS 在切 tab 时手动 toggle row 的 hidden

## [1.0.6] - 2026-06-29

### 🐛 Critical Bug Fix
- **主提示词预设栏和输入框重叠** — v1.0.5 把 #promptPresetRow 移进 #promptEditor 内部，但 .prompt-highlight 是 `position: absolute; inset: 0;` 覆盖整个 .prompt-editor，把 row 盖住了（视觉上 row 和 textarea 文字互相重叠）
- **修法**：把 #promptPresetRow 移回 .prompt-editor **外部**（tabs 下、editor 上的独立行），改用 JS 在切 tab 时手动 toggle row 的 hidden
- **教训**：带 `position: absolute; inset: 0` 的高亮层不能跟其他子元素共存，背景高亮用 `<textarea>` 自身 + `caret-color` 实现更好

## [1.0.5] - 2026-06-29

### 🐛 Critical Bug Fix
- **三个 select 都显示"画风1" + 主提示词 select 在所有 tab 都显示** — 用户报的真 bug
  - **根因**：`#promptPresetRow`（v1.0.1 加的主提示词 inline select 栏）写在了 `.prompt-tabs` 之后、`.prompt-editor` 之前的**外部**位置。tab 切换只 toggle 了 4 个 editor 的 hidden，**没 toggle** 这个外部 row
  - **结果**：切到"角色"或"姿势" tab 时，textarea 区域切换了，但**主提示词的"📋 提示词"徽章 + select 还在显示**。用户看到"三个 select 都一样"其实是**同一个主提示词 select**（只 1 条"画风1"）
  - **角色/姿势自己的 select** 在 `charactersEditor` / `poseEditor` 内部，但因为这些 editor 被隐藏了，里面的 select 也看不到
  - **修法**：把 `#promptPresetRow` 移进 `#promptEditor` 内部，跟 editor 一起 toggle。现在切到角色/姿势 tab 时，主提示词 select 一起隐藏，角色/姿势自己的 select 才显示出来
  - **headless 验证**：v=104 HTML 结构里 `promptPresetRow` 正确在 `promptEditor` 内部

### 🧪 为什么之前没发现
- 我之前用 headless dump 看 3 个 select 的 option 数量都对（17/2/1），但**没看实际用户切 tab 时的视觉**
- v1.0.2 我加了"📋 提示词 / 👤 角色 / 🧍 姿势"三个不同颜色徽章 — **但 v1.0.2 没改 tab 切换逻辑**，所以徽章加在 #promptPresetRow 永远显示的位置也没意义

## [1.0.4] - 2026-06-29

### 🐛 Bug Fixes
- **回滚 v1.0.3 的 import ?v= 改动**：ES module 规范不严格支持 query string（每个 import ?v=104 浏览器会视为不同模块，导致单例破坏）。回滚到 v1.0.2 的相对 import
- **加调试信息**：每个 preset select 的 title 现在显示 `[{id}] N 条预设 · 名字1, 名字2...`，用户悬停可看到实际加载到几个 + 名字列表
- **下一步定位**：用户硬刷/无痕模式都看到"三个下拉都只有画风1" — 但 headless v=104 实测每个 select 内容正确（17/2/1）。怀疑是 user 浏览器实际加载了**老版本 JS**

## [1.0.3] - 2026-06-29

### 🐛 Bug Fixes
- **子模块 import 强制加 `?v=104`**：之前 `app.js?v=104` 强制刷了，但子模块（characters/pose/presets 等）的 `import './xxx.js'` **没带版本号**，浏览器会缓存旧版本。导致用户看到 select 选项错位（3 个 select 都显示同一条）
  - 给所有 95 个相对 import 加上 `?v=104`，强制每次重载子模块
  - 这就是用户报的"三个 select 都一样，都是画风1"的根因

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