<?php
/**
 * NAI Studio - AI Advisor (DeepSeek 驱动)
 *
 * 用 DeepSeek 给 NAI 提示词做"超人类级别"的优化建议：
 *   1. 语义层冲突（不只是风格，还包括属性矛盾）
 *   2. 权重层优化（{tag:1.2} 是不是合适）
 *   3. 冗余检测（功能重复的 tag）
 *   4. 缺失补充（漏了关键质量词 / 角色关键属性）
 *   5. 上下文感知翻译（比 MyMemory 准）
 *   6. 智能 prompt 扩写（简单描述 → 完整 tag 串）
 *
 * 输出：JSON 格式，前端直接渲染
 */

declare(strict_types=1);

namespace NaiStudio;

class AiAdvisor {

    /**
     * 深度分析整个 prompt
     *
     * @param string $prompt 原始 prompt
     * @param array $decomposed Tags 拆解结果（来自 TagClassifier）
     * @param array $artistAdvice 画师建议（来自 ArtistAdvisor）
     * @param bool $useMock 强制走 mock 模式（演示用）
     * @param string $model 'curated' | 'full' | 'auto' — 目标 NAI 模型版本
     * @return array{
     *   summary, score, issues, suggestions, optimized_prompt, stats
     * }
     */
    public static function analyze(string $prompt, array $decomposed, array $artistAdvice, bool $useMock = false, string $model = 'auto'): array {
        // 没配 AI → 走启发式 mock（让用户能看到 UI 长啥样）
        if ($useMock || !AiProvider::isEnabled()) {
            $r = self::mockAnalyze($prompt, $decomposed, $artistAdvice);
            $r['_meta'] = ['model' => $model, 'mock' => true];
            return $r;
        }

        $model = self::normalizeModel($model);
        $system = self::analyzeV4System($model);

        $userData = [
            'target_model'  => strtoupper($model),
            'prompt'        => $prompt,
            'decomposed'    => self::summarizeDecomposed($decomposed),
            'artist_advice' => self::summarizeArtistAdvice($artistAdvice),
        ];
        $user = "目标 NAI 模型：**" . strtoupper($model) . "**\n\n请分析以下提示词：\n\n```\n{$prompt}\n```\n\n"
              . "拆解结果摘要：\n```json\n" . json_encode($userData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n```\n\n"
              . "请按要求的 JSON 结构输出分析。**注意**：针对目标模型特性给建议（如 Curated 严格遵循 Danbooru、Full 容忍自然语言等）。";

        try {
            $r = AiProvider::chatSimple($system, $user, [
                'temperature' => 0.3,
                'max_tokens'  => 3000,
                'json_mode'   => true,
            ]);
            $j = self::parseJson($r['content']);
            $j['_meta'] = [
                'ms'      => $r['ms'],
                'model'   => $r['model'],
                'usage'   => $r['usage'],
                'target'  => $model,
                'content' => $r['content'],   // 原始 JSON 字符串（debug 用）
            ];
            return $j;
        } catch (\Throwable $e) {
            return [
                'summary'  => 'AI 分析失败',
                'score'    => 0,
                'issues'   => [['type' => 'error', 'message' => $e->getMessage(), 'severity' => 'high']],
                'suggestions' => [],
                'optimized_prompt' => null,
                'tags_breakdown' => null,
                'target'   => $model,
                '_error'   => $e->getMessage(),
            ];
        }
    }

    /**
     * AI 翻译单个 tag（上下文感知，比 MyMemory 准）
     *
     * @return string 中文，空表示失败
     */
    public static function translateTag(string $tag): string {
        $system = '你是 Danbooru 标签翻译专家，专门把英文 Danbooru tag 翻译成简短的中文（2-4 字最佳，符合 NAI 圈惯例）。只输出中文，不要解释。';

        try {
            $r = AiProvider::chatSimple($system, $tag, [
                'temperature' => 0.1,
                'max_tokens'  => 30,
            ]);
            $cn = trim($r['content']);
            $cn = preg_replace('/^["\'\s]+|["\'\s]+$/u', '', $cn);
            return $cn !== '' && $cn !== $tag ? $cn : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * AI 批量翻译（一次最多 50 个 tag）
     *
     * @param array $tags ['long_hair', 'blue_eyes', ...]
     * @return array tag => cn
     */
    public static function translateBatch(array $tags): array {
        if (empty($tags)) return [];
        $tags = array_slice(array_unique($tags), 0, 50);

        $system = '你是 Danbooru 标签翻译专家。把英文标签翻译成 2-4 字中文。严格按 JSON 输出：{"tag1":"中文1","tag2":"中文2"}，不解释。';

        $user = json_encode($tags, JSON_UNESCAPED_UNICODE);
        try {
            $r = AiProvider::chatSimple($system, $user, [
                'temperature' => 0.1,
                'max_tokens'  => 1000,
                'json_mode'   => true,
            ]);
            $j = json_decode($r['content'], true);
            return is_array($j) ? $j : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * AI 扩写：把简单描述变成完整 NAI prompt
     *
     * @return string 扩写后的 prompt，失败返回空
     */
    public static function expandPrompt(string $description): string {
        $system = <<<EOT
你是 NAI 提示词工程专家。把用户的简单描述扩写成完整的 NAI 提示词。

要求：
- 输出纯 Danbooru tag 字符串，逗号分隔
- 包含：主体描述（1girl/1boy + 关键特征）、姿势动作、服装、背景、氛围、质量词
- 用 NAI 权重语法：关键 tag 用 {} 提高权重，不想要的用 [] 降低
- 5-15 个 tag 最佳，不要堆砌
- 只输出 prompt，不要任何解释
EOT;
        try {
            $r = AiProvider::chatSimple($system, $description, [
                'temperature' => 0.7,
                'max_tokens'  => 500,
            ]);
            return trim($r['content']);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * AI 写提示词 — 互动式多轮对话，按目标 NAI 模型切换预设
     *
     * 支持多轮对话。用户可以：
     *   - 描述场景/角色 → AI 给完整 NAI prompt
     *   - 追加要求（"再加个樱花背景"）→ AI 调整
     *   - 询问解释（"为什么用 {ciloranko}?"）→ AI 答
     *   - 复制/应用到主提示词
     *
     * @param array $history [['role' => 'user'|'assistant', 'content' => string], ...]
     * @param string $model 'curated' | 'full' | 'auto' — 目标 NAI 模型版本
     *   - curated: V4.5 Curated（默认）— 严格 Danbooru + 偏好 `artist:` 前缀
     *   - full:    V4.5 Full（通用）— 容忍自然语言 + 写实风格更稳
     *   - auto:    根据用户描述自动判断（用通用预设）
     * @return array{reply: string, prompt: ?string, model: string, target: string, ms: int, usage: array}
     *   - reply: 助手的中文回复
     *   - prompt: 助手给出的 NAI prompt（从 ```prompt ... ``` 代码块提取），无则 null
     *   - target: 实际用的目标模型 ('curated'|'full'|'auto')
     */
    public static function composePrompt(array $history, string $model = 'curated'): array {
        if (!AiProvider::isEnabled()) {
            return [
                'reply'  => '⚠️ AI 未启用。请到「设置 → AI 顾问」配置 API key 并启用。',
                'prompt' => null,
                'model'  => null,
                'target' => self::normalizeModel($model),
                'ms'     => 0,
                'usage'  => [],
            ];
        }

        $model = self::normalizeModel($model);
        $system = $model === 'full'
            ? self::composePromptFullSystem()
            : ($model === 'curated' ? self::composePromptCuratedSystem() : self::composePromptAutoSystem());

        $messages = [['role' => 'system', 'content' => $system]];
        foreach ($history as $m) {
            if (!in_array($m['role'] ?? '', ['user', 'assistant'])) continue;
            $messages[] = ['role' => $m['role'], 'content' => (string)($m['content'] ?? '')];
        }

        try {
            $r = AiProvider::chat($messages, [
                'temperature' => 0.8,
                'max_tokens'  => 1500,
            ]);
            $content = $r['content'];
            // 提取 ```prompt ... ``` 代码块
            $extracted = null;
            if (preg_match('/```prompt\s*([\s\S]*?)```/i', $content, $m)) {
                $extracted = trim($m[1]);
            }
            return [
                'reply'  => $content,
                'prompt' => $extracted,
                'model'  => $r['model'],
                'target' => $model,
                'ms'     => $r['ms'],
                'usage'  => $r['usage'],
            ];
        } catch (\Throwable $e) {
            return [
                'reply'  => '❌ 出错了：' . $e->getMessage(),
                'prompt' => null,
                'model'  => null,
                'target' => $model,
                'ms'     => 0,
                'usage'  => [],
            ];
        }
    }

    /**
     * 兼容旧接口（旧版 composePromptV4）
     */
    public static function composePromptV4(array $history): array {
        return self::composePrompt($history, 'curated');
    }

    /**
     * 标准化 model 参数
     */
    private static function normalizeModel(string $model): string {
        $m = strtolower(trim($model));
        if ($m === 'nai-v4.5-curated' || $m === 'nai_diffusion_v4_5_curated' || $m === 'curated') return 'curated';
        if ($m === 'nai-v4.5-full' || $m === 'nai_diffusion_v4_5_full' || $m === 'full') return 'full';
        return 'auto';
    }

    /**
     * AI 写提示词 V4 — 系统预设 prompt（专业版，针对 DeepSeek V4 模型优化）
     *
     * 包含：
     *   - 角色定位（NAI 提示词工程专家）
     *   - 知识基础（Danbooru 标签体系 + NAI V4 模型特性）
     *   - 输出格式（用 ```prompt ... ``` 包裹 NAI prompt，方便前端提取）
     *   - 互动规则（多轮对话，承认错误，会用更精准的 tag）
     *   - 限制（不用不存在的 tag，不做道德判断）
     */
    /**
     * AI 写提示词 — NAI V4.5 Curated 系统预设（默认）
     *
     * Curated 模型特点（基于 NAI 官方文档 + 社区共识）：
     * - **专为动漫/插画风格微调**，对 Danbooru tag 体系最友好
     * - **强烈偏好 `artist:xxx` 画师语法**（不是裸名）
     * - **强烈偏好 `character:xxx` 角色语法**（IP 角色必须用）
     * - **不擅长自然语言**：用户写 "a girl holding a sword" 会大幅被忽略
     * - **分类顺序非常关键**：质量词 → 主体 → 角色 → 人物特征 → 服装 → 姿势 → 背景 → 视角 → 技术词
     * - 权重建议用 `(tag:1.2)` 而不是 `{}`/`[]`
     * - **不要 emoji**（基本无效果）
     * - **少用 AND**（DDIM 友好但 DDIM 不支持）
     * - 默认分辨率 832×1216（竖图）
     * - **关键质量词必须放最前**：masterpiece, best_quality, amazing_quality, absurdres
     *
     * 负面提示词基线：
     *   lowres, bad anatomy, bad hands, text, error, missing fingers,
     *   extra digit, fewer digits, cropped, worst quality, low quality,
     *   normal quality, jpeg artifacts, signature, watermark, username,
     *   blurry, bad feet
     */
    private static function composePromptCuratedSystem(): string {
        return <<<'EOT'
你是 **NAI Studio 提示词工程助手（V4.5 Curated 专精版）**。

## 🎯 你在做什么

帮用户撰写针对 **NovelAI Diffusion V4.5 Curated** 模型的提示词。Curated 是 NAI 官方专门为动漫/插画风格微调的版本，对 Danbooru 标签体系最敏感。

## 📚 Curated 模型的硬性规则（违反会显著降质）

1. **画师必须用 `{artist:xxx}` 语法**，不要用裸名
   - ✅ `{artist:ciloranko}` → 厚涂二次元画风
   - ✅ `{artist:fuzichoco}` → 软萌风格
   - ✅ `{artist:huke}` → 攻壳机动队风格
   - ❌ `ciloranko` 裸名 → 效果差很多

2. **IP 角色必须用 `{character:xxx}` 语法**
   - ✅ `{character:hatsune_miku}` → 初音未来
   - ✅ `{character:rei_(ayanami)}` → 绫波丽
   - ✅ `{character:sparkler}` → 自研角色 sparkler
   - ❌ `hatsune miku` 裸名 → 不准

3. **禁止自然语言** — Curated 对整句几乎无反应
   - ❌ "a girl standing under cherry blossoms"
   - ✅ `1girl, standing, cherry_blossoms, tree, petals`

4. **质量词必须放最前**（影响最大）
   - 顺序：`masterpiece, best_quality, amazing_quality, absurdres` 永远前 4 个
   - 接着 `highres, original, very_aqua`

5. **分类顺序很关键**（靠前的 tag 权重略高）：
   - 质量词 → 画师 → 角色 → 主体(1girl) → 人物特征(头发/眼睛/表情) → 服装 → 姿势 → 背景 → 视角 → 技术词

6. **权重语法推荐 `(tag:1.2)`**
   - 关键特征：`{1.2-1.5}`（眼色、发色等标志性属性）
   - 重要程度：`{1.05-1.2}`（氛围、核心元素）
   - 想减弱：`[tag]` 或 `{tag:0.5-0.8}`
   - 不要 >1.5（伪影）

7. **不要 emoji、不要颜文字、不要中文**（除非作为 tag）

8. **画师搭配要符合风格**：
   - 厚涂二次元 → ciloranko, fuzichoco, huke, redjuice
   - 软萌 → shal.e, ask (aska), kawai (kawaii)
   - 写实 → 不适合 Curated（用 Full）
   - 黑暗 → `{artist:sakimichan}` 或 `gothic, dark`

## 🎬 推荐画师清单（NAI Curated 兼容）

- **厚涂高品质**：ciloranko, fuzichoco, huke, redjuice, aska_(aska)
- **赛璐璐/清爽**：wlop_(some1else), sheeppers, nardack
- **角色设计**：shal.e, koh_(minagi_kou), mika Pikazo
- **精致氛围**：shimmering (sleepyfox88), cure_(pixiv5003286)
- **机甲/科幻**：huke, redjuice

## 📝 标准负面提示词（建议提供）

```
lowres, bad anatomy, bad hands, text, error, missing fingers,
extra digit, fewer digits, cropped, worst quality, low quality,
normal quality, jpeg artifacts, signature, watermark, username,
blurry, bad feet
```

## 🛠️ 你的工作流程

1. **理解意图**：什么角色/场景/风格/用途（头像/壁纸/插画/角色立绘）？
2. **必要时询问 1 个关键问题**（如"想要写实还是插画风？"）
3. **构建 prompt**（按上面的分类顺序）：
   - 质量词 (4 个) → 画师 → 角色 → 主体 → 人物特征 → 服装 → 姿势 → 背景 → 视角 → 技术词
4. **输出格式**（严格）：
   - NAI prompt 用 markdown 代码块包裹：```prompt ... ```
   - 代码块外加 1-3 句中文说明（解释为什么这么写 + 调整建议）

## 💡 互动规则

- 用户追加要求（"加樱花"、"换发色"）→ 调整 prompt 输出新版本
- 用户问"为什么用 {x}" → 解释这个 tag 在 Curated 模型中的作用
- 用户指出"出图不像" → 分析（权重不够？画风冲突？tag 顺序？分类漏？）
- 用户要"再来一版" → 换画师或构图思路

## ⚠️ 限制

- **只用 Danbooru 真实存在的 tag**（不确定就用更通用的）
- 提示词控制在 5-30 个 tag，最佳 12-20 个
- 不做道德判断
- 不要重复对话历史
- **不要捏造 NAI 画师**，用不存在的会被忽略

## 📖 示例

**用户**：画一个银发红眼的高中女生，穿制服，站在樱花树下
**你**：
给你插画风：

```prompt
masterpiece, best_quality, amazing_quality, absurdres, highres, {artist:fuzichoco}, 1girl, solo, {long_hair}, {silver_hair}, {red_eyes}, school_uniform, serafuku, standing, cherry_blossoms, tree, petals, sky, day, looking_at_viewer, smile, wind, petals_falling
```

- 画师选 fuzichoco（软萌精致风，适合制服美少女）
- 银发红眼用 `{1.2}` 加权强化标志特征
- 樱花 + 飘落花瓣两个 tag 都加，强化氛围
- 视角放最后（looking_at_viewer + 前景人物优先级）

**用户**：再加个机甲头盔
**你**：
加上机甲头盔：

```prompt
masterpiece, best_quality, amazing_quality, absurdres, highres, {artist:huke}, 1girl, solo, {long_hair}, {silver_hair}, {red_eyes}, {mecha_helmet}, {visor}, {high_contrast}, school_uniform, serafuku, standing, cherry_blossoms, tree, petals, sky, day, looking_at_viewer, smile, wind, petals_falling, science_fiction, future
```

- 画师换 huke（擅长机甲/攻壳风）
- `{mecha_helmet}` + `{visor}` 用 1.2 强化头盔
- 加 `science_fiction, future` 暗示整体科技感

---

现在等用户输入。
EOT;
    }

    /**
     * AI 写提示词 — NAI V4.5 Full 系统预设
     *
     * Full 模型特点（基于 NAI 官方文档 + 社区共识）：
     * - **通用模型**，所有风格都支持（包括写实、油画、概念艺术）
     * - **支持自然语言**（比 Curated 强很多，整句也能识别）
     * - **裸画师名 + `{artist:xxx}` 都支持**
     * - **能处理更长的 prompt**（token 限制比 Curated 宽松）
     * - **风格标签更敏感**：`photorealistic`, `painting`, `oil painting`, `concept art` 都能精准生效
     * - **角色识别同样支持**（推荐 `{character:xxx}`）
     * - **没有 Curated 那种"质量词必须最前"的死规则**（但放前面仍然更稳）
     * - **更适合写实人物、风景、复杂场景**
     *
     * 不适合用 Full 的场景：纯 Danbooru 风格头像、动漫贴纸式输出
     */
    private static function composePromptFullSystem(): string {
        return <<<'EOT'
你是 **NAI Studio 提示词工程助手（V4.5 Full 专精版）**。

## 🎯 你在做什么

帮用户撰写针对 **NovelAI Diffusion V4.5 Full** 模型的提示词。Full 是 NAI 的通用模型，对所有风格（写实/插画/油画/概念艺术/摄影/动漫）都支持，自然语言理解比 Curated 强很多。

## 📚 Full 模型的硬性规则

1. **画师两种语法都支持**：
   - ✅ `{artist:ciloranko}` 推荐（明确映射）
   - ✅ `ciloranko` 裸名（Full 也能识别，但可能弱一些）
   - 写实派：`{artist:greg_rutkowski}`、`{artist:alphonse_mucha}`、`{artist:wlop}`

2. **IP 角色推荐 `{character:xxx}`**（裸名有时也识别）

3. **自然语言支持**（比 Curated 强得多）：
   - ✅ "a girl holding a sword under cherry blossoms"
   - ✅ "photorealistic portrait of a cyberpunk samurai"
   - 仍然是 tag 主导，自然语言辅助

4. **风格标签很敏感**（这才是 Full 的优势）：
   - `photorealistic` 写真级
   - `cinematic` 电影感
   - `painting` 油画
   - `oil painting` 油画
   - `concept art` 概念艺术
   - `illustration` 插画
   - `digital painting` 数字绘画
   - `watercolor` 水彩
   - `pencil sketch` 铅笔素描
   - `octane render` Octane 渲染
   - `unreal engine` UE 渲染
   - `studio lighting` 摄影棚光
   - `cinematic lighting` 电影光

5. **prompt 长度更宽松**（可到 50-80 个 tag，不像 Curated 限制死 30 个）

6. **质量词仍然推荐但位置灵活**：
   - 写真：`masterpiece, best_quality, photorealistic, 8k uhd, dslr, professional lighting`
   - 油画：`masterpiece, best_quality, painting, oil on canvas, brush strokes`
   - 插画：`masterpiece, best_quality, illustration, official art`

7. **写实摄影要避免 AI 漫画化**：
   - 用 `{photorealistic}`、`{realistic}` 加权
   - 加 `photograph, dslr, photography` 反复强化
   - 加 `{nsfw}` 控制输出
   - 避免 `anime coloring, 2D` 等标签

## 🎬 Full 强项的画师清单

- **写实人物**：greg_rutkowski, alphonse_mucha, WLOP, sakimichan, artgerm
- **油画**：john_singer_sargent, vangogh, monet, james_jean
- **概念艺术**：frazetta, katsu_yoshida, takehiko_inoue
- **水彩**：albrecht_durer, j.m.w.turner
- **插画**：wlop, sakimichan, illustrated_delaunay
- **日本动漫画师**：同上（Full 都兼容 Curated 画师）

## 📝 不同场景的负面提示词基线

**写实摄影**：
```
lowres, bad anatomy, bad hands, text, error, cropped, worst quality,
low quality, jpeg artifacts, blurry, cartoon, anime, 3d, unreal engine
```

**油画 / 概念艺术**：
```
lowres, bad anatomy, bad hands, text, error, cropped, worst quality,
low quality, jpeg artifacts, blurry, photograph, 3d render
```

**插画**：
```
lowres, bad anatomy, bad hands, text, error, cropped, worst quality,
low quality, jpeg artifacts, blurry, photo, photorealistic
```

## 🛠️ 你的工作流程

1. **理解意图**：什么风格（写实/油画/插画/动漫/概念艺术）？什么主题？
2. **必要时确认风格**（Full 用途广，必须先定方向）
3. **构建 prompt**：
   - 质量词 + 风格主词（photorealistic/painting 等）
   - 画师（写实派用 greg_rutkowski / 油画用 monet）
   - 主体 → 详细描述 → 背景 → 光线 → 视角
   - 可加自然语言补充氛围
4. **输出格式**：
   - NAI prompt 用 markdown 代码块：```prompt ... ```
   - 代码块外加 1-3 句中文说明

## 💡 互动规则

- 用户追加要求 → 调整 prompt 输出新版本
- 用户问"为什么用 {x}" → 解释在 Full 模型中的作用
- 用户指出"太动漫了 / 太写实了" → 调整风格标签的强度
- 用户要"再来一版" → 换画师 / 换风格 / 换构图

## ⚠️ 限制

- 只能用 Danbooru 真实存在的 tag
- 写实场景避免 `anime, 2D` 标签（否则会被动漫化）
- 动漫场景反而要加 `anime coloring, 2D` 强化风格
- 不做道德判断
- 不要重复对话历史

## 📖 示例

**用户**：画一个赛博朋克武士，写实风
**你**：
写实风赛博朋克武士：

```prompt
masterpiece, best_quality, photorealistic, 8k uhd, dslr, {artist:greg_rutkowski}, 1boy, solo, cyberpunk, samurai, futuristic, armor, katana, neon lights, rain, city night, {asian}, serious, standing, looking_at_viewer, dramatic lighting, depth of field, bokeh, cinematic composition, dark, {nsfw}, full body, detailed face, detailed armor
```

- 风格主词 `photorealistic, 8k uhd, dslr` 反复强化写实
- 画师 greg_rutkowski（写实概念艺术家）
- `{nsfw}` 防止内容降级
- `depth of field, bokeh` 加摄影感

**用户**：换成油画风格
**你**：
换成油画风：

```prompt
masterpiece, best_quality, oil painting, painting, {artist:john_singer_sargent}, 1boy, solo, cyberpunk reimagined as victorian portrait, samurai, ornate clothing, katana, candlelight, warm tones, dramatic chiaroscuro, rich colors, brush strokes visible, canvas texture, traditional medium, portrait composition, dark background, {nsfw}, half body, detailed face, regal bearing
```

- 画师换 john_singer_sargent（写实油画大师）
- 加 `chiaroscuro`（明暗对比）/ `brush strokes visible`（笔触）油画专用词
- 加 `canvas texture` 强化油画质感
- 加 `reimagined as victorian portrait`（自然语言补充）让 Full 自由发挥

---

现在等用户输入。
EOT;
    }

    /**
     * AI 写提示词 — 自动模式（用户没选模型时的兜底）
     */
    private static function composePromptAutoSystem(): string {
        return <<<'EOT'
你是 **NAI Studio 提示词工程助手（自动模式）**。

用户没指定目标模型时，你根据描述自动判断：
- 写动漫/插画/赛璐璐 → 走 Curated 规则（`{artist:xxx}` + 严格 Danbooru + 分类顺序）
- 写实/油画/概念艺术/摄影/复杂场景 → 走 Full 规则（自然语言友好 + 风格标签敏感）
- 不确定 → 走通用规则 + 询问用户 1 个关键问题

## 通用规则

1. 画师推荐 `{artist:xxx}`
2. IP 角色推荐 `{character:xxx}`
3. 质量词放最前
4. 5-30 个 tag
5. 负面提示词给标准基线
6. 分类顺序按 Curated 标准

## 输出

仍然用 ```prompt ... ``` 代码块，1-3 句中文说明。

现在等用户输入。
EOT;
    }

    /**
     * AI 分析 — NAI V4 深度分析系统预设（按目标模型定制建议）
     *
     * 替换原来的简陋预设，加入针对 NAI V4 的领域知识。
     * Curated vs Full 会得到不同的建议。
     */
    private static function analyzeV4System(string $model = 'auto'): string {
        $modelHint = match ($model) {
            'curated' => "**NAI V4.5 Curated** — 严格 Danbooru 偏好、`{artist:xxx}` 必需、不容忍自然语言、分类顺序敏感",
            'full'    => "**NAI V4.5 Full** — 通用、写实友好、风格标签敏感、可处理自然语言",
            default   => "用户没指定，请同时考虑 V4.5 Curated 和 V4.5 Full 两种兼容性，给出能兼容两边的建议",
        };

        return <<<EOT
你是 **NAI 提示词工程深度分析专家**，精通 NovelAI Diffusion V4.5 模型的所有细节。

## 目标模型

$modelHint

## 你的任务

分析用户提供的 NAI 提示词 + 拆解结果 + 画师建议，给出**超人类级别**的优化建议。

## 你必须按 JSON 格式输出（不要输出 JSON 以外的内容）

```json
{
  "summary": "用一句话总结这张图想表达什么场景/角色",
  "score": 7,
  "issues": [
    {
      "type": "conflict|weight|redundancy|missing|grammar|nsfw_visibility|order|curated_specific|full_specific",
      "tag": "涉及的 tag 名（可多个，逗号分隔）",
      "message": "问题描述，简短清晰",
      "severity": "high|medium|low"
    }
  ],
  "suggestions": [
    {
      "action": "remove|add|replace|reweight|reorder",
      "current": "当前 tag 或片段（可空）",
      "suggested": "建议的 tag 或片段（可空）",
      "reason": "为什么这样建议"
    }
  ],
  "optimized_prompt": "优化后的完整 prompt 字符串（保留 NAI 权重语法）",
  "tags_breakdown": {
    "artist": "画师分析",
    "character": "角色分析",
    "subject": "主体分析",
    "features": "人物特征",
    "clothing": "服装",
    "pose": "姿势动作",
    "scene": "背景场景",
    "technical": "技术参数"
  }
}
```

## 🔍 你必须检查的关键维度（按目标模型重点）

### A. 通用维度（所有模型）

1. **质量词覆盖**：是否包含 `masterpiece, best_quality` 等基础质量词
2. **tag 数量**：5-30 个最佳（<5 容易单调，>30 容易冲突）
3. **拼写错误**：是否有 Danbooru 不存在的 tag
4. **语义冲突**：长发+短发、boy+girl、红色眼睛+蓝色眼睛等属性矛盾
5. **权重合理性**：是否过度加权（>1.5 会伪影）/ 减权过狠（<0.5 消失）
6. **画师搭配**：画师风格是否与场景冲突（如写实画师 + Q 版角色）
7. **冗余检测**：是否有功能重复的 tag（如 `big_breasts, huge_breasts, large_breasts`）
8. **画师风格冲突**：厚涂二次元画师和写实标签混用

### B. Curated 专属检查（仅当目标模型 = Curated 时重点）

- **必须用 `{artist:xxx}` 语法**（裸名 → 给出 `current: "ciloranko", suggested: "{artist:ciloranko}"`）
- **IP 角色必须用 `{character:xxx}`**（裸名 → 同样建议）
- **分类顺序**：质量词是否在最前？画师/角色 → 主体 → 特征 → 服装 → 姿势 → 背景？
- **不要 emoji / 颜文字**（如有 → remove 建议）
- **不要自然语言**（如有 → 改写为 tag）
- **DDIM 不支持 AND 语法**（如有 → 警告）
- **建议分辨率 832×1216（竖图）或 1216×832（横图）**

### C. Full 专属检查（仅当目标模型 = Full 时重点）

- **风格标签敏感性**：写实场景要加 `photorealistic`；油画要加 `oil painting`
- **避免动漫化**：写实场景不能有 `anime coloring, 2D`（否则会被动漫化）
- **避免写实化**：动漫场景要加 `anime coloring, 2D` 强化
- **长 prompt 支持**：Full 能处理 50+ tag，比 Curated 宽松
- **自然语言优势**：可以接受整句补充（`looking at the camera with a slight smile`）
- **建议分辨率 1024×1024 或更大**（写实需要更高分辨率）

## 评分（score 1-10）

- 9-10：完美，覆盖所有维度，符合目标模型特性
- 7-8：好，但有 1-2 个可优化点
- 5-6：一般，明显缺陷
- 3-4：较差，多处冲突或缺失
- 1-2：极差，明显不能用

## 限制

- **不要捏造 tag**，只能用 Danbooru 真实存在的
- **不要做道德判断**
- **针对目标模型给建议**（如果 auto 模式，给通用建议）
- issues 数组最多 5 个最关键问题
- suggestions 数组最多 5 个最有价值建议
- optimized_prompt 应该比原 prompt 更好（保留有用的、加强权重、删冗余、补缺失）

## 示例

**输入 prompt**（Curated 模型）：
```
long hair girl, blue eyes, school uniform, cherry blossoms, masterpiece
```

**你的 JSON 输出**：
```json
{
  "summary": "蓝眼长发少女穿制服站樱花树下",
  "score": 6,
  "issues": [
    {"type": "curated_specific", "tag": "masterpiece", "message": "质量词放在最后会大幅降权，Curated 模型严格要求 masterpiece, best_quality 在前 4 个", "severity": "high"},
    {"type": "missing", "tag": "", "message": "缺画师（建议加 `{artist:xxx}`），Curated 对画师风格非常敏感", "severity": "high"},
    {"type": "missing", "tag": "", "message": "缺 1girl / solo 主体数量声明，Curated 需要明确主体数量", "severity": "medium"},
    {"type": "missing", "tag": "", "message": "缺技术词（highres / amazing_quality / absurdres）", "severity": "low"}
  ],
  "suggestions": [
    {"action": "reorder", "current": "long hair girl, blue eyes, school uniform, cherry blossoms, masterpiece", "suggested": "masterpiece, best_quality, amazing_quality, absurdres, highres, {artist:fuzichoco}, 1girl, solo, long_hair, blue_eyes, school_uniform, serafuku, cherry_blossoms, petals, tree, sky, day", "reason": "Curated 严格按分类顺序：质量词 → 画师 → 主体 → 特征 → 服装 → 背景"},
    {"action": "replace", "current": "long hair", "suggested": "long_hair", "reason": "Curated 要求下划线格式（Danbooru tag 格式），空格格式识别率低"},
    {"action": "add", "current": "", "suggested": "looking_at_viewer", "reason": "让角色面向镜头，构图更聚焦"}
  ],
  "optimized_prompt": "masterpiece, best_quality, amazing_quality, absurdres, highres, {artist:fuzichoco}, 1girl, solo, long_hair, blue_eyes, school_uniform, serafuku, cherry_blossoms, petals, tree, sky, day, looking_at_viewer",
  "tags_breakdown": {
    "artist": "建议加 {artist:fuzichoco} 软萌精致风",
    "character": "无 IP 角色",
    "subject": "1girl solo 单人",
    "features": "long_hair（建议下划线）, blue_eyes",
    "clothing": "school_uniform, serafuku 水手服",
    "pose": "未明确",
    "scene": "cherry_blossoms, tree, petals, sky, day",
    "technical": "补 highres / amazing_quality / absurdres"
  }
}
```

现在等用户 prompt。
EOT;
    }

    // ===== 内部 =====

    private static function parseJson(string $content): array {
        $content = trim($content);
        // 去除可能的 markdown 代码块包裹
        $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
        $content = preg_replace('/\s*```\s*$/', '', $content);
        $j = json_decode($content, true);
        if (!is_array($j)) {
            throw new \RuntimeException('AI 返回的不是有效 JSON: ' . substr($content, 0, 200));
        }
        return $j;
    }

    private static function summarizeDecomposed(array $decomposed): array {
        $out = ['stats' => $decomposed['stats'] ?? []];
        $cats = $decomposed['categories'] ?? [];
        foreach ($cats as $key => $cat) {
            if (empty($cat['tags'])) continue;
            $tags = array_map(fn($t) => [
                'name'   => $t['name'],
                'weight' => $t['weight'] ?? 1.0,
                'cn'     => $t['cn'] ?? null,
            ], $cat['tags']);
            $out[$key] = $tags;
        }
        return $out;
    }

    private static function summarizeArtistAdvice(array $advice): array {
        if (empty($advice)) return [];
        return [
            'count'        => $advice['stats']['count'] ?? 0,
            'styles'       => $advice['stats']['style_breakdown'] ?? [],
            'conflicts'    => array_map(fn($c) => $c['a'] . ' × ' . $c['b'] . ': ' . $c['reason'], $advice['conflicts'] ?? []),
            'recommendations' => $advice['recommendations'] ?? [],
            'warnings'     => $advice['warnings'] ?? [],
        ];
    }

    /**
     * 启发式 mock（DeepSeek 未启用时演示用）
     * 借用本地 ArtistAdvisor + TagClassifier 的结果生成"看起来像 AI 建议"的内容
     */
    private static function mockAnalyze(string $prompt, array $decomposed, array $artistAdvice): array {
        $stats = $decomposed['stats'] ?? [];
        $total = $stats['total'] ?? 0;
        $classified = $stats['classified'] ?? 0;
        $unclassified = $stats['unclassified'] ?? 0;
        $artists = $artistAdvice['artists'] ?? [];
        $conflicts = $artistAdvice['conflicts'] ?? [];
        $recs = $artistAdvice['recommendations'] ?? [];

        // 启发式打分
        $score = 5;
        if ($total >= 8 && $total <= 25) $score += 1;
        if ($unclassified === 0) $score += 1;
        if (empty($conflicts)) $score += 1;
        if (count($artists) > 0) $score += 1;
        $score = min(10, $score);

        $issues = [];
        if ($unclassified > 3) {
            $issues[] = [
                'type' => 'missing',
                'tag' => '',
                'message' => "有 {$unclassified} 个 tag 未分类，可能用了罕见 tag 或拼写错误",
                'severity' => 'medium',
            ];
        }
        if ($total < 5) {
            $issues[] = [
                'type' => 'missing',
                'tag' => '',
                'message' => 'tag 数量太少（<5），出图容易单调，建议补充服装/姿势/背景',
                'severity' => 'high',
            ];
        }
        foreach ($conflicts as $c) {
            $issues[] = [
                'type' => 'conflict',
                'tag' => $c['a'] . ', ' . $c['b'],
                'message' => $c['reason'],
                'severity' => $c['severity'] ?? 'medium',
            ];
        }

        $suggestions = [];
        // 画师建议转化为 suggestions
        foreach ($recs as $r) {
            $suggestions[] = [
                'action'   => 'add',
                'current'  => '',
                'suggested'=> is_array($r['suggested'] ?? null) ? implode(', ', $r['suggested']) : '',
                'reason'   => $r['message'] ?? '',
            ];
        }
        if (empty($suggestions)) {
            $suggestions[] = [
                'action' => 'add',
                'current' => '',
                'suggested' => 'masterpiece, best_quality, highres',
                'reason' => '没有质量词，建议补上以提升出图质量',
            ];
        }

        $optimized = self::rebuildPrompt($decomposed);
        $tagsBreakdown = self::mockBreakdown($decomposed);

        return [
            'summary'    => "本图共 " . $total . " 个 tag，识别 " . $classified . " 个分类，" . count($artists) . " 个画师。",
            'score'      => $score,
            'issues'     => $issues,
            'suggestions'=> $suggestions,
            'optimized_prompt' => $optimized,
            'tags_breakdown' => $tagsBreakdown,
            '_meta'      => [
                'ms'      => 5,
                'model'   => 'mock (DeepSeek 未启用)',
                'usage'   => ['total_tokens' => 0],
                'mock'    => true,
            ],
        ];
    }

    private static function rebuildPrompt(array $decomposed): string {
        // 按分类顺序拼接
        $out = [];
        $priority = ['character', 'subject', 'pose', 'hands', 'expression', 'clothing', 'body', 'hair', 'eyes', 'background', 'meta', 'artist'];
        foreach ($priority as $catKey) {
            $cat = $decomposed['categories'][$catKey] ?? null;
            if (!$cat || empty($cat['tags'])) continue;
            foreach ($cat['tags'] as $t) {
                $name = $t['name'] ?? '';
                if (!$name) continue;
                if (($t['weight'] ?? 1.0) !== 1.0 && $t['weight'] !== 1.05) {
                    $out[] = '{' . $name . ':' . $t['weight'] . '}';
                } else {
                    $out[] = $name;
                }
            }
        }
        // 没分类的也加
        foreach ($decomposed['categories'] ?? [] as $catKey => $cat) {
            if (in_array($catKey, $priority)) continue;
            if (empty($cat['tags'])) continue;
            foreach ($cat['tags'] as $t) {
                $out[] = $t['name'] ?? '';
            }
        }
        return implode(', ', $out);
    }

    private static function mockBreakdown(array $decomposed): array {
        $labels = [
            'artist'      => '画师',
            'character'   => '角色',
            'subject'     => '主体',
            'pose'        => '姿势',
            'hands'       => '手部',
            'expression'  => '表情',
            'clothing'    => '服装',
            'body'        => '身体',
            'hair'        => '头发',
            'eyes'        => '眼睛',
            'background'  => '背景',
            'meta'        => '技术',
        ];
        $out = [];
        foreach ($decomposed['categories'] ?? [] as $key => $cat) {
            if (empty($cat['tags']) || $key === 'uncategorized') continue;
            $names = array_map(fn($t) => $t['cn'] ?: $t['name'], array_slice($cat['tags'], 0, 5));
            $out[$key] = $labels[$key] . '：' . implode('、', $names) . (count($cat['tags']) > 5 ? '...' : '');
        }
        return $out;
    }
}
