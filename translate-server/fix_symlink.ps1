# 修误复制的 fp32 名字文件 + 改用软链接到 quantized
$snap = "D:\anima\nai-studio\translate-server\.model-cache\models--Xenova--opus-mt-en-zh\snapshots\046f55aec303cdee3e0318604406d4df20f1e8ea\onnx"

Write-Host "=== Before cleanup ==="
Get-ChildItem $snap | Where-Object { !$_.PSIsContainer } | Select-Object Name, Length | Format-Table -AutoSize

# 1) Delete wrongly-copied fp32 files (the actual quantized file is still there)
$wrong = @('encoder_model.onnx', 'decoder_model.onnx', 'decoder_model_merged.onnx')
foreach ($f in $wrong) {
    $p = Join-Path $snap $f
    if (Test-Path $p) {
        $it = Get-Item $p
        if (!$it.LinkType) {
            # Real file, not symlink
            Write-Host "Deleting real file: $f"
            Remove-Item $p -Force
        }
    }
}

# 2) Try mklink (Windows symbolic link)
#    Need: admin or developer mode enabled
#    cmd.exe mklink (no /D) = file symlink
$srcPairs = @(
    @{src = 'encoder_model_quantized.onnx';       dst = 'encoder_model.onnx'},
    @{src = 'decoder_model_merged_quantized.onnx'; dst = 'decoder_model_merged.onnx'}
)
foreach ($p in $srcPairs) {
    $src = Join-Path $snap $p.src
    $dst = Join-Path $snap $p.dst
    if (-not (Test-Path $dst)) {
        Write-Host "mklink: $((Split-Path $dst -Leaf)) -> $((Split-Path $src -Leaf))"
        $output = cmd /c "mklink `"$dst`" `"$((Split-Path $src -Leaf))`"" 2>&1
        Write-Host $output
    } else {
        Write-Host "skip  (exists): $((Split-Path $dst -Leaf))"
    }
}

Write-Host ""
Write-Host "=== After ==="
Get-ChildItem $snap | Where-Object { !$_.PSIsContainer } | Select-Object Name, Length, LinkType | Format-Table -AutoSize
