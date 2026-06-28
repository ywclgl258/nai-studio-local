-- Seed pose presets (using PHP-friendly approach)
USE nai_studio;
DELETE FROM pose_presets;

INSERT INTO `pose_presets` (`name`, `prompt`, `category`, `is_favorite`) VALUES
('standing_natural', 'standing, relaxed posture, arms at sides, facing viewer', 'standing', 1),
('standing_arms_crossed', 'standing, arms crossed, confident, looking at viewer', 'standing', 0),
('standing_hands_pocket', 'standing, hands in pockets, casual, looking at viewer', 'standing', 0),
('sitting_crossleg', 'sitting, cross-legged, hands on lap, relaxed', 'sitting', 1),
('sitting_chair', 'sitting on chair, hands on knees, upright posture', 'sitting', 0),
('sitting_ground', 'sitting on ground, knees to chest, hugging legs', 'sitting', 0),
('lying_back', 'lying on back, eyes closed, peaceful, arms at sides', 'lying', 0),
('lying_side', 'lying on side, head on pillow, sleeping pose', 'lying', 0),
('walking_step', 'walking, mid-step, dynamic pose, motion', 'action', 0),
('running_sprint', 'running, sprint, action pose, motion blur, dynamic', 'action', 0),
('jumping_air', 'jumping, mid-air, arms spread, energetic', 'action', 0),
('combat_sword', 'combat pose, drawing sword, dynamic action', 'action', 0),
('look_up_sky', 'looking up at sky, head tilted back, hands at sides', 'expression', 0),
('look_back', 'looking back over shoulder, dynamic angle', 'expression', 1),
('reach_out', 'reaching out hand, beckoning, leaning forward', 'expression', 0),
('cover_face', 'covering face with hands, embarrassed, shy', 'expression', 0),
('smile_tilt', 'smiling, head tilt, gentle expression', 'expression', 0),
('surprised', 'surprised, mouth open, hands near face', 'expression', 0),
('thinking', 'thinking pose, hand on chin, looking away', 'expression', 0),
('chin_rest', 'resting chin on hand, looking at viewer, gentle smile', 'expression', 0)
ON DUPLICATE KEY UPDATE `prompt` = VALUES(`prompt`);

DELETE FROM character_presets;
INSERT INTO `character_presets` (`name`, `gender`, `prompt`, `position_x`, `position_y`, `is_favorite`) VALUES
('girl_default', 'female', '1girl, solo, detailed face, beautiful detailed eyes, looking at viewer, masterpiece, best quality', 0.5, 0.5, 1),
('boy_default', 'male', '1boy, solo, detailed face, handsome, looking at viewer, masterpiece, best quality', 0.5, 0.5, 0),
('jk_uniform', 'female', '1girl, school uniform, pleated skirt, white shirt, ribbon tie, knee-high socks, loafers', 0.5, 0.5, 1),
('maid', 'female', '1girl, maid outfit, maid headdress, frilled apron, black dress, white gloves', 0.5, 0.5, 1),
('knight', 'male', '1boy, armor, knight, sword, heroic pose, cape, fantasy', 0.5, 0.5, 0),
('mage', 'female', '1girl, witch, mage, pointy hat, robes, holding staff, magical aura', 0.5, 0.5, 0),
('princess', 'female', '1girl, princess, elegant dress, tiara, jewelry, royal', 0.5, 0.5, 0),
('catgirl', 'female', '1girl, cat ears, cat tail, nekomimi, cute, playful', 0.5, 0.5, 0),
('foxgirl', 'female', '1girl, fox ears, fox tail, kitsune, multiple tails, mystical', 0.5, 0.5, 0),
('cyberpunk', 'female', '1girl, cyberpunk, neon lights, futuristic, techwear, glowing accents', 0.5, 0.5, 0),
('swimsuit', 'female', '1girl, swimsuit, beach, wet hair, summer', 0.5, 0.5, 0),
('kimono', 'female', '1girl, kimono, traditional japanese, floral pattern, obi, geta', 0.5, 0.5, 0)
ON DUPLICATE KEY UPDATE `prompt` = VALUES(`prompt`);
