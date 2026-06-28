<?php
/**
 * NAI Studio - 内置 Danbooru 标签中英对照字典
 * 覆盖前 ~500 最常用标签，无需联网即开即用。
 * 命中即返回中文；未命中时调用在线翻译。
 *
 * 维护：可以随时编辑此数组，运行时会被 apcu/DB 缓存加速。
 */

declare(strict_types=1);

namespace NaiStudio;

class TagDict {
    /** @var array<string,string>|null */
    private static ?array $_map = null;

    /** 常用标签字典（按类别分组，方便维护）。下划线等价空格。 */
    private const DICT = [
        // ===== 角色 / 数量 =====
        '1girl' => '1个女孩', '1boy' => '1个男孩', 'solo' => '独自',
        '2girls' => '2个女孩', '2boys' => '2个男孩', 'multiple_girls' => '多个女孩',
        'multiple_boys' => '多个男孩', '1other' => '1个其他',
        '2people' => '2人', '3people' => '3人', '4people' => '4人',
        '5people' => '5人', '6people' => '6人', 'group' => '团体',
        'couple' => '情侣', 'yuri' => '百合', 'yaoi' => '耽美',
        'hetero' => '异性恋',

        // ===== 头发 =====
        'long_hair' => '长发', 'short_hair' => '短发', 'medium_hair' => '中发',
        'very_long_hair' => '超长发', 'absurdly_long_hair' => '极长发',
        'hair_between_eyes' => '眼间刘海', 'sidelocks' => '侧发',
        'twintails' => '双马尾', 'ponytail' => '马尾辫',
        'braid' => '辫子', 'single_braid' => '单辫', 'twin_braids' => '双辫',
        'short_twintails' => '短双马尾', 'low_twintails' => '低双马尾',
        'blunt_bangs' => '齐刘海', 'bangs' => '刘海',
        'hair_ribbon' => '发带', 'hair_bow' => '发蝴蝶结',
        'hairband' => '发箍', 'white_hair' => '白发', 'black_hair' => '黑发',
        'blonde_hair' => '金发', 'brown_hair' => '棕发', 'red_hair' => '红发',
        'blue_hair' => '蓝发', 'green_hair' => '绿发', 'pink_hair' => '粉发',
        'purple_hair' => '紫发', 'silver_hair' => '银发', 'grey_hair' => '灰发',
        'aqua_hair' => '水色头发', 'purple_eyes' => '紫眼',
        'short_hair_with_long_locks' => '短发长发帘',

        // ===== 眼睛 =====
        'blue_eyes' => '蓝眼', 'red_eyes' => '红眼', 'green_eyes' => '绿眼',
        'purple_eyes' => '紫眼', 'golden_eyes' => '金眼',
        'brown_eyes' => '棕眼', 'pink_eyes' => '粉眼',
        'grey_eyes' => '灰眼', 'aqua_eyes' => '水色眼睛',
        'black_eyes' => '黑眼', 'heterochromia' => '异色瞳',
        'closed_eyes' => '闭眼', 'half-closed_eyes' => '半闭眼',
        'narrowed_eyes' => '眯眼', 'wide_eyes' => '睁大眼',

        // ===== 表情 / 动作 =====
        'smile' => '微笑', 'grin' => '咧嘴笑', 'smirk' => '得意的笑',
        'frown' => '皱眉', 'angry' => '生气', 'sad' => '悲伤',
        'cry' => '哭', 'happy' => '开心', 'surprised' => '惊讶',
        'embarrassed' => '尴尬', 'scared' => '害怕', 'worried' => '担心',
        'confused' => '困惑', 'disappointed' => '失望', 'disgusted' => '厌恶',
        'bored' => '无聊', 'sleepy' => '困倦', 'tired' => '疲惫',
        'serious' => '严肃', 'smug' => '自满', 'evil_smile' => '邪恶微笑',
        'sad_smile' => '苦笑', 'gentle_smile' => '温柔微笑',
        'blush' => '脸红', 'nose_blush' => '鼻红', 'ear_blush' => '耳朵红',
        'tears' => '泪水', 'tear' => '泪', 'crying' => '哭泣',
        'crying_with_eyes_open' => '睁眼哭泣', 'open_mouth' => '张嘴',
        'closed_mouth' => '闭嘴', 'parted_lips' => '微张嘴',
        'tongue' => '舌头', 'tongue_out' => '吐舌',
        'fang' => '尖牙', 'fangs' => '獠牙', 'sharp_fangs' => '锐利獠牙',
        'eye_contact' => '对视', 'looking_at_viewer' => '看向观众',
        'looking_away' => '看向别处', 'looking_back' => '回头看',
        'looking_up' => '仰望', 'looking_down' => '俯视',
        'looking_to_the_side' => '侧视', 'eye_contact_only' => '只对视',

        // ===== 姿势 =====
        'standing' => '站立', 'sitting' => '坐', 'kneeling' => '跪',
        'lying' => '躺着', 'lying_on_back' => '仰躺', 'lying_on_stomach' => '趴着',
        'walking' => '走路', 'running' => '跑', 'jumping' => '跳',
        'squatting' => '蹲', 'crouching' => '蜷缩',
        'crossed_legs' => '盘腿', 'sitting_on_ground' => '坐在地上',
        'sitting_on_chair' => '坐在椅子上', 'sitting_cross-legged' => '盘腿坐',
        'arm_support' => '手臂支撑', 'leaning_forward' => '前倾',
        'leaning_back' => '后仰', 'hand_on_hip' => '手叉腰',
        'hand_on_own_hip' => '手叉自己腰', 'hand_on_own_face' => '手摸自己脸',
        'hand_up' => '抬手', 'hands_up' => '双手举起',
        'arms_behind_back' => '双手背后', 'arms_crossed' => '双臂交叉',
        'armpit' => '腋下', 'armpits' => '腋下',
        'hands_on_hips' => '双手叉腰', 'hand_to_mouth' => '手捂嘴',
        'hands_clasped' => '双手合十', 'hands_in_pockets' => '手插口袋',
        'peace_sign' => '剪刀手', 'thumbs_up' => '竖大拇指',
        'pointing' => '指向', 'waving' => '挥手',
        'holding' => '拿着', 'holding_weapon' => '拿着武器',
        'holding_sword' => '拿着剑', 'holding_book' => '拿着书',
        'looking_at_object' => '看物品', 'fighting_stance' => '战斗姿态',
        'fighting' => '战斗', 'combat' => '格斗', 'battle' => '战斗',
        'spread_legs' => '张开双腿', 'crossed_arms' => '双臂交叉',
        'legs_crossed' => '二郎腿', 'sitting_on_lap' => '坐在腿上',

        // ===== 服装 =====
        'dress' => '连衣裙', 'skirt' => '裙子', 'long_sleeves' => '长袖',
        'short_sleeves' => '短袖', 'sleeveless' => '无袖',
        'school_uniform' => '校服', 'sailor_uniform' => '水手服',
        'blouse' => '衬衫', 'shirt' => '衬衫', 't-shirt' => 'T恤',
        'pants' => '裤子', 'jeans' => '牛仔裤', 'shorts' => '短裤',
        'swimsuit' => '泳装', 'bikini' => '比基尼', 'one-piece_swimsuit' => '连体泳衣',
        'kimono' => '和服', 'maid' => '女仆', 'maid_uniform' => '女仆装',
        'armor' => '盔甲', 'plate_armor' => '板甲',
        'leotard' => '紧身衣', 'bodysuit' => '连体衣',
        'lingerie' => '内衣', 'underwear' => '内衣', 'bra' => '文胸',
        'panties' => '内裤', 'panties_under_clothes' => '外衣下内裤',
        'thighhighs' => '过膝袜', 'knee-high_socks' => '及膝袜',
        'white_thighhighs' => '白色过膝袜', 'black_thighhighs' => '黑色过膝袜',
        'pantyhose' => '连裤袜', 'black_pantyhose' => '黑色连裤袜',
        'white_pantyhose' => '白色连裤袜',
        'shoes' => '鞋子', 'boots' => '靴子', 'high_heels' => '高跟鞋',
        'gloves' => '手套', 'hat' => '帽子', 'cap' => '鸭舌帽',
        'hood' => '兜帽', 'hooded_cloak' => '连帽斗篷',
        'cloak' => '斗篷', 'cape' => '披风', 'coat' => '外套',
        'jacket' => '夹克', 'cardigan' => '开衫',
        'sweater' => '毛衣', 'cardigan_sweater' => '开衫毛衣',
        'vest' => '马甲', 'tie' => '领带', 'necktie' => '领带',
        'bow' => '蝴蝶结', 'bowtie' => '领结', 'ribbon' => '丝带',
        'hair_ornament' => '头饰', 'hair_flower' => '头花',
        'flower' => '花', 'rose' => '玫瑰', 'cherry_blossoms' => '樱花',
        'necklace' => '项链', 'earrings' => '耳环', 'ring' => '戒指',
        'bracelet' => '手镯', 'choker' => '项圈',
        'apron' => '围裙', 'hairpin' => '发卡',
        'glasses' => '眼镜', 'sunglasses' => '墨镜', 'eyepatch' => '眼罩',
        'halo' => '光环', 'wings' => '翅膀', 'horns' => '角',
        'animal_ears' => '兽耳', 'cat_ears' => '猫耳', 'dog_ears' => '狗耳',
        'fox_ears' => '狐耳', 'rabbit_ears' => '兔耳',
        'cat_tail' => '猫尾', 'fox_tail' => '狐尾', 'animal_tail' => '兽尾',
        'tail' => '尾巴', 'rabbit_tail' => '兔尾',
        'elf_ears' => '精灵耳', 'pointy_ears' => '尖耳',
        'demon_horns' => '恶魔角', 'dragon_horns' => '龙角',
        'fang_out' => '露尖牙',
        'halo_above_head' => '头顶光环', 'crown' => '王冠',
        'tiara' => '头冠', 'veil' => '面纱',
        'chinese_clothes' => '中式服装', 'hanfu' => '汉服',
        'japanese_clothes' => '和式服装', 'miko' => '巫女',
        'witch' => '女巫', 'princess' => '公主',
        'butterfly' => '蝴蝶',

        // ===== 视角 / 镜头 =====
        'from_side' => '侧面', 'from_behind' => '背面', 'from_above' => '俯视',
        'from_below' => '仰视', 'from_front' => '正面',
        'close-up' => '特写', 'upper_body' => '半身', 'portrait' => '肖像',
        'cowboy_shot' => '牛仔镜头', 'full_body' => '全身',
        'wide_shot' => '远景', 'extreme_close-up' => '极特写',
        'looking_at_viewer' => '看向观众', 'eye_contact' => '眼神接触',
        'profile' => '侧脸', 'facing_viewer' => '面对观众',
        'facing_away' => '背对', 'turned_away' => '转身',
        'pov' => '主观视角',

        // ===== 背景 =====
        'simple_background' => '纯色背景', 'white_background' => '白色背景',
        'black_background' => '黑色背景', 'blue_background' => '蓝色背景',
        'sky' => '天空', 'cloud' => '云', 'clouds' => '云朵',
        'outdoors' => '户外', 'indoors' => '室内',
        'night' => '夜晚', 'day' => '白天', 'sunset' => '黄昏',
        'sunrise' => '日出', 'forest' => '森林', 'beach' => '海滩',
        'ocean' => '海洋', 'city' => '城市', 'street' => '街道',
        'room' => '房间', 'bedroom' => '卧室', 'bathroom' => '浴室',
        'kitchen' => '厨房', 'school' => '学校',
        'classroom' => '教室', 'library' => '图书馆',
        'water' => '水', 'snow' => '雪', 'rain' => '雨',
        'fire' => '火', 'stars' => '星星', 'starry_sky' => '星空',
        'night_sky' => '夜空', 'sunlight' => '阳光',
        'moon' => '月亮', 'moonlight' => '月光',
        'window' => '窗户', 'door' => '门',
        'chair' => '椅子', 'bed' => '床', 'table' => '桌子',
        'couch' => '沙发', 'bench' => '长凳',

        // ===== 身体特征 =====
        'small_breasts' => '小胸', 'medium_breasts' => '中胸',
        'large_breasts' => '大胸', 'huge_breasts' => '巨乳',
        'flat_chest' => '平胸',
        'long_hair' => '长发', 'short_hair' => '短发',
        'petite' => '娇小', 'tall' => '高挑',
        'slim' => '苗条', 'muscular' => '肌肉发达',
        'freckles' => '雀斑', 'mole' => '痣', 'beauty_mark' => '美人痣',
        'scar' => '疤痕', 'tattoo' => '纹身',
        'sweat' => '汗水', 'sweatdrop' => '汗滴',
        'blood' => '血', 'injury' => '伤',
        'bandage' => '绷带', 'bandaid' => '创可贴',

        // ===== 灯光 / 氛围 =====
        'dark' => '暗', 'light' => '亮', 'bright' => '明亮',
        'shadow' => '阴影', 'lighting' => '光照',
        'glow' => '发光', 'glowing' => '发光中',
        'lens_flare' => '镜头光晕', 'sunbeam' => '阳光光束',
        'partially_lit' => '部分光照', 'lit' => '已点亮',
        'dim' => '昏暗', 'spotlight' => '聚光灯',
        'silhouette' => '剪影', 'rim_light' => '轮廓光',
        'backlighting' => '逆光', 'moody' => '忧郁氛围',

        // ===== 风格 / 标签 =====
        'masterpiece' => '杰作', 'best_quality' => '最佳质量',
        'high_quality' => '高质量', 'amazing_quality' => '惊艳质量',
        'absurdres' => '超清', 'highres' => '高分辨率',
        '4k' => '4K', '8k' => '8K', 'wallpaper' => '壁纸',
        'illustration' => '插画', 'painting' => '绘画',
        'sketch' => '素描', 'traditional_media' => '传统媒介',
        'digital' => '数字', '3d' => '3D',
        'realistic' => '写实', 'anime' => '动漫',
        'manga' => '漫画', 'comic' => '漫画',
        'game_cg' => '游戏CG', 'pixel_art' => '像素艺术',
        'chibi' => 'Q版', 'sd' => '小比例',

        // ===== 常见元素 =====
        'weapon' => '武器', 'sword' => '剑', 'gun' => '枪',
        'rifle' => '步枪', 'pistol' => '手枪', 'katana' => '武士刀',
        'staff' => '法杖', 'wand' => '魔杖', 'bow' => '弓',
        'arrow' => '箭', 'shield' => '盾牌', 'axe' => '斧',
        'spear' => '长矛', 'hammer' => '锤', 'knife' => '刀',
        'magic' => '魔法', 'spell' => '咒语', 'energy' => '能量',
        'fire' => '火', 'water' => '水', 'ice' => '冰',
        'lightning' => '闪电', 'wind' => '风', 'earth' => '土',
        'healing' => '治疗', 'shield' => '盾',
        'blood' => '血',
        'gem' => '宝石', 'crystal' => '水晶',
        'gold' => '黄金', 'silver' => '白银',
        'rose' => '玫瑰', 'flower' => '花',
        'tree' => '树', 'flower' => '花',
        'mountain' => '山', 'castle' => '城堡',
        'tower' => '塔', 'bridge' => '桥',
        'waterfall' => '瀑布',
        'moon' => '月亮', 'sun' => '太阳',
        'star' => '星星',

        // ===== 物种 =====
        'elf' => '精灵', 'demon' => '恶魔', 'angel' => '天使',
        'vampire' => '吸血鬼', 'witch' => '女巫', 'mage' => '法师',
        'knight' => '骑士', 'samurai' => '武士', 'ninja' => '忍者',
        'pirate' => '海盗', 'cowboy' => '牛仔', 'soldier' => '士兵',
        'maid' => '女仆', 'butler' => '男仆', 'princess' => '公主',
        'prince' => '王子', 'king' => '国王', 'queen' => '女王',
        'dragon' => '龙', 'cat' => '猫', 'dog' => '狗',
        'fox' => '狐狸', 'wolf' => '狼', 'rabbit' => '兔子',
        'cat_girl' => '猫娘', 'fox_girl' => '狐娘',
        'wolf_girl' => '狼娘',

        // ===== 画风/质量 =====
        'realistic' => '写实',
        'cinematic' => '电影感',
        'studio_lighting' => '影棚光',
        'depth_of_field' => '景深',
        'bokeh' => '散景',
        'fisheye' => '鱼眼',
        'macro' => '微距',
        'telephoto' => '长焦',
    ];

    /**
     * 查表（大小写不敏感，下划线/空格等价）。
     * @return string|null 中文，找不到返回 null
     */
    public static function lookup(string $enName): ?string {
        if (self::$_map === null) {
            self::$_map = [];
            foreach (self::DICT as $k => $v) {
                self::$_map[strtolower($k)] = $v;
                self::$_map[strtolower(str_replace('_', ' ', $k))] = $v;
            }
        }
        $key1 = strtolower(trim($enName));
        $key2 = strtolower(str_replace('_', ' ', trim($enName)));
        return self::$_map[$key1] ?? self::$_map[$key2] ?? null;
    }
}