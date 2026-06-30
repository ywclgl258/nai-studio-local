# Download NLLB-200 distilled 600M quantized.
# Total ~853MB. transformers.js will use these when dtype='q8'.

$proxy  = "http://127.0.0.1:7897"
$base   = "https://huggingface.co/Xenova/nllb-200-distilled-600M/resolve/main"
$commit = "261c31d1a5732c67cdd16d80e8d6088507c7ccea"
$snap   = "D:\anima\nai-studio\translate-server\.model-cache\models--Xenova--nllb-200-distilled-600M\snapshots\$commit"

# Config files
$configFiles = @(
    "config.json",
    "generation_config.json",
    "quantize_config.json",
    "sentencepiece.bpe.model",
    "special_tokens_map.json",
    "tokenizer.json",
    "tokenizer_config.json"
)

# ONNX quantized
$onnxFiles = @(
    "onnx/encoder_model_quantized.onnx",          # 400 MB
    "onnx/decoder_model_merged_quantized.onnx"    # 453 MB
)

New-Item -ItemType Directory -Force -Path "$snap\onnx" | Out-Null
New-Item -ItemType Directory -Force -Path "$snap\refs" | Out-Null
$commit | Out-File -FilePath "$snap\refs\main" -Encoding ascii -NoNewline

# Use curl (not PowerShell Invoke-WebRequest) for LFS 302 redirect reliability
$curl = "curl.exe"

function Download($url, $out) {
    if (Test-Path $out) {
        $skip = $true
        Write-Host "  skip  $([System.IO.Path]::GetFileName($out))" -ForegroundColor Yellow
    } else {
        $skip = $false
        Write-Host "  fetch $([System.IO.Path]::GetFileName($out)) ..." -NoNewline
        & $curl -L -s -o "$out" "$url" 2>&1 | Out-Null
        if ($LASTEXITCODE -eq 0 -and (Test-Path $out)) {
            $sz = (Get-Item $out).Length
            Write-Host " OK ($([math]::Round($sz/1024/1024, 1)) MB)" -ForegroundColor Green
        } else {
            Write-Host " FAIL" -ForegroundColor Red
        }
    }
}

Write-Host "=== Config + tokenizer files ===" -ForegroundColor Cyan
foreach ($f in $configFiles) {
    Download "$base/$f" (Join-Path $snap $f)
}

Write-Host ""
Write-Host "=== ONNX quantized (~853 MB) ===" -ForegroundColor Cyan
foreach ($f in $onnxFiles) {
    Download "$base/$f" (Join-Path $snap $f)
}

Write-Host ""
$total = (Get-ChildItem $snap -Recurse -File | Measure-Object Length -Sum).Sum
Write-Host "Done. Total: $([math]::Round($total/1024/1024, 1)) MB" -ForegroundColor Green
Get-ChildItem $snap | Where-Object { !$_.PSIsContainer } | Select-Object Name, Length | Format-Table -AutoSize
