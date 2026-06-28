<?php
/**
 * NAI Studio - Prompt parser
 * Handles NAI-specific syntax: {tag:weight}, (tag), [tag], multi-bracket emphasis.
 *
 * NAI weight syntax:
 *   {tag}        = 1.05
 *   {tag:1.5}    = 1.5
 *   (tag)        = 1.05
 *   (tag:0.5)    = 0.5
 *   [tag]        = 0.95
 *   [[tag]]      = 0.9025
 *   {tag:0}      = 0 (exclude / hide)
 */

declare(strict_types=1);

namespace NaiStudio;

class PromptParser {
    private const RE_BRACE = '/\{([^{}:]+)(?::([0-9]*\.?[0-9]+))?\}/';
    private const RE_PAREN = '/\(([^():]+)(?::([0-9]*\.?[0-9]+))?\)/';
    private const RE_BRACK = '/\[([^\[\]:]+)(?::([0-9]*\.?[0-9]+))?\]/';

    /**
     * Parse a prompt into a list of tags with weights.
     * Returns: [['tag'=>'foo','weight'=>1.05,'raw'=>'{foo:1.05}','hidden'=>false], ...]
     */
    public static function parse(string $prompt): array {
        $result = [];
        $pos = 0;
        $len = strlen($prompt);
        while ($pos < $len) {
            $ch = $prompt[$pos];
            if ($ch === '{' && ($m = self::matchAt($prompt, $pos, self::RE_BRACE))) {
                $tag = trim($m[1]);
                $w = isset($m[2]) && $m[2] !== '' ? (float)$m[2] : 1.05;
                $result[] = [
                    'tag'    => $tag,
                    'weight' => $w,
                    'raw'    => $m[0],
                    'hidden' => $w <= 0.001,
                ];
                $pos += strlen($m[0]);
            } elseif ($ch === '(' && ($m = self::matchAt($prompt, $pos, self::RE_PAREN))) {
                $tag = trim($m[1]);
                $w = isset($m[2]) && $m[2] !== '' ? (float)$m[2] : 1.05;
                $result[] = [
                    'tag'    => $tag,
                    'weight' => $w,
                    'raw'    => $m[0],
                    'hidden' => $w <= 0.001,
                ];
                $pos += strlen($m[0]);
            } elseif ($ch === '[' && ($m = self::matchAt($prompt, $pos, self::RE_BRACK))) {
                $tag = trim($m[1]);
                $w = isset($m[2]) && $m[2] !== '' ? (float)$m[2] : 0.95;
                $result[] = [
                    'tag'    => $tag,
                    'weight' => $w,
                    'raw'    => $m[0],
                    'hidden' => $w <= 0.001,
                ];
                $pos += strlen($m[0]);
            } else {
                // Plain text (until next delimiter)
                $next = self::findNext($prompt, $pos);
                $result[] = [
                    'tag'    => '',
                    'weight' => 1.0,
                    'raw'    => substr($prompt, $pos, $next - $pos),
                    'hidden' => false,
                    'text'   => true,
                ];
                $pos = $next;
            }
        }
        return $result;
    }

    /** Extract just the unique tag names (for library lookups). */
    public static function extractTagNames(string $prompt): array {
        $names = [];
        foreach (self::parse($prompt) as $node) {
            if (empty($node['text']) && $node['tag'] !== '') {
                $names[] = strtolower($node['tag']);
            }
        }
        return array_values(array_unique($names));
    }

    /** Normalize a prompt for display (preserves formatting, normalizes spacing). */
    public static function normalize(string $prompt): string {
        return trim(preg_replace('/\s+/', ' ', $prompt));
    }

    private static function matchAt(string $s, int $pos, string $regex): ?array {
        if (preg_match($regex, $s, $m, 0, $pos) === 1 && $m[0] !== '' && $pos === strpos($s, $m[0], 0)) {
            // ensure match starts exactly at $pos
            if (strpos($s, $m[0], $pos === 0 ? 0 : $pos - 0) === $pos) {
                return $m;
            }
        }
        // Fallback: re-anchor
        $sub = substr($s, $pos);
        if (preg_match($regex, $sub, $m)) {
            return $m;
        }
        return null;
    }

    private static function findNext(string $s, int $pos): int {
        $len = strlen($s);
        for ($i = $pos + 1; $i < $len; $i++) {
            if (in_array($s[$i], ['{', '(', '['], true)) return $i;
        }
        return $len;
    }
}
