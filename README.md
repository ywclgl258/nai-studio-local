# NAI Studio Desktop

> 🎨 **本地生图工作台 · Tauri + Rust + SQLite · Windows 单文件 8MB**
>
> 把 1.8GB 的 PHP + XAMPP 老栈重写成 8MB 单 EXE — 启动 <1s, 内存 44MB, 数据本地 SQLite。

[![Version](https://img.shields.io/badge/version-2.0.0-blue.svg)](VERSION)
[![Tauri](https://img.shields.io/badge/Tauri-2.11-orange.svg)](https://tauri.app/)
[![Rust](https://img.shields.io/badge/Rust-stable-red.svg)](https://www.rust-lang.org/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](#-license)

---

## ✨ 这是什么

NAI Studio Desktop 是 [NAI Studio](https://novelai.net) 的**桌面端本地生图工作台**。
原生对接 NovelAI 的 V3 / V4 / V4.5 接口,搭配 Real-ESRGAN 放大、Danbooru 标签库、画师库、多 Key 轮换等生图生态功能。

整个应用就是一个 `nai-studio-desktop.exe`,**不依赖 PHP / XAMPP / Node.js / MySQL** — 双击就跑。

> 📖 **用户使用手册**: [用户手册.md](用户手册.md) — 装上后怎么用,常见问题
> 📜 **变更日志**: [CHANGELOG.md](CHANGELOG.md) — 版本历史

---

## 📸 截图

> 🚧 待补: 启动界面 / 主工作台 / 画廊 / Upscale 弹窗

```
[主工作台]              [画廊]                [Upscale 弹窗]
┌──────────────┐      ┌──────────────┐       ┌──────────────┐
│ prompt: ...  │      │ [缩略图网格] │       │ 2x / 4x / 8x │
│              │      │              │       │ 进度: ████░  │
│ [生成]        │      │ ★ fav        │       │ 7.6s         │
├──────────────┤      ├──────────────┤       ├──────────────┤
│ [大图预览]    │      │ [历史条]      │       │ [下载] [取消] │
└──────────────┘      └──────────────┘       └──────────────┘
```

---

## ⚡ 为什么用 Tauri 重写

| 指标 | 旧版 (PHP + XAMPP) | 新版 (Tauri + Rust) | 提升 |
|------|---------------------|---------------------|------|
| **体积** | 1.8 GB (PHP runtime + Apache + MySQL) | **8 MB** (单 EXE) | **225×** ↓ |
| **启动** | ~5s (Apache + MySQL) | **<1s** | 5× |
| **内存** | ~150 MB | **44 MB** | 3.4× ↓ |
| **依赖** | XAMPP / MySQL / PHP | **零** (WebView2 Win10+ 自带) | ∞ |
| **卸载** | 控制面板 / 留注册表 / 留服务 | 删 EXE + 删 APPDATA 目录 | 干净 |

技术上就是把 PHP 后端换成 Rust(Axum),前端 HTML/JS 直接复用,数据无缝迁移。

---

## 🏗️ 架构

```
┌─────────────────────────────────────────────────────────────┐
│ nai-studio-desktop.exe  (8 MB · Rust 1.77 + Tauri 2.11)    │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  ┌──────────────────────┐    ┌──────────────────────────┐   │
│  │ Tauri WebView        │    │ Axum HTTP Server         │   │
│  │ (Edge WebView2)      │ ←→ │ (127.0.0.1:RANDOM_PORT)  │   │
│  │                      │    │                          │   │
│  │ 加载 http://127.../  │    │  /api/*  → 30+ handlers │   │
│  │ 跑前端 JS / CSS     │    │  /storage/* 静态文件      │   │
│  │ 用户操作             │    │  /*       → 静态前端      │   │
│  └──────────────────────┘    └──────────────────────────┘   │
│           ↑                          ↓                       │
│           └────── fetch /api/... ────┘                       │
│                                                              │
│  ┌────────────────────────────────────────────────────────┐ │
│  │ SQLite (rusqlite, bundled)                             │ │
│  │   nai-studio.db  (同 PHP 版 schema, 100% 兼容)          │ │
│  └────────────────────────────────────────────────────────┘ │
│                                                              │
│  ┌────────────────────────────────────────────────────────┐ │
│  │ NAI HTTP client (reqwest + rustls)                     │ │
│  │   • 多 Key 轮换                                          │ │
│  │   • 5xx 自动重试 / 429 Retry-After                      │ │
│  │   • V3 / V4 / V4.5 payload 构造                          │ │
│  └────────────────────────────────────────────────────────┘ │
│                                                              │
│  ┌────────────────────────────────────────────────────────┐ │
│  │ Real-ESRGAN (subprocess)                                │ │
│  │   ncnn-vulkan 20220424  2x/4x/8x lossless upscaling    │ │
│  └────────────────────────────────────────────────────────┘ │
│                                                              │
└─────────────────────────────────────────────────────────────┘
                              ↓
              ┌────────────────────────────────┐
              │ 用户数据 (SQLite + 图片)         │
              │ %APPDATA%\nai-studio-desktop\   │
              └────────────────────────────────┘
```

---

## 🚀 快速开始

### 终端用户 (只想用)

1. **下载** `nai-studio-desktop.exe` (8MB)
2. **双击运行** — 首次启动会弹窗口
3. **配置 NAI API Key** — 设置 → API Key → 粘贴你的 key
4. **开始出图** — 主界面写 prompt,点生成

> 💡 Windows 10/11 自带 WebView2 Runtime,无需额外安装。

### 开发者 (从源码构建)

```bash
# 前置: Rust + cargo + WebView2
# 推荐 gnu toolchain (避免 MSVC link.exe 限制)

# 1. 克隆 / 进入项目
cd D:\anima\nai-studio-desktop

# 2. Dev 模式 (热重载)
cd src-tauri
cargo tauri dev

# 3. Release 构建
cargo tauri build --no-bundle
# 产物: src-tauri/target/release/nai-studio-desktop.exe (8MB)

# 4. 完整 installer (MSI + NSIS)
cargo tauri build
# 产物: src-tauri/target/release/bundle/{msi,nsis}/*
```

### 工具脚本

```bash
# 用 Node 脚本检查 DB
npm run check-db

# 把 PHP 端 index.php 转 index.html
node tools/php_to_html.cjs

# 把前端硬编码 /nai-studio/api/ 改成 /api/
node tools/fix_frontend_paths.cjs
```

---

## 📦 核心功能

### 出图
- ✅ NAI **V3 / V4 / V4.5-Curated / V4.5-Full** 全模型支持
- ✅ 多 API Key 轮换 (硬错误自动跳过, 软错误 5xx 重试)
- ✅ 单张 / 批量 / 队列生成
- ✅ Vibe Transfer (风格迁移)
- ✅ Precise / Mask Editor (局部重绘)
- ✅ Base Image (图生图)

### 提示词
- ✅ 主 prompt / 负面 prompt / 角色 / 姿势 — 4 个 tab 分开管理
- ✅ 每个 tab 都可保存为预设, 一键载入
- ✅ Prompt 模板组合
- ✅ Mention Presets (命名预设)

### 标签 / 画师
- ✅ 本地 tag 库 (带中文翻译 + 分类)
- ✅ Danbooru 在线搜索 + 翻译 (MyMemory)
- ✅ 本地画师库 (artist:xxx 预设)
- ✅ 画师分类 / 标签
- ✅ 自动从 Danbooru 抓取画师示例图

### 画廊
- ✅ 缩略图网格 + 大图预览
- ✅ 收藏 ★ / 排序 / 过滤
- ✅ 复制 prompt / seed / 设置 一键复用
- ✅ 批量打包 ZIP
- ✅ 清理孤儿文件

### Upscale
- ✅ Real-ESRGAN 2x / 4x / 8x lossless
- ✅ 8x = 4x AI 放大 + 2x LANCZOS 二次采样
- ✅ 自动下载 binary + 模型 (首次)

### AI 助手
- ✅ DeepSeek / OpenAI / SiliconFlow / Ollama 4 个 provider
- ✅ 测试连接
- ✅ Prompt 智能补全 (基础版)
- ✅ AI 图像分析 (Phase 3.3 text-only, Phase 4 接 vision)

### 系统
- ✅ AES-256-GCM API key 加密 (与 PHP 版 100% 兼容)
- ✅ HTTP 代理 (Clash / v2rayN)
- ✅ 多 key 轮换 + 429 限流退避
- ✅ 后台长任务管理 (expand_tags / import_all_tags / fetch_all_images)

---

## 📡 API 端点 (30+)

### 核心
| Method | Path | 功能 |
|--------|------|------|
| `GET/POST` | `/api/settings` | 配置读写 (key 加密存) |
| `GET/POST/DELETE` | `/api/gallery` + `/zip` + `/clear` | 画廊 CRUD |
| `POST` | `/api/generate` | NAI 出图 (多 key 轮换 + 重试) |
| `POST` | `/api/upscale` | Real-ESRGAN 2x/4x/8x |
| `GET` | `/api/anlas` | 查 NAI 余额 |

### 标签 / 画师
| Method | Path | 功能 |
|--------|------|------|
| `GET` | `/api/tags?action=...` | 6 种 action (categories/search/local_search/lookup/detail/local_list) |
| `GET` | `/api/artists?action=...` | 4 种 action (list/detail/search/categories) |
| `GET` | `/api/pose-dict` | 姿势字典 |
| `POST` | `/api/decompose` | prompt 拆分 + 分类 |
| `GET` | `/api/danbooru?action=tag\|post\|translate` | Danbooru 在线 + 翻译 |

### 预设
| Method | Path | 功能 |
|--------|------|------|
| `GET/POST/DELETE` | `/api/prompts` | 提示词预设 |
| `GET/POST/DELETE` | `/api/character_presets` | 角色预设 |
| `GET/POST/DELETE` | `/api/pose_presets` | 姿势预设 |
| `GET/POST/DELETE` | `/api/artist_presets` | 画师预设 |

### 配置
| Method | Path | 功能 |
|--------|------|------|
| `GET/POST` | `/api/settings_ai` + `/test` | AI provider 配置 + 连通测试 |
| `GET/POST` | `/api/api-keys` | NAI 多 key 管理 |
| `GET/POST` | `/api/proxy` + `/test` | 代理配置 + 测试 |

### 工具
| Method | Path | 功能 |
|--------|------|------|
| `POST` | `/api/upload` | multipart 文件上传 |
| `POST` | `/api/import_meta` | 导入 PNG metadata |
| `POST` | `/api/cleanup` | 清理 cache / logs / 孤儿文件 |
| `GET/POST` | `/api/ai_analyze` | AI 图像分析 (text-only) |
| `GET` | `/api/tag_image?method=decompose` | prompt 拆分 (WD Tagger Phase 4) |

### 后台长任务
| Method | Path | 功能 |
|--------|------|------|
| `GET/POST/DELETE` | `/api/admin/expand-tags` | AI 拆 tag 任务 |
| `GET/POST/DELETE` | `/api/admin/import-all-tags` | 全量 tag 导入 |
| `GET/POST/DELETE` | `/api/admin/fetch_all_images` | NAI 历史作品拉取 |

> 💡 **URL 兼容**: 前端 JS 仍写 `/api/xxx.php?action=...`,后端 middleware 自动改写到 `/api/xxx?action=...`,不用改 JS

---

## 🔄 数据迁移 (从 PHP 版)

老 PHP 版数据在 `D:\anima\nai-studio\user-data\`,**SQLite schema 100% 兼容**,直接 copy 即可:

```powershell
# 1. 复制 DB
Copy-Item "D:\anima\nai-studio\user-data\nai-studio.db" `
          "$env:APPDATA\nai-studio-desktop\nai-studio.db" -Force

# 2. 复制 storage (图片 + 缩略图)
Copy-Item "D:\anima\nai-studio\user-data\storage\*" `
          "$env:APPDATA\nai-studio-desktop\storage\" -Recurse -Force

# 3. 复制 data / data-tpl (业务数据)
Copy-Item "D:\anima\nai-studio\user-data\data" `
          "$env:APPDATA\nnai-studio-desktop\data" -Recurse -Force
Copy-Item "D:\anima\nai-studio\user-data\data-tpl" `
          "$env:APPDATA\nnai-studio-desktop\data-tpl" -Recurse -Force
```

迁移完成后所有图片 / preset / tag / artist 都在 Tauri 版能直接看到。**API key 也能无缝解密**(AES key 一致)。

PHP 源归档保留在 `D:\anima\nai-studio-archive\`(1.8GB),想回滚直接挪回去。

---

## 🛠️ 技术栈

| 层 | 技术 |
|----|------|
| 后端 | Rust 1.77+ (gnu toolchain) |
| HTTP 框架 | Axum 0.7 + tower-http |
| 异步运行时 | Tokio (full) |
| 数据库 | rusqlite 0.32 (bundled, WAL mode) |
| HTTP 客户端 | reqwest 0.12 (rustls + gzip + brotli) |
| 加密 | aes-gcm 0.10 (PHP 兼容格式) |
| 图像 | image 0.25 (png + jpeg + Lanczos3) |
| ZIP | zip 0.6 (NAI 返回 ZIP 提取) |
| 文件系统 | walkdir 2 (cleanup), sha2 0.10 (upload hash) |
| WebView | Tauri 2.11 + Edge WebView2 |
| 前端 | 原 PHP 版 JS / CSS (无修改) |

---

## 📂 项目结构

```
D:\anima\nai-studio-desktop\
├── src-tauri/                          ← Rust 后端
│   ├── src/
│   │   ├── api/                        ← 30+ 业务 API
│   │   │   ├── settings.rs             ← 配置
│   │   │   ├── gallery.rs              ← 画廊
│   │   │   ├── generate.rs             ← NAI 出图
│   │   │   ├── upscale.rs              ← Real-ESRGAN
│   │   │   ├── tags.rs                 ← 标签库
│   │   │   ├── artists.rs              ← 画师库
│   │   │   ├── danbooru.rs             ← Danbooru 在线
│   │   │   ├── decompose.rs            ← prompt 拆分
│   │   │   ├── ai_analyze.rs           ← AI 分析
│   │   │   ├── tag_image.rs            ← 图片打 tag
│   │   │   ├── cleanup.rs              ← 清理
│   │   │   ├── settings_ai.rs          ← AI 配置
│   │   │   ├── api_keys.rs             ← NAI 多 key
│   │   │   ├── proxy.rs                ← 代理
│   │   │   ├── upload.rs               ← 文件上传
│   │   │   ├── import_meta.rs          ← 导入元数据
│   │   │   ├── pose_dict.rs            ← 姿势字典
│   │   │   ├── prompts.rs              ← prompt 预设
│   │   │   ├── character_presets.rs    ← 角色预设
│   │   │   ├── pose_presets.rs         ← 姿势预设
│   │   │   ├── artist_presets.rs       ← 画师预设
│   │   │   ├── anlas.rs                ← 余额
│   │   │   ├── status.rs               ← 状态
│   │   │   ├── backend.rs              ← 后端
│   │   │   ├── admin/                  ← 长任务
│   │   │   │   ├── expand_tags.rs
│   │   │   │   ├── import_all_tags.rs
│   │   │   │   └── fetch_all_images.rs
│   │   │   └── mod.rs
│   │   ├── db/                         ← SQLite + migrations
│   │   │   ├── connection.rs
│   │   │   ├── migrations.rs
│   │   │   └── mod.rs
│   │   ├── http/                       ← 路由 + middleware
│   │   │   ├── routes.rs               ← 30+ 路由
│   │   │   ├── rewrite.rs              ← /api/*.php → /api/*
│   │   │   ├── response.rs
│   │   │   └── mod.rs
│   │   ├── nai_api.rs                  ← NAI HTTP 客户端
│   │   ├── encryption.rs               ← AES-256-GCM
│   │   ├── paths.rs                    ← 跨平台路径
│   │   ├── server.rs                   ← Axum 启动
│   │   ├── state.rs                    ← AppState
│   │   ├── error.rs                    ← AppError + IntoResponse
│   │   └── lib.rs                      ← Tauri 入口
│   ├── Cargo.toml                      ← 依赖 (30+ crates)
│   ├── tauri.conf.json
│   └── target/release/nai-studio-desktop.exe   ← 最终产物 (8 MB)
│
├── src/                                ← 前端 (从 PHP public/ 复制)
│   ├── index.html                      ← SPA shell
│   ├── assets/
│   │   ├── css/                        ← main.css + components.css + tag-picker.css
│   │   ├── js/                         ← app.js + api.js + gallery.js + ... (30+ 文件)
│   │   └── fonts/
│   ├── favicon.ico / .svg / -32.png / -192.png
│   └── apple-touch-icon.png
│
├── tools/                              ← 一次性工具脚本
│   ├── php_to_html.cjs                 ← index.php → index.html
│   └── fix_frontend_paths.cjs          ← /nai-studio/api/ → /api/
│
├── 用户手册.md                          ← 中文使用手册 (面向终端用户)
├── CHANGELOG.md                        ← 变更日志
├── README.md                           ← 本文件
├── VERSION                             ← 2.0.0
└── package.json                        ← npm scripts + dev deps
```

---

## ⚠️ Known Issues

1. **空目录 `D:\anima\nai-studio` 删不掉**
   - PHP 源已移到 `D:\anima\nai-studio-archive`(1.8GB 完整保留),但根目录空目录有 Windows 进程 hold
   - 影响: 占 0 空间, 不影响功能
   - 解决: 重启机器后在资源管理器右键删, 或 `Remove-Item -LiteralPath "D:\anima\nai-studio" -Force`(需 admin)

2. **Phase 3 / Phase 4 stub 标记**
   - `ai_analyze`: text-only 调 LLM(Phase 4 接 vision 多模态)
   - `tag_image`: 仅 `method=decompose` 走内置拆分(Phase 4 接 WD Tagger ONNX)
   - `admin/*`: 模拟任务框架(Phase 4 实装 AI 拆 tag / 拉 Danbooru / 拉 NAI 历史)
   - `cleanup`: 不实现 `level=all` 删除 generations 行(Phase 4)

3. **WebView race condition**
   - `tauri.conf.json` 配 `frontendDist: "../src"` + `win.eval("window.location.replace(url)")` 有 race
   - 改进方向: 用 `WebviewUrl::External` 模式直接加载 HTTP URL
   - 影响: 偶尔有 0.5s 闪烁, 无功能问题

4. **release build 不带 installer**
   - `cargo tauri build --no-bundle` 只产 EXE(8MB)
   - `cargo tauri build` 加 MSI/NSIS 还要装 wix / nsis 工具链(约 200MB),本项目没做
   - 需要 installer 时: 自带 `tools/` 加 wix 3.x + NSIS 3.x 后再 build

---

## 🆘 故障排查

| 症状 | 原因 | 修法 |
|------|------|------|
| 启动后白屏 | WebView2 没装 | Win10 1809 以下手动装 https://developer.microsoft.com/microsoft-edge/webview2/ |
| 出图 500 | NAI 服务端问题 | 等几分钟再试 / 换 Key |
| 出图 400 | proxy 配错 | 检查 `Settings → Proxy` URL(默认 7890, 不是 7897) |
| 出图 403 | Cloudflare WAF 拦 | 开 Clash / v2rayN 走代理 |
| 出图 429 | 限流 | 等 Retry-After 秒数,或加多 Key |
| Upscale 失败 | 没下载 binary | 看 logs, 重试一次让它自动下 |
| DB 损坏 | 异常断电 | 删 `nai-studio.db*` 三个文件重启,会重建空 DB |

详细看 [用户手册 §7 常见问题](用户手册.md#7-常见问题)。

---

## 🤝 贡献

欢迎提 issue / PR。开发流程:
1. fork → 创建分支
2. `cargo tauri dev` 跑起来
3. 改代码 + 测试
4. 提 PR

代码风格:
- Rust: `cargo fmt` + `cargo clippy`
- 锁 await 模式: 用 block-scope, 不要 `drop(conn)` 后让变量名留在 scope(否则 parking_lot::MutexGuard 不 Send, future 不 Send)
- API URL: 前端仍写 `.php` 后缀, 后端 middleware 自动改写, 不用动

---

## 📜 License

MIT

---

## 🙏 致谢

- [NovelAI](https://novelai.net) — 出图 API
- [Tauri](https://tauri.app) — 桌面应用框架
- [Axum](https://github.com/tokio-rs/axum) — Rust HTTP 框架
- [Real-ESRGAN](https://github.com/xinntao/Real-ESRGAN) — 图像放大
- [Danbooru](https://danbooru.donmai.us) — 标签数据源
- [MyMemory](https://mymemory.translated.net) — 翻译 API
