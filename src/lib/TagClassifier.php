<?php
/**
 * NAI Studio - Prompt decomposer
 *
 * 把一串 NAI 提示词按 12 大类拆开：
 *   1. 画师串     artist / by_xxx
 *   2. 角色       character / copyright
 *   3. 人物描述   1girl / 1boy / solo
 *   4. 头发       long_hair / twintails
 *   5. 眼睛       blue_eyes / closed_eyes
 *   6. 表情       smile / blush / open_mouth
 *   7. 姿势动作   standing / sitting / arms_up
 *   8. 手部动作   peace_sign / holding / hand_on_hip
 *   9. 服装配饰   dress / school_uniform / hat
 *  10. 身体特征   small_breasts / petite / freckles
 *  11. 背景场景   outdoors / forest / simple_background
 *  12. 视角/质量  from_side / masterpiece / best_quality
 *
 * 数据来源优先级：
 *   a. Danbooru category 字段（来自 danbooru_tag_cache 表，最准）
 *   b. TagDict 字典里的语义分类
 *   c. 启发式规则（artist: 前缀、by_xxx 形式、常见画师/角色白名单）
 *
 * 用法：
 *   $r = TagClassifier::classify('masterpiece, 1girl, long_hair, ...');
 *   // ['categories' => [...], 'tags' => [...], 'stats' => [...]]
 */

declare(strict_types=1);

namespace NaiStudio;

class TagClassifier {

    /** 12 大类（UI 显示用） */
    public const CATEGORIES = [
        'artist'      => ['name' => '画师串',  'icon' => '🎨', 'order' => 1],
        'character'   => ['name' => '角色',    'icon' => '👤', 'order' => 2],
        'subject'     => ['name' => '人物描述', 'icon' => '🧍', 'order' => 3],
        'hair'        => ['name' => '头发',    'icon' => '💇', 'order' => 4],
        'eyes'        => ['name' => '眼睛',    'icon' => '👁️', 'order' => 5],
        'expression'  => ['name' => '表情',    'icon' => '😊', 'order' => 6],
        'pose'        => ['name' => '姿势动作', 'icon' => '🏃', 'order' => 7],
        'hands'       => ['name' => '手部动作', 'icon' => '✋', 'order' => 8],
        'clothing'    => ['name' => '服装配饰', 'icon' => '👗', 'order' => 9],
        'body'        => ['name' => '身体特征', 'icon' => '🧬', 'order' => 10],
        'background'  => ['name' => '背景场景', 'icon' => '🌳', 'order' => 11],
        'meta'        => ['name' => '视角/质量', 'icon' => '✨', 'order' => 12],
    ];

    /**
     * 启发式分类规则：tag 字符串 → 分类 key
     * 用于 DB 查不到、字典也查不到时的 fallback。
     */
    private const HEURISTIC_KEYWORDS = [
        'expression' => [
            'smile','grin','smirk','frown','angry','sad','cry','happy','surprised',
            'embarrassed','scared','worried','confused','disappointed','disgusted',
            'bored','sleepy','tired','serious','smug','evil_smile','sad_smile',
            'gentle_smile','blush','nose_blush','ear_blush','tears','tear','crying',
            'crying_with_eyes_open','open_mouth','closed_mouth','parted_lips','tongue',
            'tongue_out','fang','fangs','sharp_fangs','eye_contact','looking_at_viewer',
            'looking_away','looking_back','looking_up','looking_down','looking_to_the_side',
            'eye_contact_only',
        ],
        'pose' => [
            'standing','sitting','kneeling','squatting','crouching','lying',
            'lying_on_back','lying_on_stomach','lying_on_side','on_side',
            'leaning_forward','leaning_back','leaning_to_the_side','walking','running',
            'jumping','crossed_legs','cross_leg','sitting_cross-legged','sitting_cross_legged',
            'sitting_on_ground','sitting_on_chair','sitting_on_lap','on_stomach','all_fours',
            'upright','reclining','arched_back','hunched','arm_support','armpit','armpits',
            'spread_legs','fighting_stance','fighting','combat','battle',
        ],
        'hands' => [
            'peace_sign','thumbs_up','pointing','waving','hand_on_hip','hand_on_own_hip',
            'hand_on_own_face','hand_up','hands_up','arms_behind_back','arms_crossed',
            'crossed_arms','legs_crossed','hands_on_hips','hand_to_mouth','hands_clasped',
            'hands_in_pockets','holding','holding_weapon','holding_sword','holding_book',
            'looking_at_object',
        ],
        'hair' => [
            'long_hair','short_hair','medium_hair','very_long_hair','absurdly_long_hair',
            'hair_between_eyes','sidelocks','twintails','ponytail','braid','single_braid',
            'twin_braids','short_twintails','low_twintails','blunt_bangs','bangs',
            'hair_ribbon','hair_bow','hairband','white_hair','black_hair','blonde_hair',
            'brown_hair','red_hair','blue_hair','green_hair','pink_hair','purple_hair',
            'silver_hair','grey_hair','aqua_hair','short_hair_with_long_locks',
        ],
        'eyes' => [
            'blue_eyes','red_eyes','green_eyes','purple_eyes','golden_eyes','brown_eyes',
            'pink_eyes','grey_eyes','aqua_eyes','black_eyes','heterochromia',
            'closed_eyes','half-closed_eyes','narrowed_eyes','wide_eyes',
        ],
        'subject' => [
            '1girl','1boy','1other','2girls','2boys','multiple_girls','multiple_boys',
            '2people','3people','4people','5people','6people','group','couple',
            'yuri','yaoi','hetero','solo','child','teenager','adult','old','young',
        ],
        'clothing' => [
            'dress','skirt','long_sleeves','short_sleeves','sleeveless','school_uniform',
            'sailor_uniform','blouse','shirt','t-shirt','pants','jeans','shorts',
            'swimsuit','bikini','one-piece_swimsuit','kimono','maid','maid_uniform',
            'armor','plate_armor','leotard','bodysuit','lingerie','underwear','bra',
            'panties','panties_under_clothes','thighhighs','knee-high_socks',
            'white_thighhighs','black_thighhighs','pantyhose','black_pantyhose',
            'white_pantyhose','shoes','boots','high_heels','gloves','hat','cap',
            'hood','hooded_cloak','cloak','cape','coat','jacket','cardigan','sweater',
            'cardigan_sweater','vest','tie','necktie','bow','bowtie','ribbon',
            'hair_ornament','hair_flower','flower','rose','cherry_blossoms',
            'necklace','earrings','ring','bracelet','choker','apron','hairpin',
            'glasses','sunglasses','eyepatch','halo','wings','horns','animal_ears',
            'cat_ears','dog_ears','fox_ears','rabbit_ears','cat_tail','fox_tail',
            'animal_tail','tail','rabbit_tail','elf_ears','pointy_ears','demon_horns',
            'dragon_horns','fang_out','halo_above_head','crown','tiara','veil',
            'chinese_clothes','hanfu','japanese_clothes','miko','witch','princess',
            'butterfly',
        ],
        'body' => [
            'small_breasts','medium_breasts','large_breasts','huge_breasts','flat_chest',
            'petite','tall','slim','muscular','freckles','mole','beauty_mark',
            'scar','tattoo','sweat','sweatdrop','blood','injury','bandage','bandaid',
        ],
        'background' => [
            'simple_background','white_background','black_background','blue_background',
            'sky','cloud','clouds','outdoors','indoors','night','day','sunset','sunrise',
            'forest','beach','ocean','city','street','room','bedroom','bathroom',
            'kitchen','school','classroom','library','water','snow','rain','fire',
            'stars','starry_sky','night_sky','sunlight','moon','moonlight',
            'window','door','chair','bed','table','couch','bench',
        ],
        'meta' => [
            'from_side','from_behind','from_above','from_below','from_front',
            'close-up','upper_body','portrait','cowboy_shot','full_body',
            'wide_shot','extreme_close-up','profile','facing_viewer','facing_away',
            'turned_away','pov','masterpiece','best_quality','high_quality',
            'amazing_quality','absurdres','highres','4k','8k','wallpaper',
            'illustration','painting','sketch','traditional_media','digital','3d',
            'realistic','anime','manga','comic','game_cg','pixel_art','chibi','sd',
            'cinematic','studio_lighting','depth_of_field','bokeh','fisheye',
            'macro','telephoto','dark','light','bright','shadow','lighting',
            'glow','glowing','lens_flare','sunbeam','partially_lit','lit','dim',
            'spotlight','silhouette','rim_light','backlighting','moody',
        ],
    ];

    /**
     * Danbooru category 字段 → 我们的分类
     * 0=general, 1=artist, 3=copyright, 4=character, 5=meta
     */
    private const DANBOORU_CATEGORY_MAP = [
        1 => 'artist',
        3 => 'character',   // copyright 作品名
        4 => 'character',
        5 => 'meta',
        // 0=general，需要二次分类（用 TagDict + heuristic）
    ];

    /**
     * 已知画师白名单（Danbooru 排名 Top 200 常用）
     * 用于无前缀识别（"ciloranko" 单出现也能识别成画师）。
     * 仅放最有把握的，模糊地带扔 'uncategorized'。
     */
    private const ARTIST_WHITELIST = [
        'ciloranko','wlop','greg_rutkowski','makoto_shinkai','ross_tran','sakimichan',
        'ask','as109','redjuice','shal.e','ilya_kuvshinov','ayumi_kasuga','ke-ta',
        'huke','fuzichoco','lack','refeia','pottsness','lasterk',
        'sho_(shoswan)','tiv','reiya','esther_alt','mianhua_jiang','canson',
        'ricegnat','veea','loiza','mitsu_(sodium)','sakimori_(danboru)','hirokiku',
        'kawacy','bbjun','tarakon','pixiv_id','tofu_fubuki','aoi_ogata',
        'range_murata','creayus','jhony_configman','kino_juusou','suzuna_midori',
        'freng','vayne_aming','matisse','yunsang','kspaint','mond_(mmarjy)','dino',
        'daeralove','jelly_kim','dandon_fuga','zombi_(mau_zombi)','almible',
        'hatsuki_neo','ariverkao','feli_(paionii)','la-na','taro_(taro_2932)',
        'orobou','kainown','minamiconcept','hyo-ka','bluerain_std','moufu',
        'kaworu','lighthaus','ningmengchudian','geso_(twrlare)','ainy','nyoon',
        'hase_yu','lulu_(pixiv2764093)','shimmer','yuki_noa','tk_(kuma)',
        'shilin','yukimai','lilith_x','rotten_(hkr0320)','celeste_(artist)',
        'ohako','pochi_(pochi-gma)','yoshio_(55level)','neko_(nekorl)',
        'mememe','weno','doyou_want_to_try','lyra_(pixiv_4394113)','moxuan',
    ];

    /**
     * 已知角色白名单（识别单独出现的角色名，无前缀时用）
     */
    private const CHARACTER_WHITELIST = [
        // Touhou
        'hakurei_reimu','kirisame_marisa','patchouli_knowledge','remilia_scarlet',
        'flandre_scarlet','sakuya_izayoi','sanae_kochiya','nitori_kawashiro',
        'cirno','reisen_udongein_inaba','yuyuko_saigyouji','youmu_konpaku',
        'alice_margatroid','hatsune_miku','kagamine_rin','kagamine_len',
        'megurine_luka','meiko','kaito',
        // Fate
        'saber','saber_alter','illyasviel_von_einzbern','tohsaka_rin','archer',
        'emiya_shirou','matou_sakura','medusa','rin_tohsaka','jeanne_d\'arc',
        'astolfo','mordred','nero_claudius','okita_souji',
        // Genshin
        'amber_(genshin_impact)','barbara_(genshin_impact)','diluc','jean_(genshin_impact)',
        'kaeya','klee','lisa_(genshin_impact)','mona_(genshin_impact)','razor',
        'venti','xiangling','zhongli','ganyu','hutao','eula','raiden_shogun',
        'nahida','ayaka','yoimiya','albedo_(genshin_impact)','itto',
        // Honkai
        'bronya_zaychik','seele_vollerei','raiden_mei','kiana_kaslana',
        // Kancolle
        'akagi','kaga','shimakaze','atago','takao',
        // Re:Zero
        'emilia','rem','ram','natsuki_rem','natsuki_ram',
        // Azur Lane
        'enterprise','akagi_chan','belfast','ayanami','javelin',
        // Vocaloid
        'hatsune_miku','kagamine_rin','kagamine_len','megurine_luka','gumi',
        // Galgame 常见
        'yukino_yukinoshita','sakurauchi_riko','makinohara_shoko','yui_kotegawa',
        // 通用
        'kizuna_ai','kizunaai',
    ];

    /**
     * 把一整段 prompt 拆成 12 大类。
     *
     * @return array{
     *   categories: array<key, {name:string, icon:string, count:int, tags:array}>,
     *   tags: array<{name:string, raw:string, weight:float, category:key, cn:string|null, danbooru_category:?int, source:string}>,
     *   stats: {total:int, classified:int, unclassified:int}
     * }
     */
    public static function classify(string $prompt): array {
        $tokens = Splitter::split($prompt);

        $out = [];
        foreach (self::CATEGORIES as $key => $meta) {
            $out[$key] = [
                'name'  => $meta['name'],
                'icon'  => $meta['icon'],
                'order' => $meta['order'],
                'count' => 0,
                'tags'  => [],
            ];
        }
        $out['uncategorized'] = [
            'name'  => '未识别',
            'icon'  => '❓',
            'order' => 99,
            'count' => 0,
            'tags'  => [],
        ];

        $classifiedCount = 0;
        $unclassifiedCount = 0;

        // 把所有 token 展开成扁平 tag 列表
        //   - text 节点按逗号 split（保留逗号）
        //   - brace/paren/bracket 节点直接作为 tag
        $flatTags = [];
        foreach ($tokens as $t) {
            if (!empty($t['hidden'])) continue;
            if (empty($t['text'])) {
                // brace/paren/bracket 节点
                if ($t['tag'] === '') continue;
                $flatTags[] = [
                    'name'   => $t['name'] ?? $t['raw'],
                    'tag'    => $t['tag'],
                    'weight' => $t['weight'],
                    'raw'    => $t['raw'],
                ];
                continue;
            }
            // text 节点：按逗号 split
            $chunks = self::splitByCommas($t['raw']);
            foreach ($chunks as $c) {
                $name = trim($c);
                if ($name === '' || $name === ',') continue;
                // 剥前缀权重 N::tag → tag, weight=N（无 {} 的 Danbooru 风格权重）
                $tagName = $name;
                $tagWeight = 1.0;
                if (preg_match('/^(\d+(?:\.\d+)?)::(.+)$/', $name, $m)) {
                    $tagName = trim($m[2]);
                    $tagWeight = (float)$m[1];
                }
                $flatTags[] = [
                    'name'   => $tagName,
                    'tag'    => $tagName,
                    'weight' => $tagWeight,
                    'raw'    => $c,
                ];
            }
            // 上面的 chunks 已 trim 并去逗号，下面是 noop（保留原 raw）
        }

        foreach ($flatTags as $t) {
            $r = self::classifyTag($t['tag'], $t['weight'], $t['raw']);
            // 把显示名替换为原始大小写（如果有）
            $r['name'] = $t['name'];
            $out[$r['category']]['tags'][] = $r;
            $out[$r['category']]['count']++;
            if ($r['category'] === 'uncategorized') $unclassifiedCount++;
            else $classifiedCount++;
        }

        // 按 order 排序
        uasort($out, fn($a, $b) => ($a['order'] ?? 99) <=> ($b['order'] ?? 99));

        return [
            'categories' => $out,
            'stats'      => [
                'total'         => $classifiedCount + $unclassifiedCount,
                'classified'    => $classifiedCount,
                'unclassified'  => $unclassifiedCount,
            ],
        ];
    }

    /**
     * 按逗号切字符串，不保留逗号（逗号作为分隔符丢掉）。
     * 跳过 brace/paren/bracket 内的逗号（NAI 权重里可能有）。
     */
    private static function splitByCommas(string $s): array {
        $out = [];
        $buf = '';
        $depth = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $ch = $s[$i];
            if ($ch === '{' || $ch === '(' || $ch === '[') $depth++;
            elseif ($ch === '}' || $ch === ')' || $ch === ']') $depth--;
            if ($ch === ',' && $depth === 0) {
                $chunk = trim($buf);
                if ($chunk !== '') $out[] = $chunk;
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        $chunk = trim($buf);
        if ($chunk !== '') $out[] = $chunk;
        return $out;
    }

    /**
     * 单个 tag 分类逻辑（内部使用）
     */
    private static function classifyTag(string $name, float $weight, string $raw): array {
        $original = $name;
        $cn = null;
        $source = 'unclassified';
        $dbCategory = null;

        // 1) 解析前缀：artist:xxx / character:xxx / copyright:xxx / meta:xxx
        $prefixCategory = null;
        $cleanName = $name;
        if (preg_match('/^(artist|character|copyright|meta):(.+)$/i', $name, $m)) {
            $prefixCategory = strtolower($m[1]);
            $cleanName = strtolower($m[2]);
            if ($prefixCategory === 'artist') {
                return self::result($original, $raw, $weight, 'artist', $cleanName, 'prefix');
            }
            if ($prefixCategory === 'character' || $prefixCategory === 'copyright') {
                return self::result($original, $raw, $weight, 'character', $cleanName, 'prefix');
            }
            if ($prefixCategory === 'meta') {
                // meta: 前缀的 tag 一般是 uc preset 之类 → 走质量/视角分类
                $cn = TagDict::lookup($cleanName) ?? ucwords(str_replace('_', ' ', $cleanName));
                $source = 'prefix';
                // 走一次分类逻辑
                $cat = self::classifyClean($cleanName, $cn, $dbCategory, $source);
                return self::result($original, $raw, $weight, $cat, $cleanName, $source, $cn, $dbCategory);
            }
        }

        $cleanName = strtolower(trim($name));

        // 2) DB 查 danbooru_tag_cache（最准）
        try {
            $row = Db::fetchOne(
                "SELECT name, cn_name, category FROM danbooru_tag_cache WHERE name = ? LIMIT 1",
                [$cleanName]
            );
            if ($row) {
                $dbCategory = (int)$row['category'];
                $cn = $row['cn_name'] ?: null;
                $source = 'danbooru';

                // 画师/角色直接确定
                if ($dbCategory === 1) {
                    return self::result($original, $raw, $weight, 'artist', $cleanName, $source, $cn, $dbCategory);
                }
                if ($dbCategory === 4 || $dbCategory === 3) {
                    return self::result($original, $raw, $weight, 'character', $cleanName, $source, $cn, $dbCategory);
                }
                if ($dbCategory === 5) {
                    // meta 类（masterpiece 等）→ 走 meta
                    return self::result($original, $raw, $weight, 'meta', $cleanName, $source, $cn, $dbCategory);
                }
                // general (0)：继续用下面的启发式细分
                $cat = self::classifyClean($cleanName, $cn, $dbCategory, $source);
                return self::result($original, $raw, $weight, $cat, $cleanName, $source, $cn, $dbCategory);
            }
        } catch (\Throwable $e) {
            // DB 不可用时静默
        }

        // 3) 启发式：白名单
        if (in_array($cleanName, self::ARTIST_WHITELIST, true)) {
            return self::result($original, $raw, $weight, 'artist', $cleanName, 'whitelist');
        }
        if (in_array($cleanName, self::CHARACTER_WHITELIST, true)) {
            return self::result($original, $raw, $weight, 'character', $cleanName, 'whitelist');
        }

        // 4) TagDict + 关键词启发式
        $cat = self::classifyClean($cleanName, $cn, null, 'heuristic');
        return self::result($original, $raw, $weight, $cat, $cleanName, 'heuristic', $cn, null);
    }

    /**
     * 干净的 tag 名 → 分类 key（用于 general 类细分）
     */
    private static function classifyClean(string $name, ?string $cn, ?int $dbCategory, string $source): string {
        // 按关键词命中
        foreach (self::HEURISTIC_KEYWORDS as $catKey => $keywords) {
            if (in_array($name, $keywords, true)) return $catKey;
        }
        // TagDict 命中：如果字典里这个 tag 存在，按字典的"语义分类"映射
        // （TagDict 当前没有内部分类标记，所以这步先跳过，等扩展）

        // 启发式 fallback：名词风格
        if (str_ends_with($name, '_hair'))  return 'hair';
        if (str_ends_with($name, '_eyes'))  return 'eyes';
        if (str_ends_with($name, 'skirt') || str_ends_with($name, 'dress') || str_ends_with($name, 'uniform') || str_ends_with($name, 'shirt') || str_ends_with($name, 'pants') || str_ends_with($name, 'socks') || str_ends_with($name, 'shoes') || str_ends_with($name, 'boots') || str_ends_with($name, 'gloves') || str_ends_with($name, 'hat') || str_ends_with($name, 'cloak') || str_ends_with($name, 'jacket') || str_ends_with($name, 'sweater') || str_ends_with($name, 'necklace') || str_ends_with($name, 'earrings')) {
            return 'clothing';
        }
        if (str_contains($name, 'smile') || str_contains($name, 'mouth')) return 'expression';
        if (str_contains($name, 'tail'))   return 'body';
        if (str_contains($name, 'wing'))   return 'body';
        if (str_contains($name, 'horns'))  return 'body';
        // 知名 IP / 系列
        if (in_array($name, ['vocaloid','utauloid','touhou','idolmaster','love_live','bang_dream','fate_grand_order','fgo','genshin_impact','honkai','azur_lane','arknights','blue_archive','nier','pokemon','kantai_collection','kancolle','touken_ranbu','granblue_fantasy','gbf','fire_emblem','final_fantasy','kingdom_hearts','puyo_puyo'], true)) {
            return 'character';
        }
        // 通用 IP 关键词
        if (str_contains($name, 'vocaloid') || str_contains($name, '_series')) return 'character';

        return 'uncategorized';
    }

    /**
     * 构造结果行
     */
    private static function result(
        string $original, string $raw, float $weight, string $category,
        string $cleanName, string $source, ?string $cn = null, ?int $dbCategory = null
    ): array {
        // 尝试取中文
        if ($cn === null || $cn === '') {
            $cn = TagDict::lookup($cleanName);
        }
        return [
            'name'               => $original,
            'clean'              => $cleanName,
            'raw'                => $raw,
            'weight'             => $weight,
            'category'           => $category,
            'cn'                 => $cn,
            'danbooru_category'  => $dbCategory,
            'source'             => $source,    // danbooru / prefix / whitelist / heuristic / unclassified
        ];
    }
}
