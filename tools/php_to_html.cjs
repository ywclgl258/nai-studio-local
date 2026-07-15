// Convert PHP index.php to static index.html
// Strip <?php ... ?> blocks, replace <?= expr ?> with default values

const fs = require('fs');
const path = require('path');

const SRC = 'D:\\anima\\nai-studio\\public\\index.php';
const DST = 'D:\\anima\\nai-studio-desktop\\src\\index.html';

let text = fs.readFileSync(SRC, 'utf8');

// 1) Strip <?php ... ?> blocks (multi-line, greedy across lines until ?>)
text = text.replace(/<\?php[\s\S]*?\?>/g, '');

// 2) Replace <?= expr ?> with empty or default values for known expressions
const defaults = {
    "htmlspecialchars($defaultSettings['theme'])": "dark",
    "filemtime(__DIR__ . '/assets/css/main.css')": "100",
    "filemtime(__DIR__ . '/assets/css/components.css')": "100",
    "filemtime(__DIR__ . '/assets/css/tag-picker.css')": "100",
    "filemtime(__DIR__ . '/assets/js/app.js')": "100",
    "filemtime(__DIR__ . '/assets/js/api.js')": "100",
    "filemtime(__DIR__ . '/assets/js/gallery.js')": "100",
    "filemtime(__DIR__ . '/assets/js/actions.js')": "100",
    "filemtime(__DIR__ . '/assets/js/upscale.js')": "100",
    "filemtime(__DIR__ . '/assets/js/upscale-page.js')": "100",
    "filemtime(__DIR__ . '/assets/js/preset-modal.js')": "100",
};
for (const [k, v] of Object.entries(defaults)) {
    const re = new RegExp('<\\?=\\s*' + k.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\s*\\?>', 'g');
    text = text.replace(re, v);
}

// 3) Any remaining <?= ... ?>  — replace with empty
text = text.replace(/<\?=[\s\S]*?\?>/g, '');

// 4) Replace any remaining <? ... ?>  with empty
text = text.replace(/<\?[\s\S]*?\?>/g, '');

fs.writeFileSync(DST, text, 'utf8');
console.log(`Written ${DST} (${text.length} bytes)`);
