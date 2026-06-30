# Download OPUS-MT quantized variants.
# transformers.js needs decoder_model_merged.onnx (or *_quantized.onnx).

$proxy  = "http://127.0.0.1:7897"
$base   = "https://huggingface.co/Xenova/opus-mt-en-zh/resolve/main"
$commit = "046f55aec303cdee3e0318604406d4df20f1e8ea"
$snap   = "D:\anima\nai-studio\translate-server\.model-cache\models--Xenova--opus-mt-en-zh\snapshots\$commit"

$files = @(
    "onnx/encoder_model_quantized.onnx",
    "onnx/decoder_model_merged_quantized.onnx"
)

New-Item -ItemType Directory -Force -Path "$snap\onnx" | Out-Null

foreach ($f in $files) {
    $out = Join-Path $snap $f
    if (Test-Path $out) {
        Write-Host "  skip  $([System.IO.Path]::GetFileName($f))" -ForegroundColor Yellow
        continue
    }
    $url = "$base/$f"
    Write-Host "  fetch $f ..." -NoNewline
    try {
        Invoke-WebRequest -Uri $url -OutFile $out -Proxy $proxy -UseBasicParsing
        $size = (Get-Item $out).Length
        Write-Host " OK ($([math]::Round($size/1024/1024, 1)) MB)" -ForegroundColor Green
    } catch {
        Write-Host " FAIL: $_" -ForegroundColor Red
    }
}

Write-Host ""
Write-Host "Done. server.js will auto-detect quantized files and use them." -ForegroundColor Green
