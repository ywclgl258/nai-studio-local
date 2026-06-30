# 修复 OPUS-MT 模型 cache 结构
# transformers.js 期望：
#   refs/main           = 40 字符 commit hash
#   snapshots/{hash}/   = 实际文件目录

$commit = "046f55aec303cdee3e0318604406d4df20f1e8ea"
$cache  = "D:\anima\nai-studio\translate-server\.model-cache"
$refs   = "$cache\models--Xenova--opus-mt-en-zh\refs\main"
$oldSnap = "$cache\models--Xenova--opus-mt-en-zh\snapshots\main"
$newSnap = "$cache\models--Xenova--opus-mt-en-zh\snapshots\$commit"

# 1) 写 commit hash 到 refs/main
Write-Host "Setting refs/main = $commit"
$commit | Out-File -FilePath $refs -Encoding ascii -NoNewline

# 2) 复制/重命名 snapshots/main -> snapshots/{commit}
if (Test-Path $newSnap) {
    Write-Host "snapshots/$commit already exists, skip copy" -ForegroundColor Yellow
} elseif (Test-Path $oldSnap) {
    Write-Host "Copying snapshots/main -> snapshots/$commit ..."
    Copy-Item -Path $oldSnap -Destination $newSnap -Recurse -Force
} else {
    Write-Host "ERROR: snapshots/main missing" -ForegroundColor Red
    exit 1
}

# 3) 删除旧 snapshots/main（避免 transformers.js 误读）
#    但保留原 snapshots/main 作为 fallback 路径
#    → 实际保留：transformers.js 看到 refs/main 是 commit 后会用 snapshots/{hash}
#    → snapshots/main 不再被引用，可以删
#    → 保险起见我们保留

Write-Host ""
Write-Host "Cache structure now:" -ForegroundColor Green
Get-ChildItem "$cache\models--Xenova--opus-mt-en-zh" -Recurse -Depth 2 | Where-Object { !$_.PSIsContainer } | Select-Object FullName, Length | Format-Table -AutoSize
