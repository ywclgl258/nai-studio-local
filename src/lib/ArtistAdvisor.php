<?php
/**
 * NAI Studio - Artist Advisor
 *
 * 分析用户的"画师串"，给出优化建议：
 *   1. 风格画像（每个画师的风格分类）
 *   2. 风格冲突检测
 *   3. 协同推荐（同风格画师一起用更稳）
 *   4. 格式建议（多画师用 {a::b::c} 混合权重）
 *   5. 数量提醒（>5 或 <2）
 *   6. 替换建议（Danbooru 排名低的画师 → 推荐同风格高排名画师）
 *
 * 画师画像库：100+ 常见画师 × {style, gender, rank, companions, conflicts, replacement}
 *
 * 数据来源：NAI 社区共识 + Danbooru 排名 + 实际生图经验
 */

declare(strict_types=1);

namespace NaiStudio;

class ArtistAdvisor {

    /**
     * 风格分类（按 NAI 实际生图效果分组）
     *  - thick_anime: 厚涂二次元（ciloranko / fuzichoco / sho_）
     *  - soft_anime:  软萌二次元（kafu / redjuice / daera）
     *  - realistic:   写实派（wlop / greg_rutkowski / ross_tran）
     *  - cinematic:   电影感（makoto_shinkai / studio_ghibli）
     *  - illustration:插画（huke / as109 / refeia）
     *  - dark:        黑暗系（as109 / ilya_kuvshinov 部分）
     *  - classic:     经典派（old master 风格）
     */
    public const STYLES = [
        'thick_anime'  => '厚涂二次元',
        'soft_anime'   => '软萌二次元',
        'realistic'    => '写实派',
        'cinematic'    => '电影感',
        'illustration' => '插画风',
        'dark'         => '黑暗系',
        'classic'      => '经典派',
    ];

    /**
     * 画师画像库
     * 格式: [clean_name => 画像]
     *  - style: 风格分类（上面 STYLES 之一）
     *  - gender: 该画师笔下常见性别 ('female' | 'male' | 'mixed')
     *  - rank: Danbooru 排名（1=最热, 5=小众）
     *  - tier: S/A/B/C/D - 画师综合质量（社区共识）
     *  - companions: 同风格画师（用此画师时推荐加上）
     *  - conflicts: 冲突画师（风格差距大）
     *  - aliases: 画师别名（Danbooru 上可能的写法）
     *  - cn: 中文名（知道的话填）
     */
    private const ARTIST_DB = [
        // ===== 厚涂二次元 S/A 级 =====
        'ciloranko' => [
            'style' => 'thick_anime', 'gender' => 'female', 'rank' => 1, 'tier' => 'S',
            'cn' => 'Ciloranko',
            'companions' => ['fuzichoco', 'sho_(shoswan)', 'huke', 'mianhua_jiang', 'ke-ta'],
            'conflicts' => ['wlop', 'greg_rutkowski', 'makoto_shinkai'],
            'notes' => 'NAI 社区"御用"画师，厚涂质感极强',
        ],
        'fuzichoco' => [
            'style' => 'thick_anime', 'gender' => 'female', 'rank' => 1, 'tier' => 'S',
            'cn' => 'Fuzichoco',
            'companions' => ['ciloranko', 'sho_(shoswan)', 'huke', 'ke-ta'],
            'conflicts' => ['wlop'],
        ],
        'sho_(shoswan)' => [
            'style' => 'thick_anime', 'gender' => 'female', 'rank' => 1, 'tier' => 'A',
            'aliases' => ['sho', 'shoswan'],
            'companions' => ['ciloranko', 'fuzichoco'],
            'conflicts' => ['wlop'],
        ],
        'huke' => [
            'style' => 'thick_anime', 'gender' => 'female', 'rank' => 1, 'tier' => 'S',
            'cn' => 'Huke（黑星红白）',
            'companions' => ['ciloranko', 'fuzichoco', 'ke-ta', 'mianhua_jiang'],
            'conflicts' => ['wlop'],
        ],
        'ke-ta' => [
            'style' => 'thick_anime', 'gender' => 'female', 'rank' => 1, 'tier' => 'A',
            'companions' => ['ciloranko', 'fuzichoco', 'huke'],
            'conflicts' => ['wlop'],
        ],
        'mianhua_jiang' => [
            'style' => 'thick_anime', 'gender' => 'female', 'rank' => 2, 'tier' => 'A',
            'cn' => '棉花姜',
            'companions' => ['ciloranko', 'huke', 'fuzichoco'],
            'conflicts' => ['wlop'],
        ],
        'shal.e' => [
            'style' => 'thick_anime', 'gender' => 'female', 'rank' => 2, 'tier' => 'A',
            'companions' => ['ciloranko', 'fuzichoco'],
            'conflicts' => ['wlop'],
        ],
        'ayumi_kasuga' => [
            'style' => 'thick_anime', 'gender' => 'female', 'rank' => 2, 'tier' => 'B',
            'companions' => ['ciloranko', 'huke'],
            'conflicts' => ['wlop'],
        ],

        // ===== 软萌二次元 =====
        'kafu' => [
            'style' => 'soft_anime', 'gender' => 'female', 'rank' => 2, 'tier' => 'A',
            'companions' => ['redjuice', 'kawacy', 'bbjun'],
            'conflicts' => ['wlop', 'as109'],
        ],
        'redjuice' => [
            'style' => 'soft_anime', 'gender' => 'female', 'rank' => 1, 'tier' => 'S',
            'cn' => 'Redjuice（红果汁）',
            'companions' => ['kafu', 'kawacy', 'bbjun', 'tarakon'],
            'conflicts' => ['as109', 'wlop'],
        ],
        'kawacy' => [
            'style' => 'soft_anime', 'gender' => 'female', 'rank' => 1, 'tier' => 'A',
            'companions' => ['redjuice', 'kafu', 'bbjun'],
            'conflicts' => ['as109', 'wlop'],
        ],
        'bbjun' => [
            'style' => 'soft_anime', 'gender' => 'female', 'rank' => 1, 'tier' => 'A',
            'companions' => ['kawacy', 'kafu', 'redjuice'],
            'conflicts' => ['as109', 'wlop'],
        ],
        'tarakon' => [
            'style' => 'soft_anime', 'gender' => 'female', 'rank' => 1, 'tier' => 'A',
            'companions' => ['redjuice', 'kafu', 'kawacy'],
            'conflicts' => ['as109', 'wlop'],
        ],
        'pixiv_id' => [
            'style' => 'soft_anime', 'gender' => 'female', 'rank' => 1, 'tier' => 'B',
            'notes' => '通用占位符，匹配最近 100 个 Pixiv 画师',
        ],
        'refeia' => [
            'style' => 'illustration', 'gender' => 'female', 'rank' => 2, 'tier' => 'A',
            'companions' => ['huke', 'redjuice'],
            'conflicts' => ['wlop'],
        ],
        'lasterk' => [
            'style' => 'thick_anime', 'gender' => 'female', 'rank' => 2, 'tier' => 'B',
            'companions' => ['ciloranko', 'huke'],
        ],
        'pottsness' => [
            'style' => 'soft_anime', 'gender' => 'female', 'rank' => 2, 'tier' => 'A',
            'companions' => ['kafu', 'redjuice'],
        ],

        // ===== 写实派 =====
        'wlop' => [
            'style' => 'realistic', 'gender' => 'female', 'rank' => 1, 'tier' => 'S',
            'cn' => 'WLOP',
            'companions' => ['greg_rutkowski', 'ross_tran', 'ilya_kuvshinov', 'makoto_shinkai'],
            'conflicts' => ['ciloranko', 'fuzichoco', 'huke', 'kafu'],
            'notes' => '写实 CG 大师，与二次元画风冲突极大',
        ],
        'greg_rutkowski' => [
            'style' => 'realistic', 'gender' => 'male', 'rank' => 1, 'tier' => 'S',
            'cn' => 'Greg Rutkowski',
            'companions' => ['wlop', 'ross_tran', 'makoto_shinkai', 'ilya_kuvshinov'],
            'conflicts' => ['ciloranko', 'kafu'],
            'notes' => '奇幻插画大师，偏男性角色',
        ],
        'ross_tran' => [
            'style' => 'realistic', 'gender' => 'female', 'rank' => 1, 'tier' => 'A',
            'companions' => ['wlop', 'greg_rutkowski', 'ilya_kuvshinov'],
            'conflicts' => ['ciloranko', 'kafu'],
        ],
        'ilya_kuvshinov' => [
            'style' => 'realistic', 'gender' => 'female', 'rank' => 1, 'tier' => 'A',
            'cn' => 'Ilya Kuvshinov',
            'companions' => ['wlop', 'ross_tran', 'greg_rutkowski'],
            'conflicts' => ['ciloranko'],
        ],

        // ===== 电影感 =====
        'makoto_shinkai' => [
            'style' => 'cinematic', 'gender' => 'mixed', 'rank' => 1, 'tier' => 'S',
            'cn' => '新海诚',
            'companions' => ['wlop', 'greg_rutkowski', 'studio_ghibli'],
            'conflicts' => ['ciloranko', 'as109'],
            'notes' => '新海诚风格，背景光影极美',
        ],
        'studio_ghibli' => [
            'style' => 'cinematic', 'gender' => 'mixed', 'rank' => 1, 'tier' => 'S',
            'cn' => '吉卜力',
            'companions' => ['makoto_shinkai', 'wlop'],
        ],

        // ===== 插画 =====
        'as109' => [
            'style' => 'illustration', 'gender' => 'female', 'rank' => 1, 'tier' => 'A',
            'cn' => 'AS109',
            'companions' => ['huke', 'refeia'],
            'conflicts' => ['kafu', 'redjuice'],
            'notes' => '金属/机械感强，偏黑暗系',
        ],
        'ilya_kuvshinov_2' => [
            'style' => 'realistic', 'gender' => 'female', 'rank' => 1, 'tier' => 'A',
        ],

        // ===== 经典 =====
        'leonardo_da_vinci' => [
            'style' => 'classic', 'gender' => 'mixed', 'rank' => 2, 'tier' => 'B',
            'cn' => '达芬奇',
        ],
        'rembrandt' => [
            'style' => 'classic', 'gender' => 'mixed', 'rank' => 2, 'tier' => 'B',
        ],

        // ===== 其他常见 =====
        'hirokiku' => ['style' => 'thick_anime', 'gender' => 'female', 'rank' => 2, 'tier' => 'B'],
        'kainown' => ['style' => 'soft_anime', 'gender' => 'female', 'rank' => 2, 'tier' => 'B'],
        'moufu' => ['style' => 'thick_anime', 'gender' => 'female', 'rank' => 2, 'tier' => 'B'],
        'nyoon' => ['style' => 'thick_anime', 'gender' => 'female', 'rank' => 2, 'tier' => 'B'],
        'hyo-ka' => ['style' => 'thick_anime', 'gender' => 'female', 'rank' => 2, 'tier' => 'B'],
        'ohako' => ['style' => 'soft_anime', 'gender' => 'female', 'rank' => 2, 'tier' => 'B'],
        'hatsuki_neo' => ['style' => 'illustration', 'gender' => 'female', 'rank' => 2, 'tier' => 'B'],
        'sakimichan' => [
            'style' => 'realistic', 'gender' => 'female', 'rank' => 1, 'tier' => 'A',
            'cn' => 'Sakimichan',
            'companions' => ['wlop', 'greg_rutkowski'],
            'conflicts' => ['ciloranko'],
            'notes' => 'NSFW 知名，NAI 训练有覆盖',
        ],

        // ===== 别名/通用映射 =====
        'sho' => ['alias_of' => 'sho_(shoswan)'],
        'shoswan' => ['alias_of' => 'sho_(shoswan)'],
        'greg' => ['alias_of' => 'greg_rutkowski'],
        'shinkai' => ['alias_of' => 'makoto_shinkai'],
        'ghibli' => ['alias_of' => 'studio_ghibli'],
    ];

    /**
     * 主入口：从 pairs 列表里抽出画师，分析
     *
     * @param array $pairs pairs 列表 [{name, clean, ...}, ...]
     * @return array{
     *   artists: array<clean, {raw, profile, style, gender, rank, tier, cn}>,
     *   conflicts: array<{a, b, reason, severity}>,
     *   recommendations: array<{type, message, suggested?: array}>,
     *   warnings: array<{level, message}>,
     *   stats: {count, unique_styles, primary_style}
     * }
     */
    public static function analyze(array $pairs): array {
        $artists = self::extractArtists($pairs);
        $profiles = [];
        foreach ($artists as $clean => $info) {
            $profile = self::lookup($clean);
            if ($profile) {
                $profiles[$clean] = array_merge($info, ['profile' => $profile]);
            } else {
                $profiles[$clean] = array_merge($info, ['profile' => null]);
            }
        }

        $conflicts = self::detectConflicts($profiles);
        $recommendations = self::generateRecommendations($profiles, $conflicts);
        $warnings = self::generateWarnings($profiles, $recommendations);
        $stats = self::computeStats($profiles);

        return [
            'artists'         => $profiles,
            'conflicts'       => $conflicts,
            'recommendations' => $recommendations,
            'warnings'        => $warnings,
            'stats'           => $stats,
        ];
    }

    /**
     * 从 pairs 抽出画师
     * 两种来源：
     *   1. category='artist' 分类里的所有 tag（包括 {artist:xxx} 解析后的）
     *   2. 任何 clean 名是画像库里的画师（即使没在 artist 分类）
     */
    private static function extractArtists(array $pairs): array {
        $out = [];
        foreach ($pairs as $p) {
            $clean = strtolower(trim($p['clean'] ?? ''));
            $name  = strtolower(trim($p['name'] ?? ''));
            $category = $p['category'] ?? '';
            if (!$clean && !$name) continue;

            // 来源 1: artist 分类
            if ($category === 'artist') {
                // clean 已经是剥过前缀的（如 "ciloranko"），直接查
                $profile = self::lookup($clean);
                if ($profile) {
                    $out[$clean] = [
                        'clean'  => $clean,
                        'raw'    => $p['name'] ?? $clean,
                        'weight' => $p['weight'] ?? 1.0,
                    ];
                }
                continue;
            }

            // 来源 2: clean 或 name 在画像库（无前缀的画师名）
            $profile = self::lookup($clean) ?? self::lookup($name);
            if ($profile) {
                $useClean = $profile === self::lookup($clean) ? $clean : $name;
                $out[$useClean] = [
                    'clean'  => $useClean,
                    'raw'    => $p['name'] ?? $useClean,
                    'weight' => $p['weight'] ?? 1.0,
                ];
            }
        }
        return $out;
    }

    /**
     * 查画像库（处理 alias）
     */
    public static function lookup(string $clean): ?array {
        $clean = strtolower(trim($clean));
        if (isset(self::ARTIST_DB[$clean])) {
            $entry = self::ARTIST_DB[$clean];
            if (isset($entry['alias_of'])) {
                return self::lookup($entry['alias_of']);
            }
            return $entry;
        }
        return null;
    }

    /**
     * 冲突检测：两两对比
     */
    private static function detectConflicts(array $profiles): array {
        $conflicts = [];
        $items = array_values($profiles);
        $n = count($items);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $items[$i]['profile'];
                $b = $items[$j]['profile'];
                if (!$a || !$b) continue;
                // 显式 conflicts
                $aConflicts = $a['conflicts'] ?? [];
                $bConflicts = $b['conflicts'] ?? [];
                if (in_array($items[$j]['clean'], $aConflicts, true) ||
                    in_array($items[$i]['clean'], $bConflicts, true)) {
                    $conflicts[] = [
                        'a'        => $items[$i]['clean'],
                        'b'        => $items[$j]['clean'],
                        'reason'   => self::STYLES[$a['style']] . ' × ' . self::STYLES[$b['style']] . ' 风格差距大',
                        'severity' => 'high',
                    ];
                    continue;
                }
                // 隐式：风格标签差太远（如 realistic vs thick_anime）
                $diff = self::styleDistance($a['style'], $b['style']);
                if ($diff >= 2) {
                    $conflicts[] = [
                        'a'        => $items[$i]['clean'],
                        'b'        => $items[$j]['clean'],
                        'reason'   => self::STYLES[$a['style']] . ' 与 ' . self::STYLES[$b['style']] . ' 出图风格不一致',
                        'severity' => 'medium',
                    ];
                }
            }
        }
        return $conflicts;
    }

    /**
     * 风格距离（越大越冲突）
     * 0=相同, 1=相近, 2=冲突, 3=严重冲突
     */
    private static function styleDistance(string $a, string $b): int {
        if ($a === $b) return 0;
        $close = [
            'thick_anime' => ['illustration', 'soft_anime'],
            'soft_anime'  => ['thick_anime', 'illustration'],
            'illustration' => ['thick_anime', 'soft_anime', 'dark'],
            'dark'         => ['illustration', 'realistic'],
            'realistic'    => ['cinematic', 'dark', 'classic'],
            'cinematic'    => ['realistic', 'classic'],
            'classic'      => ['realistic', 'cinematic'],
        ];
        $bFriends = $close[$b] ?? [];
        if (in_array($a, $bFriends, true)) return 1;
        // 写实 vs 二次元 - 必冲突
        $isAnimeA = in_array($a, ['thick_anime', 'soft_anime', 'illustration']);
        $isAnimeB = in_array($b, ['thick_anime', 'soft_anime', 'illustration']);
        if ($isAnimeA !== $isAnimeB) return 2;
        return 1;
    }

    /**
     * 协同推荐
     */
    private static function generateRecommendations(array $profiles, array $conflicts): array {
        $recs = [];
        $count = count($profiles);

        // 1) 单画师 → 推荐加 1-2 个同风格
        if ($count === 1) {
            $only = array_values($profiles)[0];
            $profile = $only['profile'];
            if ($profile && !empty($profile['companions'])) {
                $suggested = array_slice($profile['companions'], 0, 2);
                $recs[] = [
                    'type'     => 'companion',
                    'level'    => 'info',
                    'message'  => "画师「{$only['clean']}」是 " . self::STYLES[$profile['style']] . " 风格，加 1-2 个同风格画师可让出图更稳：",
                    'suggested'=> $suggested,
                ];
            }
        }

        // 2) 多画师无冲突 → 建议用 {a::b::c} 混合权重
        if ($count >= 2 && empty($conflicts)) {
            $names = array_map(fn($p) => $p['clean'], $profiles);
            $recs[] = [
                'type'    => 'syntax',
                'level'   => 'tip',
                'message' => "多画师推荐用 NAI 混合权重语法：",
                'suggested_syntax' => '{' . implode('::', $names) . '}',
                'note'    => '这种格式让 NAI 内部按权重融合，比逗号分隔更稳',
            ];
        }

        // 3) 有冲突 → 给出排除建议
        if (!empty($conflicts)) {
            $highSeverity = array_filter($conflicts, fn($c) => $c['severity'] === 'high');
            if (!empty($highSeverity)) {
                $worst = $highSeverity[0];
                $recs[] = [
                    'type'    => 'remove_conflict',
                    'level'   => 'warning',
                    'message' => "「{$worst['a']}」和「{$worst['b']}」风格冲突（{$worst['reason']}），建议删一个",
                ];
            }
        }

        // 4) 全部画师都是同一风格 → 提醒"风格很纯"（或太单调）
        $styles = array_unique(array_map(fn($p) => $p['profile']['style'] ?? 'unknown', $profiles));
        $styles = array_filter($styles, fn($s) => $s !== 'unknown');
        if ($count >= 2 && count($styles) === 1) {
            $recs[] = [
                'type'    => 'pure_style',
                'level'   => 'tip',
                'message' => '所有画师都是同一风格（' . self::STYLES[array_values($styles)[0]] . '），出图风格非常纯。',
                'note'    => '可以接受但缺少变化；如果想要更"杂"或更"丰富"的效果，可以加一个不同风格的画师',
            ];
        }

        // 5) 有低级 (tier D) 画师 → 推荐替换
        $lowTier = array_filter($profiles, fn($p) => ($p['profile']['tier'] ?? 'A') === 'D' || ($p['profile']['rank'] ?? 1) >= 4);
        if (!empty($lowTier)) {
            foreach ($lowTier as $p) {
                $recs[] = [
                    'type'    => 'replace',
                    'level'   => 'info',
                    'message' => "「{$p['clean']}」在 Danbooru 排名较低（rank {$p['profile']['rank']}），NAI 训练数据可能不足。",
                ];
            }
        }

        return $recs;
    }

    /**
     * 数量/质量警告
     */
    private static function generateWarnings(array $profiles, array $recommendations): array {
        $warnings = [];
        $count = count($profiles);

        if ($count === 0) {
            $warnings[] = [
                'level'   => 'info',
                'message' => '未检测到任何已知画师',
            ];
            return $warnings;
        }

        if ($count > 5) {
            $warnings[] = [
                'level'   => 'warning',
                'message' => "画师串里有 {$count} 个画师，超过 5 个容易互相干扰，建议精简到 2-4 个",
            ];
        } elseif ($count === 1) {
            $warnings[] = [
                'level'   => 'tip',
                'message' => '只有 1 个画师 - 出图风格稳定但缺少变化',
            ];
        } elseif ($count === 2) {
            $warnings[] = [
                'level'   => 'tip',
                'message' => '2 个画师 - 推荐组合，NAI 出图最稳',
            ];
        } elseif ($count >= 3 && $count <= 4) {
            $warnings[] = [
                'level'   => 'tip',
                'message' => "{$count} 个画师 - 数量合理，建议用 {a::b::c} 混合权重",
            ];
        }

        return $warnings;
    }

    /**
     * 统计
     */
    private static function computeStats(array $profiles): array {
        $count = count($profiles);
        $styles = [];
        foreach ($profiles as $p) {
            $s = $p['profile']['style'] ?? 'unknown';
            $styles[$s] = ($styles[$s] ?? 0) + 1;
        }
        arsort($styles);
        $primary = $styles ? array_key_first($styles) : 'unknown';
        return [
            'count'          => $count,
            'unique_styles'  => count($styles),
            'primary_style'  => $primary,
            'style_breakdown'=> $styles,
        ];
    }
}
