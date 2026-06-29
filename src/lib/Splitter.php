<?php
/**
 * NAI Studio - NAI Prompt splitter (robust)
 *
 * 把 NAI 提示词切成 [{tag, weight, raw}, ...] 列表。
 *
 * 比 PromptParser 强的地方：
 *   - 支持 {tag:something} 非数字 brace（artist:xxx, character:xxx）
 *   - 正确处理嵌套 {{...}}、{a::1.2} 这种 NAI 真实用法
 *   - 对原 PromptParser 解析失败的复杂 prompt 也能切干净
 *
 * 输出结构（与 PromptParser 保持一致，方便互换）：
 *   - text=true 的节点：纯文本
 *   - text 缺省：tag 节点（tag, weight, raw, hidden）
 *
 * 用法：
 *   Splitter::split('{artist:ciloranko}, 1girl, {a::1.2}');
 *   // => [
 *   //   {name:'{artist:ciloranko}', tag:'artist:ciloranko', weight:1.05, raw:'{artist:ciloranko}'},
 *   //   {name:', ', text:true, raw:', '},
 *   //   {name:'1girl', tag:'1girl', weight:1.0, raw:'1girl'},
 *   //   ...
 *   // ]
 */

declare(strict_types=1);

namespace NaiStudio;

class Splitter {
    /**
     * 匹配 brace/paren/bracket 包裹的内容（含多层嵌套）
     * 提取的 inner: 不含最外层包裹
     */
    private const RE_NESTED_BRACE = '/\{([^{}]*(?:\{[^{}]*\}[^{}]*)*)\}/';
    private const RE_NESTED_PAREN = '/\(([^()]*(?:\([^()]*\)[^()]*)*)\)/';
    private const RE_NESTED_BRACK = '/\[([^\[\]]*(?:\[[^\[\]]*\][^\[\]]*)*)\]/';

    /**
     * 简易 split：先按 NAI 权重符号切（保留原片段）
     */
    public static function split(string $prompt): array {
        $tokens = [];
        $len = mb_strlen($prompt);
        $pos = 0;
        $i = 0;

        while ($i < $len) {
            $ch = $prompt[$i];
            // brace: {tag} 或 {tag:weight} 或 {tag:anything}（NAI V4 也用）
            if ($ch === '{') {
                $end = self::findMatching($prompt, $i, '{', '}');
                if ($end > $i) {
                    $raw = substr($prompt, $i, $end - $i + 1);
                    $inner = substr($prompt, $i + 1, $end - $i - 1);
                    $tokens[] = self::parseBraced($raw, $inner);
                    $i = $end + 1;
                    continue;
                }
            }
            // paren: (tag) 或 (tag:weight)
            if ($ch === '(') {
                $end = self::findMatching($prompt, $i, '(', ')');
                if ($end > $i) {
                    $raw = substr($prompt, $i, $end - $i + 1);
                    $inner = substr($prompt, $i + 1, $end - $i - 1);
                    $tokens[] = self::parseParened($raw, $inner);
                    $i = $end + 1;
                    continue;
                }
            }
            // bracket: [tag] 或 [tag:weight]
            if ($ch === '[') {
                $end = self::findMatching($prompt, $i, '[', ']');
                if ($end > $i) {
                    $raw = substr($prompt, $i, $end - $i + 1);
                    $inner = substr($prompt, $i + 1, $end - $i - 1);
                    $tokens[] = self::parseBracketed($raw, $inner);
                    $i = $end + 1;
                    continue;
                }
            }
            // 普通字符 - 找到下一个 delimiter
            $next = self::findNextDelim($prompt, $i + 1);
            $raw = substr($prompt, $i, $next - $i);
            $tokens[] = [
                'tag'    => '',
                'weight' => 1.0,
                'raw'    => $raw,
                'hidden' => false,
                'text'   => true,
            ];
            $i = $next;
        }

        return $tokens;
    }

    private static function findMatching(string $s, int $start, string $open, string $close): int {
        $depth = 0;
        $len = strlen($s);
        for ($i = $start; $i < $len; $i++) {
            if ($s[$i] === $open) $depth++;
            elseif ($s[$i] === $close) {
                $depth--;
                if ($depth === 0) return $i;
            }
        }
        return -1;
    }

    private static function findNextDelim(string $s, int $from): int {
        $len = strlen($s);
        for ($i = $from; $i < $len; $i++) {
            if ($s[$i] === '{' || $s[$i] === '(' || $s[$i] === '[') return $i;
        }
        return $len;
    }

    /** Parse {xxx} or {xxx:1.2} or {xxx:non_numeric} */
    private static function parseBraced(string $raw, string $inner): array {
        [$name, $weight, $hidden] = self::splitInner($inner, 1.05);
        return [
            'name'   => $raw,
            'tag'    => $name,
            'weight' => $weight,
            'raw'    => $raw,
            'hidden' => $hidden,
        ];
    }

    private static function parseParened(string $raw, string $inner): array {
        [$name, $weight, $hidden] = self::splitInner($inner, 1.05);
        return [
            'name'   => $raw,
            'tag'    => $name,
            'weight' => $weight,
            'raw'    => $raw,
            'hidden' => $hidden,
        ];
    }

    private static function parseBracketed(string $raw, string $inner): array {
        [$name, $weight, $hidden] = self::splitInner($inner, 0.95);
        return [
            'name'   => $raw,
            'tag'    => $name,
            'weight' => $weight,
            'raw'    => $raw,
            'hidden' => $hidden,
        ];
    }

    /**
     * 切 inner 里的 "name[:weight]" 形式
     * NAI 权重语法：
     *   "tag"           -> weight 1.05
     *   "tag:1.2"       -> weight 1.2
     *   "tag:0"         -> weight 0 (hidden)
     *   "tag::1.2"      -> weight 1.05 * 1.2 = 1.26 (NAI 的"双冒号"叠加权重)
     *   "tag" 但带 character:xxx 前缀 -> tag="character:xxx", weight 1.05
     */
    private static function splitInner(string $inner, float $defaultWeight): array {
        $inner = trim($inner);
        // 双冒号 {a::1.2} 叠加权重
        if (str_contains($inner, '::')) {
            [$left, $right] = explode('::', $inner, 2);
            $left = trim($left);
            $rightW = is_numeric(trim($right)) ? (float)trim($right) : 1.0;
            return [$left, $defaultWeight * $rightW, false];
        }
        // 单冒号 - 可能是 weight（数字）也可能是 prefix（artist:xxx）
        if (str_contains($inner, ':')) {
            $parts = explode(':', $inner, 2);
            $name = trim($parts[0]);
            $tail = trim($parts[1]);
            // tail 是数字 -> weight
            if (is_numeric($tail)) {
                $w = (float)$tail;
                return [$name, $w, $w <= 0.001];
            }
            // tail 不是数字 -> 整体是 tag name（含 prefix）
            return [$inner, $defaultWeight, false];
        }
        return [$inner, $defaultWeight, false];
    }
}
