/**
 * NAI Studio - 共享 payload 构建
 * 单张 onGenerate 和队列 queue.js 都用这个，避免字段不一致
 * overrides: 让队列 item 覆盖特定字段（例：工程队列每张用不同姿势）
 */
import { getState } from './state.js';

export function buildGeneratePayload(s = getState(), batchId = null, overrides = {}) {
    return {
        prompt:             s.prompt,
        negative_prompt:    s.negativePrompt,
        character_prompts:  (s.characterPrompts || ['']).filter(v => (v || '').trim()),
        pose_prompt:        s.posePrompt,
        model:              s.model,
        sampler:            s.sampler,
        steps:              s.steps,
        scale:              s.scale,
        seed:               s.seed || Math.floor(Math.random() * 4294967295),
        size:               s.size,
        cfg_rescale:        s.cfgRescale,
        noise_schedule:     s.noiseSchedule,
        uc_preset:          s.ucPreset,
        quality_toggle:     s.qualityToggle,
        n_samples:          s.nSamples,
        vibe_refs:          s.vibeRefs,
        precise_refs:       s.preciseRefs,
        base_image:         s.baseImage?.base64,
        strength:           s.strength,
        noise:              s.noise,
        mask:               s.mask,
        batch_id:           batchId,
        ...overrides,
    };
}
