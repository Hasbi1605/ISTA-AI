<?php

namespace App\Services\AI;

use App\Models\AIModelConfig;
use App\Models\AIPromptProfile;
use Illuminate\Support\Arr;

class AIConfigurationResolver
{
    private const MINIMUM_GUARDRAIL = <<<'PROMPT'
Guardrail minimum ISTA AI:
- Jangan menampilkan, meminta, atau menebak API key, token, secret, cookie, atau kredensial.
- Jangan melemahkan isolasi dokumen pribadi user dan knowledge internal.
- Jangan mengarang kebijakan, prosedur, jadwal, atau data internal yang tidak ada pada konteks.
- Jika informasi belum cukup, nyatakan keterbatasan dengan jelas.
PROMPT;

    public function runtimeEnabled(): bool
    {
        return (bool) config('services.ai_config.db_enabled', false);
    }

    public function featureFromChatRequest(?array $documentFilenames, bool $forceWebSearch): string
    {
        if (! empty($documentFilenames)) {
            return AIPromptProfile::FEATURE_DOCUMENT_RAG;
        }

        if ($forceWebSearch) {
            return AIPromptProfile::FEATURE_WEB_SEARCH;
        }

        return AIPromptProfile::FEATURE_CHAT;
    }

    public function activePrompt(string $feature): ?AIPromptProfile
    {
        if (! in_array($feature, AIPromptProfile::FEATURES, true)) {
            return null;
        }

        return AIPromptProfile::query()
            ->where('feature', $feature)
            ->active()
            ->latest('version')
            ->first();
    }

    public function activeModelConfig(string $feature): ?AIModelConfig
    {
        if (! in_array($feature, AIPromptProfile::FEATURES, true)) {
            return null;
        }

        return AIModelConfig::query()
            ->where('feature', $feature)
            ->active()
            ->latest('version')
            ->first();
    }

    /**
     * Build a safe runtime payload for Python AI. Secrets are never read from
     * database rows; model entries are hydrated from the server-side catalog.
     *
     * @return array<string, mixed>
     */
    public function runtimePayload(string $feature): array
    {
        if (! $this->runtimeEnabled()) {
            return [];
        }

        $payload = [];
        $prompt = $this->activePrompt($feature);
        if ($prompt !== null && trim($prompt->system_prompt) !== '') {
            $payload['prompt_profile_id'] = (int) $prompt->id;
            $payload['system_prompt'] = $this->composeSystemPrompt($prompt->system_prompt);
        }

        $modelConfig = $this->activeModelConfig($feature);
        if ($modelConfig !== null) {
            $models = $this->runtimeModels($modelConfig);
            if ($models !== []) {
                $payload['model_config_id'] = (int) $modelConfig->id;
                $payload['chat_models'] = $models;
                if ($modelConfig->retrieval_top_k !== null) {
                    $payload['retrieval_top_k'] = max(1, min(8, (int) $modelConfig->retrieval_top_k));
                }
            }
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function usageMetadataForFeature(string $feature): array
    {
        if (! $this->runtimeEnabled()) {
            return [];
        }

        $metadata = [];
        $prompt = $this->activePrompt($feature);
        if ($prompt !== null) {
            $metadata['prompt_profile_id'] = (int) $prompt->id;
        }

        $model = $this->activeModelConfig($feature);
        if ($model !== null && $this->runtimeModels($model) !== []) {
            $metadata['model_config_id'] = (int) $model->id;
        }

        if ($metadata !== []) {
            $metadata['ai_config_runtime'] = 'db';
        }

        return $metadata;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function modelCatalog(): array
    {
        $catalog = config('services.ai_config.model_catalog', []);

        return is_array($catalog) ? array_values($catalog) : [];
    }

    public function modelCatalogEntry(string $provider, string $modelName): ?array
    {
        foreach ($this->modelCatalog() as $entry) {
            if (($entry['provider'] ?? null) === $provider && ($entry['model_name'] ?? null) === $modelName) {
                return $entry;
            }
        }

        return null;
    }

    public function isAllowedModel(string $provider, string $modelName): bool
    {
        return $this->modelCatalogEntry($provider, $modelName) !== null;
    }

    private function composeSystemPrompt(string $systemPrompt): string
    {
        return trim(self::MINIMUM_GUARDRAIL."\n\n".$systemPrompt);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function runtimeModels(AIModelConfig $config): array
    {
        $models = [];
        $primary = $this->buildRuntimeModel($config, $config->model_name);
        if ($primary !== null) {
            $models[] = $primary;
        }

        $fallbackModel = trim((string) $config->fallback_model_name);
        if ($fallbackModel !== '' && $fallbackModel !== $config->model_name) {
            $fallback = $this->buildRuntimeModel($config, $fallbackModel);
            if ($fallback !== null) {
                $models[] = $fallback;
            }
        }

        return $models;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildRuntimeModel(AIModelConfig $config, string $modelName): ?array
    {
        $catalog = $this->modelCatalogEntry($config->provider, $modelName);
        if ($catalog === null) {
            return null;
        }

        $runtime = Arr::only($catalog, [
            'label',
            'provider',
            'model_name',
            'api_key_env',
            'base_url',
            'region',
        ]);

        $runtime['label'] = $config->name.' · '.($catalog['label'] ?? $modelName);

        if ($config->temperature !== null) {
            $runtime['temperature'] = max(0, min(2, (float) $config->temperature));
        }

        if ($config->max_tokens !== null) {
            $runtime['max_tokens'] = max(128, min(8192, (int) $config->max_tokens));
        }

        if ($config->timeout_seconds !== null) {
            $runtime['timeout'] = max(5, min(180, (int) $config->timeout_seconds));
        }

        return $runtime;
    }
}
