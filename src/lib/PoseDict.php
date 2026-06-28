<?php
/**
 * 姿势/动作词库 - curated 列表
 *
 * 原则：
 *   - 全部带中文（不依赖 tags 表 / MyMemory）
 *   - 按 Danbooru 真实 post_count 排序，挑最常用的
 *   - 英文是 Danbooru 标准 tag 形式（snake_case）
 *
 * 数据来源：Danbooru wiki + 实际高频 tag 统计
 * 不在这里存 post_count，调用方可以从 tags 表 JOIN 拿。
 */

declare(strict_types=1);

namespace NaiStudio;
class PoseDict
{
    /** @var array<string, array<string, array{en:string, cn:string}>> */
    private static array $categories = [
        '基础姿势' => [
            ['en' => 'standing', 'cn' => '站立'],
            ['en' => 'sitting', 'cn' => '坐'],
            ['en' => 'kneeling', 'cn' => '跪'],
            ['en' => 'squatting', 'cn' => '蹲'],
            ['en' => 'crouching', 'cn' => '蜷缩'],
            ['en' => 'lying', 'cn' => '躺着'],
            ['en' => 'lying_on_back', 'cn' => '仰躺'],
            ['en' => 'lying_on_stomach', 'cn' => '趴着'],
            ['en' => 'lying_on_side', 'cn' => '侧躺'],
            ['en' => 'on_side', 'cn' => '侧卧'],
            ['en' => 'leaning_forward', 'cn' => '前倾'],
            ['en' => 'leaning_back', 'cn' => '后仰'],
            ['en' => 'leaning_to_the_side', 'cn' => '侧倾'],
            ['en' => 'cross_leg', 'cn' => '盘腿'],
            ['en' => 'crossed_legs', 'cn' => '交叉双腿'],
            ['en' => 'sitting_cross_legged', 'cn' => '盘腿坐'],
            ['en' => 'sitting_on_ground', 'cn' => '坐在地上'],
            ['en' => 'sitting_on_chair', 'cn' => '坐在椅子上'],
            ['en' => 'sitting_on_lap', 'cn' => '坐在腿上'],
            ['en' => 'on_stomach', 'cn' => '俯卧'],
            ['en' => 'all_fours', 'cn' => '四肢着地'],
            ['en' => 'upright', 'cn' => '直立'],
            ['en' => 'reclining', 'cn' => '斜倚'],
            ['en' => 'arched_back', 'cn' => '弓背'],
            ['en' => 'hunched', 'cn' => '弓身'],
        ],

        '上下肢动作' => [
            ['en' => 'arms_up', 'cn' => '举双手'],
            ['en' => 'hands_up', 'cn' => '双手举起'],
            ['en' => 'hand_up', 'cn' => '抬手'],
            ['en' => 'one_hand_up', 'cn' => '单手举起'],
            ['en' => 'arms_raised', 'cn' => '双臂抬起'],
            ['en' => 'arms_behind_back', 'cn' => '双手背后'],
            ['en' => 'arms_crossed', 'cn' => '双臂交叉'],
            ['en' => 'arms_at_sides', 'cn' => '双臂垂放'],
            ['en' => 'arms_around_waist', 'cn' => '搂腰'],
            ['en' => 'arm_around_shoulder', 'cn' => '搂肩'],
            ['en' => 'arm_around_neck', 'cn' => '搂脖子'],
            ['en' => 'arm_held_back', 'cn' => '手背在身后'],
            ['en' => 'outstretched_arms', 'cn' => '张开双臂'],
            ['en' => 'outstretched_hand', 'cn' => '伸出一只手'],
            ['en' => 'outstretched_arm', 'cn' => '伸出一只手臂'],
            ['en' => 'hand_on_hip', 'cn' => '手叉腰'],
            ['en' => 'hand_on_own_hip', 'cn' => '手叉自己腰'],
            ['en' => 'hand_on_own_face', 'cn' => '手摸自己脸'],
            ['en' => 'hand_on_own_chest', 'cn' => '手捂胸口'],
            ['en' => 'hand_on_own_knee', 'cn' => '手放膝盖'],
            ['en' => 'hand_to_mouth', 'cn' => '手捂嘴'],
            ['en' => 'hand_on_chin', 'cn' => '手托下巴'],
            ['en' => 'hands_on_hips', 'cn' => '双手叉腰'],
            ['en' => 'hands_clasped', 'cn' => '双手合十'],
            ['en' => 'hands_in_pockets', 'cn' => '手插口袋'],
            ['en' => 'hands_behind_back', 'cn' => '双手背后'],
            ['en' => 'hands_on_own_knees', 'cn' => '双手放膝盖'],
            ['en' => 'spread_legs', 'cn' => '张开双腿'],
            ['en' => 'legs_apart', 'cn' => '双腿分开'],
            ['en' => 'legs_together', 'cn' => '双腿并拢'],
            ['en' => 'knee_up', 'cn' => '抬膝'],
            ['en' => 'foot_up', 'cn' => '抬脚'],
            ['en' => 'kicking', 'cn' => '踢腿'],
        ],

        '手势' => [
            ['en' => 'peace_sign', 'cn' => '剪刀手'],
            ['en' => 'thumbs_up', 'cn' => '竖大拇指'],
            ['en' => 'thumbs_down', 'cn' => '拇指向下'],
            ['en' => 'pointing', 'cn' => '指向'],
            ['en' => 'pointing_at_viewer', 'cn' => '指向观众'],
            ['en' => 'pointing_up', 'cn' => '指向上方'],
            ['en' => 'pointing_forward', 'cn' => '指向前方'],
            ['en' => 'pointing_to_the_side', 'cn' => '指向侧面'],
            ['en' => 'waving', 'cn' => '挥手'],
            ['en' => 'beckoning', 'cn' => '招手过来'],
            ['en' => 'fist', 'cn' => '握拳'],
            ['en' => 'clenched_hand', 'cn' => '紧握拳头'],
            ['en' => 'open_hand', 'cn' => '张开手掌'],
            ['en' => 'open_palm', 'cn' => '摊开手掌'],
            ['en' => 'covering_mouth', 'cn' => '捂嘴'],
            ['en' => 'covering_eyes', 'cn' => '捂眼'],
            ['en' => 'covering_face', 'cn' => '捂脸'],
            ['en' => 'covering_ears', 'cn' => '捂耳朵'],
            ['en' => 'salute', 'cn' => '敬礼'],
            ['en' => 'heart_hands', 'cn' => '比心'],
            ['en' => 'finger_gun', 'cn' => '手枪手势'],
            ['en' => 'v_gesture', 'cn' => 'V 手势'],
            ['en' => 'ok_hand', 'cn' => 'OK 手势'],
        ],

        '表情动作' => [
            ['en' => 'smile', 'cn' => '微笑'],
            ['en' => 'grin', 'cn' => '咧嘴笑'],
            ['en' => 'smirk', 'cn' => '得意笑'],
            ['en' => 'evil_smile', 'cn' => '邪恶笑'],
            ['en' => 'sad_smile', 'cn' => '苦笑'],
            ['en' => 'gentle_smile', 'cn' => '温柔笑'],
            ['en' => 'open_mouth', 'cn' => '张嘴'],
            ['en' => 'closed_mouth', 'cn' => '闭嘴'],
            ['en' => 'parted_lips', 'cn' => '微张嘴'],
            ['en' => 'tongue_out', 'cn' => '吐舌'],
            ['en' => 'fang', 'cn' => '露尖牙'],
            ['en' => 'frown', 'cn' => '皱眉'],
            ['en' => 'angry', 'cn' => '生气'],
            ['en' => 'sad', 'cn' => '悲伤'],
            ['en' => 'cry', 'cn' => '哭'],
            ['en' => 'crying', 'cn' => '哭泣'],
            ['en' => 'tears', 'cn' => '泪水'],
            ['en' => 'laughing', 'cn' => '大笑'],
            ['en' => 'happy', 'cn' => '开心'],
            ['en' => 'surprised', 'cn' => '惊讶'],
            ['en' => 'shocked', 'cn' => '震惊'],
            ['en' => 'embarrassed', 'cn' => '尴尬'],
            ['en' => 'blush', 'cn' => '脸红'],
            ['en' => 'pout', 'cn' => '嘟嘴'],
            ['en' => 'scared', 'cn' => '害怕'],
            ['en' => 'worried', 'cn' => '担心'],
            ['en' => 'serious', 'cn' => '严肃'],
            ['en' => 'bored', 'cn' => '无聊'],
            ['en' => 'sleepy', 'cn' => '困倦'],
            ['en' => 'drunk', 'cn' => '醉酒'],
        ],

        '视线方向' => [
            ['en' => 'looking_at_viewer', 'cn' => '看向观众'],
            ['en' => 'eye_contact', 'cn' => '眼神对视'],
            ['en' => 'looking_away', 'cn' => '看向别处'],
            ['en' => 'looking_back', 'cn' => '回头看'],
            ['en' => 'looking_up', 'cn' => '仰望'],
            ['en' => 'looking_down', 'cn' => '俯视'],
            ['en' => 'looking_to_the_side', 'cn' => '侧视'],
            ['en' => 'looking_at_another', 'cn' => '看向他人'],
            ['en' => 'looking_at_object', 'cn' => '看物品'],
            ['en' => 'looking_at_hand', 'cn' => '看自己的手'],
            ['en' => 'looking_at_mirror', 'cn' => '照镜子'],
            ['en' => 'looking_at_phone', 'cn' => '看手机'],
            ['en' => 'looking_at_book', 'cn' => '看书'],
            ['en' => 'closed_eyes', 'cn' => '闭眼'],
            ['en' => 'half-closed_eyes', 'cn' => '半闭眼'],
            ['en' => 'narrowed_eyes', 'cn' => '眯眼'],
            ['en' => 'wide_eyes', 'cn' => '睁大眼'],
        ],

        '移动状态' => [
            ['en' => 'walking', 'cn' => '走路'],
            ['en' => 'running', 'cn' => '跑步'],
            ['en' => 'jumping', 'cn' => '跳跃'],
            ['en' => 'leaping', 'cn' => '腾跃'],
            ['en' => 'climbing', 'cn' => '攀爬'],
            ['en' => 'crawling', 'cn' => '爬行'],
            ['en' => 'dancing', 'cn' => '跳舞'],
            ['en' => 'spinning', 'cn' => '旋转'],
            ['en' => 'flying', 'cn' => '飞翔'],
            ['en' => 'hovering', 'cn' => '悬浮'],
            ['en' => 'falling', 'cn' => '坠落'],
            ['en' => 'stumbling', 'cn' => '踉跄'],
            ['en' => 'pursuing', 'cn' => '追逐'],
            ['en' => 'fleeing', 'cn' => '逃跑'],
            ['en' => 'chasing', 'cn' => '追赶'],
            ['en' => 'swinging', 'cn' => '摇摆'],
        ],

        '战斗动作' => [
            ['en' => 'fighting_stance', 'cn' => '战斗姿态'],
            ['en' => 'fighting', 'cn' => '战斗'],
            ['en' => 'combat', 'cn' => '格斗'],
            ['en' => 'battle', 'cn' => '对战'],
            ['en' => 'holding_sword', 'cn' => '持剑'],
            ['en' => 'holding_weapon', 'cn' => '持武器'],
            ['en' => 'holding_gun', 'cn' => '持枪'],
            ['en' => 'holding_bow', 'cn' => '持弓'],
            ['en' => 'aiming', 'cn' => '瞄准'],
            ['en' => 'swinging_weapon', 'cn' => '挥武器'],
            ['en' => 'slashing', 'cn' => '挥砍'],
            ['en' => 'thrusting', 'cn' => '突刺'],
            ['en' => 'blocking', 'cn' => '格挡'],
            ['en' => 'dodging', 'cn' => '闪避'],
            ['en' => 'magic', 'cn' => '施法'],
            ['en' => 'casting_magic', 'cn' => '释放魔法'],
            ['en' => 'summoning', 'cn' => '召唤'],
            ['en' => 'crouching_defense', 'cn' => '防守蹲伏'],
        ],

        '互动/亲密' => [
            ['en' => 'hug', 'cn' => '拥抱'],
            ['en' => 'hugging', 'cn' => '拥抱中'],
            ['en' => 'hugging_own_legs', 'cn' => '抱膝'],
            ['en' => 'embrace', 'cn' => '怀抱'],
            ['en' => 'kiss', 'cn' => '亲吻'],
            ['en' => 'kissing', 'cn' => '接吻'],
            ['en' => 'forehead_kiss', 'cn' => '额头吻'],
            ['en' => 'cheek_kiss', 'cn' => '脸颊吻'],
            ['en' => 'hand_holding', 'cn' => '牵手'],
            ['en' => 'holding_hands', 'cn' => '牵手'],
            ['en' => 'wrist_grab', 'cn' => '抓手腕'],
            ['en' => 'collar_grab', 'cn' => '抓领子'],
            ['en' => 'hair_pull', 'cn' => '拉头发'],
            ['en' => 'headpat', 'cn' => '摸头'],
            ['en' => 'patting_head', 'cn' => '摸头'],
            ['en' => 'shoulder_to_shoulder', 'cn' => '肩并肩'],
            ['en' => 'back-to-back', 'cn' => '背靠背'],
            ['en' => 'carrying', 'cn' => '背着'],
            ['en' => 'piggyback', 'cn' => '背人'],
            ['en' => 'bridal_carry', 'cn' => '公主抱'],
            ['en' => 'arm_in_arm', 'cn' => '手挽手'],
            ['en' => 'cheek_to_cheek', 'cn' => '脸贴脸'],
            ['en' => 'leaning_on_person', 'cn' => '靠在人身上'],
            ['en' => 'lying_on_person', 'cn' => '趴在人身上'],
            ['en' => 'sitting_on_person', 'cn' => '坐在人身上'],
        ],
    ];

    /**
     * 返回所有分类（用于前端展示）
     * @return array<string, array<int, array{en:string, cn:string}>>
     */
    public static function all(): array
    {
        return self::$categories;
    }

    /**
     * 展平成 en=>cn 映射（合并去重，后面覆盖前面）
     * @return array<string, string>
     */
    public static function flatMap(): array
    {
        $map = [];
        foreach (self::$categories as $items) {
            foreach ($items as $it) {
                $map[$it['en']] = $it['cn'];
            }
        }
        return $map;
    }

    /**
     * 搜索过滤：支持中英文模糊
     * @return array<string, array<int, array{en:string, cn:string}>>
     */
    public static function search(string $query): array
    {
        $q = mb_strtolower(trim($query));
        if ($q === '') {
            return self::all();
        }
        $out = [];
        foreach (self::$categories as $cat => $items) {
            $hits = array_values(array_filter($items, function ($it) use ($q) {
                return mb_stripos($it['en'], $q) !== false
                    || mb_stripos($it['cn'], $q) !== false;
            }));
            if ($hits) {
                $out[$cat] = $hits;
            }
        }
        return $out;
    }

    public static function totalCount(): int
    {
        $n = 0;
        foreach (self::$categories as $items) {
            $n += count($items);
        }
        return $n;
    }
}
