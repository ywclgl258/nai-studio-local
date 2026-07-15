// 把前端 JS 里的硬编码 /nai-studio/api/... 改成 /api/...
// 因为 Tauri 加载 http://127.0.0.1:PORT/ 从根，没有 /nai-studio/ 前缀

const fs = require('fs');
const path = require('path');

const SRC_DIR = 'D:\\anima\\nai-studio-desktop\\src\\assets\\js';

function walk(dir) {
    const out = [];
    for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, e.name);
        if (e.isDirectory()) out.push(...walk(full));
        else if (e.name.endsWith('.js')) out.push(full);
    }
    return out;
}

let total = 0;
for (const f of walk(SRC_DIR)) {
    let text = fs.readFileSync(f, 'utf8');
    const before = text;
    text = text.replace(/\/nai-studio\/api\//g, '/api/');
    // 同时去掉 api.js 里的 fetchImgStart 硬编码
    text = text.replace(/`\/nai-studio\/api\//g, '`/api/');
    if (text !== before) {
        fs.writeFileSync(f, text, 'utf8');
        console.log('Fixed:', path.basename(f));
        total++;
    }
}
console.log(`Total: ${total} files`);
