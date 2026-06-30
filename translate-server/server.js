/**
 * NAI Studio - Local translation server
 *
 * 纯 Node.js + @huggingface/transformers + Helsinki-NLP/opus-mt-en-zh
 * API 完全兼容 LibreTranslate 协议：POST /translate {q, source, target, format}
 *
 * 优势：
 *   - 无 Docker / 无 Python
 *   - 模型本地缓存（首次下载 ~300MB，之后秒起）
 *   - 端口固定 5555
 *   - 自动 GPU 检测（CUDA/ROCm/DirectML），有 GPU 时翻译 < 50ms
 *
 * 启动：npm install  →  npm start
 */

import express from 'express';
import { pipeline, env } from '@huggingface/transformers';
import path from 'path';
import { fileURLToPath } from 'url';
import os from 'os';
import fs from 'fs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const PORT = parseInt(process.env.PORT || '5555', 10);
const MODEL = 'Xenova/nllb-200-distilled-600M';   // 200+ 语言双向
// 也可换 'Xenova/opus-mt-en-zh'（en→zh 单向，更专精）

// 模型缓存目录：放本地，避免污染用户目录
const cacheDir = path.join(__dirname, '.model-cache');
if (!fs.existsSync(cacheDir)) fs.mkdirSync(cacheDir, { recursive: true });
env.cacheDir = cacheDir;
env.allowLocalModels = true;
env.allowRemoteModels = false;  // 关键：禁止远程下载，否则每次启动都连不上 huggingface.co
env.useFs = true;
// 用 commit hash 直接定位 snapshot（避免 refs/main 解析失败时回退远程）
const HF_COMMIT = '261c31d1a5732c67cdd16d80e8d6088507c7ccea';
const LOCAL_MODEL_PATH = path.join(cacheDir, 'models--Xenova--nllb-200-distilled-600M', 'snapshots', HF_COMMIT);

// 检测 GPU（CUDA / ROCm / DirectML）
let device = 'cpu';
if (process.env.USE_GPU === '1') {
    if (process.env.CUDA_VISIBLE_DEVICES !== undefined) device = 'cuda';
    else if (process.platform === 'win32') device = 'webgpu';   // DirectML via WebGPU
    else device = 'wasm';
}

console.log('============================================================');
console.log('  NAI Studio - Local Translate Server');
console.log('============================================================');
console.log(`  Model:    ${MODEL}`);
console.log(`  Device:   ${device}  (CPU 慢但稳，GPU 需 USE_GPU=1)`);
console.log(`  Port:     ${PORT}`);
console.log(`  Cache:    ${cacheDir}`);
console.log(`  Platform: ${os.platform()} ${os.arch()} / Node ${process.version}`);
console.log('------------------------------------------------------------');
console.log('  首次启动会下载模型 ~300MB，请耐心等待...');
console.log('  之后启动会快很多（模型已缓存）');
console.log('============================================================');

let translator = null;
let modelLoading = null;
let modelLoadTime = 0;

async function loadModel() {
    if (translator) return translator;
    if (modelLoading) return modelLoading;

    const t0 = Date.now();
    console.log(`[${new Date().toLocaleTimeString()}] 加载模型 ${MODEL} ...`);

    modelLoading = (async () => {
        try {
            // 优先用本地路径（不用 model ID，避免 transformers.js fetch 远程 metadata）
            const modelArg = fs.existsSync(LOCAL_MODEL_PATH) ? LOCAL_MODEL_PATH : MODEL;
            console.log(`  using local path: ${modelArg}`);
            // 自动检测：有 quantized 文件就用 dtype='q8'，否则 fp32
            const hasQuantizedMerged = fs.existsSync(path.join(LOCAL_MODEL_PATH, 'onnx', 'decoder_model_merged_quantized.onnx'));
            const dtype = hasQuantizedMerged ? 'q8' : 'fp32';
            console.log(`  dtype: ${dtype} (${dtype === 'q8' ? 'small ~850MB, CPU friendly' : 'big ~3.2GB'})`);
            translator = await pipeline('translation', modelArg, {
                device,
                dtype,  // 'q8' = quantized, 'fp32' = full precision
                // 进度回调
                progress_callback: (data) => {
                    if (data.status === 'progress' && data.progress) {
                        process.stdout.write(`\r  loading ${data.file || 'model'}: ${data.progress.toFixed(0)}%   `);
                    } else if (data.status === 'done') {
                        console.log(`\n  done ${data.file || 'file'}`);
                    } else if (data.status === 'ready') {
                        console.log('  ready');
                    }
                },
            });
            modelLoadTime = Date.now() - t0;
            console.log(`[${new Date().toLocaleTimeString()}] 模型加载完成（耗时 ${(modelLoadTime/1000).toFixed(1)}s）`);
            return translator;
        } catch (e) {
            console.error('模型加载失败:', e.message);
            console.error('完整错误:', e);
            if (e.cause) console.error('cause:', e.cause);
            if (e.stack) console.error('stack:', e.stack.split('\n').slice(0, 8).join('\n'));
            console.error('可能原因：');
            console.error('  1. 网络问题（无法从 huggingface.co 下载）');
            console.error('  2. Node 版本太低（需要 >= 18）');
            console.error('  3. 磁盘空间不足');
            console.error('  4. SSL/TLS 证书问题');
            console.error('  5. 需要代理（设 HTTPS_PROXY=http://127.0.0.1:7890）');
            throw e;
        }
    })();

    return modelLoading;
}

// ===== HTTP server =====
const app = express();
app.use(express.json({ limit: '1mb' }));

// 限制最大 body 解析时间
app.use((req, res, next) => {
    res.setTimeout(30000, () => {
        res.status(504).json({ error: 'Translation timeout' });
    });
    next();
});

// 健康检查
app.get('/', (req, res) => {
    res.json({
        name: 'nai-translate-server',
        model: MODEL,
        device,
        ready: !!translator,
        uptime: process.uptime(),
        endpoint: 'POST /translate {q, source, target, format}',
    });
});

// NLLB-200 语言代码映射 (ISO 639-1 / BCP 47 -> NLLB flores200 codes)
const LANG_MAP = {
    'en': 'eng_Latn', 'zh': 'zho_Hans', 'zh-CN': 'zho_Hans', 'zh-Hans': 'zho_Hans', 'zh-Hant': 'zho_Hant',
    'ja': 'jpn_Jpan', 'ko': 'kor_Hang',
    'fr': 'fra_Latn', 'de': 'deu_Latn', 'es': 'spa_Latn', 'pt': 'por_Latn', 'it': 'ita_Latn',
    'ru': 'rus_Cyrl',
};
function toNllbCode(lang) {
    if (!lang) return null;
    if (LANG_MAP[lang]) return LANG_MAP[lang];
    // 已经是 NLLB 格式（xxx_Yyyy）
    if (/^[a-z]{3}_[A-Z][a-z]{3}$/.test(lang)) return lang;
    return null;
}

// 兼容 LibreTranslate 的 /translate 端点
app.post('/translate', async (req, res) => {
    const t0 = Date.now();
    const { q, source = 'en', target = 'zh', format = 'text' } = req.body || {};
    if (!q || typeof q !== 'string') {
        return res.status(400).json({ error: 'q (text) required' });
    }
    const srcLang = toNllbCode(source);
    const tgtLang = toNllbCode(target);
    if (!srcLang || !tgtLang) {
        return res.status(400).json({
            error: `Unsupported language: source=${source} target=${target}. NLLB supports en, zh, ja, ko, fr, de, es, pt, it, ru.`,
        });
    }

    try {
        if (!translator) await loadModel();

        // NLLB 推理
        const result = await translator(q, {
            src_lang: srcLang,
            tgt_lang: tgtLang,
            max_length: 128,
            num_beams: 1,     // 贪心解码，tag 翻译够用
            temperature: 1.0,
        });

        const translatedText = (result[0]?.translation_text || '').trim();
        const ms = Date.now() - t0;

        // LibreTranslate 兼容返回格式
        res.json({
            translatedText,
            detectedLanguage: { language: source, confidence: 1 },
            // 额外字段
            source,
            target,
            model: MODEL,
            ms,
        });
        console.log(`[${new Date().toLocaleTimeString()}] ${source}→${target} ${q.slice(0,30)} -> ${translatedText.slice(0,30)} (${ms}ms)`);
    } catch (e) {
        console.error('translate failed:', e.message);
        res.status(500).json({ error: e.message });
    }
});

// 单 tag 翻译（NAI Studio decompose 用）
app.post('/translate_one', async (req, res) => {
    const { text } = req.body || {};
    if (!text) return res.status(400).json({ error: 'text required' });
    try {
        if (!translator) await loadModel();
        const r = await translator(text, { max_length: 128, num_beams: 1 });
        res.json({ text, cn: r[0]?.translation_text?.trim() || text });
    } catch (e) {
        res.status(500).json({ error: e.message });
    }
});

// 批量翻译（NAI Studio 一次性补译多个 tag 时用，省往返）
app.post('/translate_batch', async (req, res) => {
    const { texts } = req.body || {};
    if (!Array.isArray(texts)) return res.status(400).json({ error: 'texts array required' });
    try {
        if (!translator) await loadModel();
        const out = [];
        // 串行推理（避免 OOM，多线程 ONNX 在小 batch 上反而慢）
        for (const t of texts) {
            if (typeof t !== 'string' || !t.trim()) {
                out.push({ text: t, cn: t });
                continue;
            }
            try {
                const r = await translator(t, { max_length: 128, num_beams: 1 });
                out.push({ text: t, cn: r[0]?.translation_text?.trim() || t });
            } catch (e) {
                out.push({ text: t, cn: t, error: e.message });
            }
        }
        res.json({ results: out });
    } catch (e) {
        res.status(500).json({ error: e.message });
    }
});

// 预热接口（NAI Studio 启动后可以提前调用，让模型在后台加载）
app.post('/warmup', async (req, res) => {
    // 不阻塞响应，模型在后台加载
    loadModel().catch(e => console.error('warmup failed:', e.message));
    res.json({ ok: true, message: 'Model loading in background' });
});

const server = app.listen(PORT, '127.0.0.1', () => {
    console.log(`\n✓ 翻译服务已启动: http://127.0.0.1:${PORT}`);
    console.log(`  NAI Studio 拆解器设置: URL 填 http://127.0.0.1:${PORT}\n`);
    // 后台预热模型
    loadModel().catch(e => console.error('启动预热失败:', e.message));
});

// 优雅退出
process.on('SIGINT', () => {
    console.log('\n正在关闭...');
    server.close(() => process.exit(0));
});
process.on('SIGTERM', () => server.close(() => process.exit(0)));
