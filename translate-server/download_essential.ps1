# Download ONLY what transformers.js 3.8.1 needs for OPUS-MT.
# Hardcoded file names: encoder_model.onnx + decoder_model_merged.onnx
# Total: ~445 MB (fp32, the only format transformers.js can load locally)

$proxy  = "http://127.0.0.1:7897"
$base   = "https://huggingface.co/Xenova/opus-mt-en-zh/resolve/main"
$commit = "046f55aec303cdee3e0318604406d4df20f1e8ea"
$snap   = "D:\anima\nai-studio\translate-server\.model-cache\models--Xenova--opus-mt-en-zh\snapshots\$commit\onnx"

# These are the ONLY 2 files transformers.js will look for
$files = @(
    "encoder_model.onnx",          # 210 MB - the encoder
    "decoder_model_merged.onnx"    # 235 MB - the decoder (with past merged in)
)

# Total: ~445 MB

New-Item -ItemType Directory -Force -Path $snap | Out-Null

foreach ($f in $files) {
    $out = Join-Path $snap $f
    if (Test-Path $out) {
        Write-Host "  skip  $f" -ForegroundColor Yellow
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

# Also clean up the mistakenly-copied _quantized files (we're using fp32 now)
$cleanup = @(
    "$snap\encoder_model_quantized.onnx",
    "$snap\decoder_model_merged_quantized.onnx",
    "$snap\decoder_with_past_model.onnx"
)
foreach ($p in $cleanup) {
    if (Test-Path $p) {
        $sz = (Get-Item $p).Length
        Write-Host "  cleanup  $([System.IO.Path]::GetFileName($p)) ($([math]::Round($sz/1024/1024, 1)) MB)" -ForegroundColor DarkGray
        Remove-Item $p -Force
    }
}

Write-Host ""
$total = (Get-ChildItem $snap -Recurse -File | Measure-Object Length -Sum).Sum
Write-Host "Done. onnx/ total: $([math]::Round($total/1024/1024, 1)) MB" -ForegroundColor Green
Get-ChildItem $snap | Where-Object { !$_.PSIsContainer } | Select-Object Name, Length | Format-Table -AutoSize
