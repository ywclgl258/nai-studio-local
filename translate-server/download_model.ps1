# 手动下载 OPUS-MT 模型（走 7897 代理）
# v1.1.4+ 修：Xenova/opus-mt-en-zh 是 encoder-decoder 架构
#   旧清单（onnx/model.onnx + model_quantized.onnx）= 不存在
#   正确清单：onnx/{encoder,decoder,decoder_with_past}_model.onnx
# 用法：PowerShell 跑这个脚本

$proxy = "http://127.0.0.1:7897"
$base  = "https://huggingface.co/Xenova/opus-mt-en-zh/resolve/main"
$dst   = "D:\anima\nai-studio\translate-server\.model-cache"

# HF 标准 cache 格式
$snapshotDir = "$dst\models--Xenova--opus-mt-en-zh\snapshots\main"
New-Item -ItemType Directory -Force -Path $snapshotDir | Out-Null
New-Item -ItemType Directory -Force -Path "$snapshotDir\onnx" | Out-Null
New-Item -ItemType Directory -Force -Path "$dst\models--Xenova--opus-mt-en-zh\refs" | Out-Null
"main" | Out-File -FilePath "$dst\models--Xenova--opus-mt-en-zh\refs\main" -Encoding ascii

# 配置文件 + token
$configFiles = @(
    "config.json",
    "generation_config.json",
    "quantize_config.json",
    "source.spm",
    "target.spm",
    "tokenizer.json",
    "tokenizer_config.json",
    "vocab.json",
    "special_tokens_map.json"
)

# onnx 模型：encoder-decoder 架构需要这 3 个
# 用 fp32 默认版（transformers.js 默认加载，~670MB 总量）
# 如果想小：换成 *_quantized 变体，~170MB
$onnxFiles = @(
    "onnx/encoder_model.onnx",            # 210 MB
    "onnx/decoder_model.onnx",            # 236 MB
    "onnx/decoder_with_past_model.onnx"   # 223 MB
)

# 量化版（CPU 友好，~170MB）—— 取消注释用这 3 个替换上面 3 个
# $onnxFiles = @(
#     "onnx/encoder_model_quantized.onnx",          # 53 MB
#     "onnx/decoder_model_quantized.onnx",          # 60 MB
#     "onnx/decoder_with_past_model_quantized.onnx" # 57 MB
# )

function Download-File($url, $out) {
    if (Test-Path $out) {
        Write-Host "  skip  $([System.IO.Path]::GetFileName($out))" -ForegroundColor Yellow
        return
    }
    $name = Split-Path $out -Leaf
    Write-Host "  fetch $name ..." -NoNewline
    try {
        Invoke-WebRequest -Uri $url -OutFile $out -Proxy $proxy -UseBasicParsing
        $size = (Get-Item $out).Length
        Write-Host " OK ($([math]::Round($size/1024/1024, 1)) MB)" -ForegroundColor Green
    } catch {
        Write-Host " FAIL: $_" -ForegroundColor Red
    }
}

Write-Host "=== Config + tokenizer files ===" -ForegroundColor Cyan
foreach ($f in $configFiles) {
    Download-File "$base/$f" (Join-Path $snapshotDir $f)
}

Write-Host ""
Write-Host "=== ONNX models (large) ===" -ForegroundColor Cyan
foreach ($f in $onnxFiles) {
    Download-File "$base/$f" (Join-Path $snapshotDir $f)
}

Write-Host ""
Write-Host "=== Done ===" -ForegroundColor Green
$total = (Get-ChildItem $snapshotDir -Recurse | Measure-Object Length -Sum).Sum
Write-Host ("Total: {0:N1} MB" -f ($total/1024/1024))
Write-Host "Path:  $snapshotDir"
