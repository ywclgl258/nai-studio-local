# NAI Studio v1.1.4 - 发版说明

> 🎉 **本版本最大变化：真正零依赖，解压就跑**
> 同时修了 4 个会让 PHP server **永久死锁**的关键 bug

## 一句话总结

| | v1.1.3 | **v1.1.4** |
|---|---|---|
| 装 PHP | 必需 | **不用**，自带 39MB runtime |
| 配 MySQL/Apache | 必需 | **不用**，SQLite 单文件 |
| 调 HTTPS 报错 | 经常 | **修了**（cacert.pem） |
| 启停 server | 手动 | **一键**（start.bat / stop.bat） |
| server 死锁 | 4 个 bug | **修了**（detached CLI） |
| 翻译 | 只在线 MyMemory | **NLLB 本地 200+ 语言**（默认 fallback 三级） |

---

## 🆕 怎么用（新人视角）

### 1. 解压
- 路径**不要有中文**（Windows `php -S` 对中文路径支持差）
- 不要装到 `Program Files`（写权限问题）
- 推荐：`D:\tools\nai-studio\` 或 `D:\anima\nai-studio\`

### 2. 启动
双击 `tools\start.bat`，看到 `Service ready` 即可

### 3. 配置
浏览器打开 `http://127.0.0.1:8080/nai-studio/`
- 顶部粘 NAI Token → 保存
- （可选）打开设置，配代理 / 翻译源 / DeepSeek Key

### 4. 出图
- 提示词 + 标签超市 + 画师库
- 点「生成」

### 5. 关闭
双击 `tools\stop.bat`（或直接关浏览器，server 还在后台跑）

---

## 🆕 翻译源三级（设置页 → 翻译源）

| 选项 | 行为 | 适合 |
|---|---|---|
| **off**（只在线） | 直接调 DeepSeek → MyMemory | 国内网络好，懒得下模型 |
| **fallback**（默认） | 本地 NLLB 优先 → AI → MyMemory | 大部分人，**推荐** |
| **local**（只用本地） | 只用 NLLB，失败就报"未翻译" | 内网 / 隐私要求 |

NLLB 首次启动会从 `translate-server/.model-cache/` 加载 874MB 量化模型，约 5 秒。

---

## 🆕 数据备份

**整个 `user-data\` 目录就是你的全部数据**：
- `user-data\nai-studio.db` — 主数据库（标签、画师、预设、API key 密文、历史）
- `user-data\storage\images\` — 出图
- `user-data\storage\tag-previews\` — 标签预览图
- `user-data\logs\` — 日志
- `user-data\data-tpl\` — 升级时**不要删**（首次启动用它当模板）

**备份 = 复制整个 `user-data\` 到别处**。**还原 = 替换回 `user-data\`**。

**重装项目不会丢数据**——`user-data\` 独立于源码。

---

## 🆕 升级（已有 v1.1.3 → v1.1.4）

1. 备份 `user-data\` 到别处
2. 跑 `tools\stop.bat` 停服务
3. 用新版本覆盖（**不要删 user-data\，不要删 data\backups\**）
4. 跑 `tools\start.bat`
5. 数据库迁移会自动跑（settings 表加 `translate_source` / `local_translate_url` / `local_translate_enabled`）

---

## 🐛 v1.1.3 → v1.1.4 修了哪些 bug

### 🔥 4 个会让 server 永久死锁的 bug

| Bug | 触发 | 现象 | 修法 |
|---|---|---|---|
| **PHP server 自杀** | `backend.php?action=stop` | PHP server 进程被杀，浏览器 100% 拒连 | 检测 `SERVER_PORT=8080` 时返 503 + 提示用 stop.bat |
| **expand-tags 长任务死锁** | `expandStart` | 5 秒后所有 endpoint 都 timeout | spawn 独立 PHP 进程跑 CLI 脚本 |
| **import-all-tags 同问题** | `importAllStart` | 同上 | 同上 |
| **fetch_all_images SSE 同问题** | `fetchAllImages` | 同上 | 同上 |

**为什么会死锁？**
PHP built-in server 是**单线程**的。任何 `set_time_limit(0) + while 循环` 在请求线程里跑都会阻塞 server，**之后所有请求都排队等它结束**——但它可能在跑几小时，server 看起来就像"死了"。

**永久修复**：
- 把 `expand-tags.php` / `import-all-tags.php` / `fetch_all_images.php` 的"长任务部分"拆到 `tools/expand_tags_cli.php` / `tools/fetch_all_images_cli.php`
- web 端收到请求后**立即**用 vbs 包裹 spawn 独立 PHP 进程
- HTTP 响应 200 立即返回（< 100ms），独立进程在后台跑几小时也没事

### 🔧 其他 bug

- 拆解 `1.6::tag` 权重剥不动 → TagClassifier 加正则
- danbooru.php 翻译方向写死 → 自动判方向
- decompose.php lookup miss 不兜底 → en_guess 兜底
- tags.php translate_one 三个 bug（POST body 解析 + 中文拒绝 + 中文流程）
- Translator::zhToEn 无 AI 兜底 → 加 AI 兜底
- artists.php danbooru_search 失败 → 降级 local_fallback
- NaiApi 代理判断不准 → `endpoint()` 方法区分 HTTP 代理 vs 镜像
- API key 走 `where php` 找 php 路径（v1.1.3 装上 XAMPP 后才需要）→ v1.1.4 runtime\ 自带不依赖

---

## 📦 完整变更清单

### 新增
- `runtime\php\php.exe` + 22 个 DLL（39 MB PHP 8.2.12 NTS x64）
- `runtime\php\php.ini`（精简 + curl.cainfo + openssl.cafile）
- `runtime\php\extras\ssl\cacert.pem`（189KB）
- `tools\expand_tags_cli.php`（CLI 版）
- `tools\fetch_all_images_cli.php`（CLI 版）
- `translate-server\` 整个目录（本地翻译服务 + NLLB 模型 + 下载脚本）
- 11 个 schema（001-010，**全部已在 v1.1.0 之前就位**）
- DB 迁移 011：`settings.translate_source` + `local_translate_url` + `local_translate_enabled`

### 修改
- `public/api/backend.php` — 加自杀防护
- `public/api/admin/expand-tags.php` — 改 detached 模式
- `public/api/admin/import-all-tags.php` — 改 detached 模式
- `public/api/admin/fetch_all_images.php` — 改 detached 模式
- `public/api/danbooru.php` — translate 自动判方向
- `public/api/decompose.php` — lookup 加 en_guess 兜底
- `public/api/tags.php` — translate_one 3 bug 修
- `src/lib/Translator.php` — zhToEn 加 AI 兜底
- `src/lib/TagClassifier.php` — 拆解 N::tag 剥权重
- `src/lib/Settings.php` — getTranslateSource + shouldTryLocal + shouldFallbackToOnline
- `src/lib/NaiApi.php` — endpoint() + isHttpProxy()
- `src/config.php` — user-data 模式 + auto mkdir
- `public/router.php` — /storage/* 优先 user-data
- `public/index.php` — 翻译源改 select
- `public/assets/js/settings.js` — translateSource 元素
- `tools\start.bat` — 5 步启动 + CRLF + goto+label
- `tools\stop.bat` — taskkill 杀 8080
- `tools\php_server.cmd` — **删除**（被 vbs 方案替代）
- `README.md` — 删 Apache/XAMPP 6 处历史残留，主打「零依赖便携版」
- `CHANGELOG.md` — 补 1.1.3 + 1.1.4 完整条目
- `.gitignore` — track `data/schema_sqlite.sql` + `translate-server/` 源码（忽略 .model-cache / node_modules / err*.log / out*.log）；删 stray `Stop`/`enter` 0字节文件

### 没改
- `src/lib\Db.php`（双 driver 早已就位）
- `src\lib\AiProvider.php` / `AiAdvisor.php` / `DeepSeekHelper.php`（v1.1.0 完善）
- 所有 `public/assets/js\*.js`（前端 UI 0 改动，本版本专注后端稳定性）

---

## ⚙️ 性能参考（v1.1.4 实测）

| 场景 | 时间 | 备注 |
|---|---|---|
| start.bat 启动 | 4-5s | 5 步：user-data 检查 + DB 检查 + 清端口 + 后台启动 + 等就绪 |
| 翻译首次（NLLB） | 5-6s | 模型加载 5s ready |
| 翻译单条 | 1-1.5s | NLLB CPU 模式 |
| AI 写提示词 | 4-6s | DeepSeek V4-Flash / V3.5 |
| 翻译源 fallback 链路 | 0.5-3s | 本地 1.5s → AI 5s → 在线 0.5s |
| 单张生图 | 5-15s | 取决于 NAI 服务，5xx 自动重试 2 次 |
| 队列 4 张 | 30-60s | 串行，后台跑可关浏览器 |
| 批量扩充标签（50 页） | 5-15min | detached 进程，跑完不阻塞 server |

---

## 🆘 出问题怎么办

| 现象 | 原因 | 修法 |
|---|---|---|
| `start.bat` 卡 "Cleaning port 8080" | 上次没正常退出 | 跑 `stop.bat` 然后再 `start.bat` |
| 浏览器 100% 拒连 | PHP server 挂了 | 看 `user-data\logs\php-server.log` 末尾 |
| 标签翻译不出来 | 没装 NLLB 模型 | 跑 `translate-server\download_nllb.ps1`（874MB） |
| AI 写提示词失败 | DeepSeek key 没配 | 设置页 → AI 配置 → 填 key |
| NAI 一直 500 | 镜像挂了 / 没代理 | 设置页 → 代理 → 填 `http://127.0.0.1:7890`（Clash）或 `10809`（v2rayN） |
| MySQL 误启占 3306 | 历史残留 | 关掉 MySQL 服务即可，NAI Studio 用 SQLite 不需要 |
| 想完全卸载 | 直接删整个 `D:\anima\nai-studio\` 目录 | `user-data\` 也在里面，备份好再删 |

---

## 📋 系统要求

- Windows 10/11 64-bit
- 浏览器：Chrome / Edge / Firefox（任意现代浏览器）
- 网络：能访问 novelai.net（出图）/ danbooru.donmai.us（标签）/ api.deepseek.com（AI，可选）
- 磁盘：项目 200MB + 翻译模型 874MB（可选，不装也能用在线翻译）
- **不需要**装 PHP / Apache / MySQL / Composer / Node.js

---

## 🔗 相关链接

- **README**（功能总览）：[README.md](README.md)
- **CHANGELOG**（完整变更日志）：[CHANGELOG.md](CHANGELOG.md)
- **LICENSE**：MIT
- **GitHub**：`https://github.com/ywclgl258/nai-studio-local`

---

**v1.1.4 制作花絮**：
- 修死锁 bug 的过程：audit 端点时发现 `expandStart` 5 秒后所有 endpoint 都死 → 意识到 PHP built-in server 是单线程 → 把长任务全部拆 detached 进程
- 修 curl.cainfo 的过程：发现所有 HTTPS 调外部 API 都返 SSL 错 → 下 Mozilla CA bundle 配 php.ini → 所有外部 API 突然通
- 写 portable 的过程：用户原话"runtime\php 要精简，XAMPP 55MB 太重" → 留 NTS 删 intl/icu/pgsql/ldap/odbc/odbc/sockets 等 → 39MB

