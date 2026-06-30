# NAI Studio - 本地翻译服务

纯 Node.js + Transformers.js + OPUS-MT en→zh 模型。
**不需要 Docker，不需要 Python，只需要 Node.js 18+**

## 启动

### 方法 1：双击 `start.bat`（最简单）

第一次双击会：
1. 检测 Node.js
2. `npm install` 装依赖（~200MB，含 Transformers.js）
3. 启动后台服务
4. 自动打开浏览器到 `http://127.0.0.1:5555`

服务启动后**首次访问**会从 HuggingFace 下载模型：
- OPUS-MT en→zh: ~300MB
- 1-3 分钟下载时间（看网速）
- 之后启动会快很多（模型已缓存到 `.model-cache/`）

### 方法 2：命令行

```bash
cd D:\anima\nai-studio\translate-server
npm install      # 首次
npm start        # 启动
```

## 在 NAI Studio 中使用

1. 启动翻译服务（`start.bat`）
2. 打开 nai-studio 主站
3. 顶栏 → **设置** → 左侧 **网络** tab
4. 滚到 "本地翻译" 区：
   - 启用本地翻译：✅
   - 服务地址：`http://127.0.0.1:5555`
   - 点 "测试连接"
   - 应该看到 `✓ 本地翻译可用：长发 蓝眼`
5. 保存
6. 顶栏 → **拆解** → 拆解时未翻译的 tag 会自动走本地服务

## 端口

固定 `5555`。改环境变量 `PORT=6666 npm start` 即可。

## 性能

- CPU 推理：~50-200ms/单句
- GPU 推理（启用 USE_GPU=1，需 DirectML/CUDA）：~10-50ms
- 内存：~1.5GB（模型常驻）
- 磁盘：~500MB（依赖 + 模型缓存）

## 模型切换

编辑 `server.js` 顶部的 `MODEL`：

```js
const MODEL = 'Xenova/opus-mt-en-zh';           // 英文 → 简体中文（默认）
const MODEL = 'Xenova/opus-mt-en-zh-v2';       // v2 改进版（更大稍慢）
const MODEL = 'Xenova/nllb-200-distilled-600M'; // 多语言，更大更准
```

改完重启 `start.bat`。

## 常见问题

**Q: 启动时一直卡在 "下载模型"**
A: 首次要从 huggingface.co 下载 ~300MB，取决于网速可能要 1-3 分钟。可以设代理：
`HTTPS_PROXY=http://127.0.0.1:7890 npm start`

**Q: 报错 "model not found"**
A: 模型名可能拼错。检查 `server.js` 的 MODEL 变量。

**Q: 想换端口**
A: 环境变量 `PORT=6666 npm start` 或编辑 start.bat 里的 `set PORT=...`

**Q: 想完全卸载**
A: 整个 `translate-server` 文件夹删掉即可，无注册表残留。

**Q: 占用太多内存**
A: CPU 跑 OPUS-MT 需要 ~1.5GB 内存。换更小的模型：编辑 `server.js`，但准确度会下降。

## 目录结构

```
translate-server/
├── server.js         # 主服务（Express + Transformers.js）
├── package.json      # 依赖
├── start.bat         # Windows 启动脚本
├── stop.bat          # Windows 停止脚本
└── .model-cache/     # 模型缓存（自动创建）
```

## API 端点

完全兼容 LibreTranslate 协议：

- `GET /` - 健康检查
- `POST /translate` body `{q, source:'en', target:'zh', format:'text'}` - 单句翻译
- `POST /translate_one` body `{text}` - NAI Studio 简化的单 tag
- `POST /translate_batch` body `{texts: [...]}` - 批量翻译
- `POST /warmup` - 后台预热模型

`POST /translate` 响应格式：
```json
{
  "translatedText": "长发",
  "detectedLanguage": {"language": "en", "confidence": 1},
  "source": "en",
  "target": "zh",
  "model": "Xenova/opus-mt-en-zh",
  "ms": 87
}
```
