<?php

namespace App\Services\AI;

use App\Models\AIConfigAudit;
use App\Models\User;

class AIConfigurationPlaygroundService
{
    public function __construct(
        private readonly AIConfigurationResolver $resolver,
        private readonly AIConfigurationAuditService $audits,
    ) {}

    /**
     * A safe playground preview. It validates which active config would be used
     * without sending arbitrary admin text to the LLM or storing raw prompts.
     *
     * @return array<string, mixed>
     */
    public function preview(User $actor, string $feature, string $sampleInput): array
    {
        $runtime = $this->resolver->runtimePayload($feature);
        $prompt = $this->resolver->activePrompt($feature);
        $model = $this->resolver->activeModelConfig($feature);

        $result = [
            'feature' => $feature,
            'runtime_enabled' => $this->resolver->runtimeEnabled(),
            'prompt_profile_id' => $prompt?->id,
            'prompt_profile_name' => $prompt?->name,
            'model_config_id' => $model?->id,
            'model_config_name' => $model?->name,
            'model_name' => $model?->model_name,
            'input_chars' => mb_strlen($sampleInput),
            'system_prompt_chars' => isset($runtime['system_prompt']) ? mb_strlen((string) $runtime['system_prompt']) : 0,
            'chat_model_count' => isset($runtime['chat_models']) && is_array($runtime['chat_models']) ? count($runtime['chat_models']) : 0,
            'ready' => $runtime !== [],
        ];

        $auditable = $prompt ?? $model;
        if ($auditable !== null) {
            $this->audits->record(
                $actor,
                $auditable,
                AIConfigAudit::ACTION_TESTED,
                after: null,
                metadata: [
                    'feature' => $feature,
                    'input_chars' => $result['input_chars'],
                    'ready' => $result['ready'],
                ],
            );
        }

        return $result;
    }
}
