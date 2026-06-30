# Changelog

All notable changes to NAI Studio are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

> 中文版更新日志：[CHANGELOG.zh-CN.md](CHANGELOG.zh-CN.md)

---

## [Unreleased]

## [1.1.4] - 2026-06-30

> 🎯 **真正零依赖便携版 + 系统级 PHP server 死锁修复**
> 解压即跑，**无需**安装 PHP / Apache / XAMPP。修了 4 个会让 server 永久死锁的 bug。

### ✨ Features

- **🪶 Portable 模式**（v1.1.4+）
  - `runtime\php\php.exe` 内置精简 PHP 8.2.12 NTS（39 MB）
  - `runtime\php\extras\ssl\cacert.pem` Mozilla CA bundle 189KB
  - `runtime\php\php.ini` 配 `curl.cainfo` + `openssl.cafile`（**所有 HTTPS 失败的根因**）
  - `user-data\` 隔离用户数据（DB / 图 / 缩略图 / 日志 / 加密 API key 密文）
  - 首次启动自动从 `user-data\data-tpl\` 复制模板
- **🌐 本地翻译服务**（NLLB-200 distilled 600M）
  - 三级翻译源：`off` / `fallback`（默认）/ `local`
  - NLLB 单模型支持 200+ 语言双向翻译（en↔zh, ja↔zh, fr↔zh, …）
  - 自动判方向（中文→zhToEn，英文→enToZh）
  - 替代 OPUS-MT：单一模型 200+ 语言，不再切换
  - 旧 OPUS-MT 模型已删除
- **📦 CLI 拆分**（避免 web 死锁）
  - `tools/expand_tags_cli.php` — 批量扩充标签
  - `tools/fetch_all_images_cli.php` — 批量下载示例图
  - `expand-tags.php` / `fetch_all_images.php` / `import-all-tags.php` web 端改为 spawn detached 进程

### 🐛 Bug Fixes（4 个会让 server 永久死锁的关键 bug）

- **🔥 致命：PHP server 自杀**
  - 现象：调 `backend.php?action=stop` 把 PHP server 自己杀掉了
  - 根因：killPort(8080) 杀掉监听 8080 的进程，但请求本身就是在 8080 上处理的
  - 修法：拒绝在 SERVER_PORT=8080 调用 stop（503 + 提示用 stop.bat）
- **🔥 致命：expand-tags.php 长任务死锁 server**
  - 现象：调用 `expandStart` 5 秒后所有 endpoint 都 timeout
  - 根因：`fastcgi_finish_request` 在 PHP built-in server 下无效，整个 while 循环阻塞单线程 server
  - 修法：vbs 包裹 spawn 独立 PHP 进程，HTTP 立即返回
- **🔥 致命：import-all-tags.php 同问题**（同修法）
- **🔥 致命：fetch_all_images.php SSE 同问题**（同修法）
- **🔧 拆解 `1.6::tag` 剥权重**：tag classifier 现在认 `{tag}` / `[tag]` / `N::tag` 三种权重语法
- **🔧 danbooru.php?action=translate 自动判方向**：之前固定 zhToEn
- **🔧 decompose.php?action=lookup miss 兜底**：miss 走 en_guess（zhToEn 翻译）
- **🔧 tags.php?action=translate_one 3 bug**：read_json_body（前端发 JSON）+ 移除"中文拒绝"+ 中文走 zhToEn→enToZh
- **🔧 Translator::zhToEn 加 AI 兜底**：本地翻译失败时调 DeepSeek
- **🔧 artists.php danbooru_search 失败降级**：网络失败用本地 artist_fallback
- **🔧 NaiApi::endpoint()**：HTTP 代理走 NAI 官方 + CURLOPT_PROXY，镜像直接拼 URL
- **🔧 Settings::getTranslateSource() + shouldTryLocal() / shouldFallbackToOnline()**

### 📝 Documentation

- **README 重写**为「零依赖便携版」主打，Apache/XAMPP/MySQL/MariaDB 6 处历史残留全清
- **start.bat 重写**：CRLF + 4 层引号去掉 + goto+label 替代 `\` 续行 + `pause` 替代 `timeout` + powershell Start-Process 兜底 explorer
- **stop.bat 重写**：CRLF，杀 8080 端口
- **.gitignore 修**：track `data/schema_sqlite.sql` + `translate-server/` 源码（忽略 .model-cache/node_modules/err*.log/out*.log）+ 删 stray `Stop`/`enter` 0字节文件 + 修 tests/*.php 漏 ignore

### 🗄️ DB

- **迁移 011**：加 `settings.translate_source TEXT DEFAULT 'fallback'` + `settings.local_translate_url` + `settings.local_translate_enabled`
- **stale schema 标记**：`api_logs` / `precise_refs` / `tag_aliases` / `tag_prompts` / `vibe_refs` 5 个表 0 引用，未删（保留兼容）

### 🎓 关键教训

- **PHP built-in server 是单线程**：任何 `set_time_limit(0) + while` 在请求线程里跑都会死锁整个 server。v1.1.4 后所有长任务必须 spawn 独立 PHP 进程
- **不要通过 web 进程自杀**：`killPort(8080)` 杀不掉自己；但能杀掉自己 listener 的所有同端口进程
- **`fastcgi_finish_request` 只在 PHP-FPM 有效**：built-in server 下不存在（`function_exists` 返 false，但走 else 分支也没用，会一直跑）
- **Windows CRLF 必用**：start.bat / stop.bat / .ps1 必须 CRLF + 不以 `xxx\` 结尾（cmd 续行符吞注释）
- **curl.cainfo**：PHP 内置 curl 不带 CA 证书，所有 HTTPS 调外部 API（NAI / Danbooru / DeepSeek）都失败

## [1.1.3] - 2026-06-30

> ⚙️ **PHP server 状态查询 + 一键启停 + 姿势/角色预设 combobox**
> 补齐「点一下就启停」的最后拼图

### ✨ Features

- **🟢 backend.php 状态 API**：`backendStatus` / `backendStart` / `backendStop`
  - 检查 8080 端口 + DB 存在 + DB 非空 + log 文件
  - PID 文件记录，stop 时 taskkill
  - start 走 vbs 包裹 spawn 独立 PHP server
- **🟢 start.bat v1.1.3**：5 步启动（user-data 检查 → DB 检查 → 清端口 → 后台启动 → 等就绪）
- **🟢 stop.bat**：taskkill 杀 8080 端口所有进程
- **🟢 姿势/角色预设 combobox**：v1.1.2 之前是下拉框，1.1.3 改 combobox（输入 + 列表 + 搜索）

## [1.1.2] - 2026-06-30

> 🌐 **本地缓存未翻译标签：再次翻译 + 手动纠正 + 批量翻译**
> 把 25266 个 tag 里 25020 个未翻译的逐步翻译。字典优先 → MyMemory 兜底。

### 🔤 翻译 API

- **`POST /api/tags.php?action=translate_one`** — 单条重译
  - 优先查 `TagDict`（500+ 内置字典命中秒回：1girl/1个女孩、long_hair/长发...）
  - miss 走 `Translator::enToZh`（MyMemory API）
  - 写回 `tags.cn_name` + `translated_at`，同步到 `danbooru_tag_cache`
  - 跳过非英文（中文/特殊字符）→ 400 拒绝
- **`POST /api/tags.php?action=manual_translate`** — 手动纠正
  - 任意 `name` + `cn_name` 直接 SET 落库（覆盖自动翻译）
- **`GET /api/tags.php?action=untranslated_list`** — 分页列出 cn_name 为空的 tag
- **`local_list` 加 `has_cn` 筛选参数**：`?has_cn=0` 列出未翻译，`?has_cn=1` 列出已翻译

### 🗄 数据库迁移

- **`tools/_migrate_add_translated_at.php`** — 给 `tags` / `danbooru_tag_cache` 加 `translated_at DATETIME` 字段

### 🎨 UI

- **顶部 "🌐 批量翻译" 按钮**（橙红渐变）：自动扫 25020 个未翻译，串行调 MyMemory（每 50ms 间隔防限流）
- **本地缓存 tab 侧边栏新增 "翻译" 分组**：
  - ✅ 已翻译 245（已翻译数）
  - 🔤 未翻译 25.0k（待翻译数）
- **每张卡片左下角 "✏️ 改 / ✏️ 译" 按钮**（hover 才显示）：
  - 弹自定义 modal 显示英文原文 + 中文输入框
  - 留空 → 触发自动翻译
  - 填值 → 触发手动纠正
  - 完成后自动刷新当前列表 + sidebar 计数

### 🛠 标签拉取保底（tag_image.php）

- **分层 fallback**：`preview_file_url` → `large_file_url` → `file_url`
  - 之前只看 `preview_file_url`，该字段为空时直接失败
  - 现在依次试大图 → 原图，总能拿到
- **`danbooruPickFirstPost()`** 函数：拿第一张有 preview 的 post
  - `limit=1&random=true` 失败时，遍历 `limit=20` 找第一张有 `preview_file_url` 的
  - 兜底拿第一张的 `file_url`（不再是死路）
- `tags.example_image_url` 在写入前会先做 HEAD 探活（避免 404 永久缓存）

### 📝 UI 文字微调

- 提示词 tab 顶部"📋 提示词"badge → "🎨 画师串"（明确这是画师组合模板，不是普通提示词）
- 标题也改为"画师串预设（多画师组合模板）"

## [1.1.1] - 2026-06-30

> 🚀 **标签预览图全流程：构建时预生成 + 本地缓存 tab + 单 tag 手动拉取**
> 解决"标签超市卡片没图"的核心痛点。运行时纯静态 `<img>`，零 JS 状态机。

### 🖼 标签预览图预下载（仿 tags.novelai.dev）

- **核心思路**：图片由后端构建时下载到本地 `storage/tag-previews/<hash>/<name>.jpg`，运行时纯静态 `<img loading="lazy">`，无 JS 状态机/无并发池/无异步抓图
- **`tools/fetch_all_tag_images.php`** — CLI 批量预生成工具
  - `php tools/fetch_all_tag_images.php 500` 跑 top 500
  - 按 `post_count DESC` 排序抓热门
  - Danbooru 礼貌限速（≈1.5s/张）≈ 40 张/分钟
  - 本地已有 / DB 已同步 → 跳过；无 posts → 标记已尝试，避免反复请求
- **`public/api/admin/fetch_all_images.php`** — HTTP 流式触发（SSE）
  - `?action=stats` → 覆盖率查询
  - `?action=run&limit=N` → 流式进度（`data: {"stage":"progress",...}`）
- **设置页加「🚀 开始抓图」按钮**：直接触发 SSE，UI 实时显示 ✅ 成功 / ❌ 失败 / · 无 post / 进度条

### 🆕 标签超市新增「本地缓存」tab

- **顶部 tab 切换**：「🔍 在线搜索」/「💾 本地缓存」（带 tag 总数 badge）
- **本地缓存 tab 功能**：
  - 网格视图（同搜索 tab，但数据来自 `tags` 表的本地 DB）
  - 分页加载（每页 60）+ 滚动到底自动加载更多
  - 筛选条：分类（通用/画师/版权/角色/元/质量/风格/环境）/ 是否有图 / 排序（热门/最近/名字/随机）
  - **每张卡片的「📥 拉取」按钮**（仅无图时显示）：点一下 → 后端调 Danbooru → 存本地 → 写 DB → 卡片立刻显示新图
  - 拉取成功 → 按钮变绿 "✅ 已拉取"，1.5s 后消失
  - 拉取失败（冷门 tag / 受限 tag）→ 按钮变红 "❌ 网络错"
- **后端 API** `public/api/tags.php?action=local_list`：
  - 支持分页 / category / has_image / q / sort
  - JOIN `tag_categories` 表返回 `category_name_cn`，前端直接显示中文分类
  - 返回 `{ rows, total, page, has_more }`

### 🔧 重构与 bug 修复

- **删除 `_imgPool` / `lazyLoadExamples` / `scheduleImageFetch` / `fetchOneImage`** —— 整个 JS 状态机（约 90 行）由纯静态 `<img>` 替代
- **修 `tags.php?action=local_search` 的 JOIN bug**：原本查 `danbooru_tag_cache.example_image_url`（null），改为 `LEFT JOIN tags` + `COALESCE(t.example_image_url, d.example_image_url)`，让本地搜出来的 tag 也能显示图片
- **`buildCard` 支持 `opts.showFetchBtn`** —— 单个 tag 拉取预览按钮，只在「本地缓存」tab 渲染
- **新事件**：本地缓存 tab 滚动到底自动 `loadLocalPage(false)` 加载下一页
- **ES module 缓存修复（重要）**：添加 `<script type="importmap">` 给所有 module 文件加 `?v=<filemtime>` 版本号，避免浏览器缓存旧版本模块（之前改 JS 改完不生效就是这个原因）

### 📊 性能对比

| 方案 | 运行时 | 抓图失败处理 | 用户体验 |
|------|--------|------------|---------|
| ~~JS 状态机并发池~~ | 每开 picker 重新拉 4 张 | 静默失败 / spinner 转不停 | 卡顿 + 缺图 |
| **预下载 + 静态 img**（新） | 0 状态机 | 提前标灰 + 单 tag 手动补 | 流畅 + 自助补 |

### 📁 新增/修改文件

- 新增：`tools/fetch_all_tag_images.php`、`public/api/admin/fetch_all_images.php`、`public/api/tag_image.php`（之前 fetch 接口补充）
- 修改：`public/api/tags.php`、`public/index.php`、`public/assets/js/{tag-picker,api,actions,app}.js`、`public/assets/css/tag-picker.css`、`src/lib/Db.php`
- 数据库：`tags.example_image_url` 字段已有，无需迁移

---

## [1.1.0] - 2026-06-29

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