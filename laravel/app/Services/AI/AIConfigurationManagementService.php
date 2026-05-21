<?php

namespace App\Services\AI;

use App\Models\AIConfigAudit;
use App\Models\AIModelConfig;
use App\Models\AIPromptProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AIConfigurationManagementService
{
    public function __construct(
        private readonly AIConfigurationResolver $resolver,
        private readonly AIConfigurationAuditService $audits,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createPromptDraft(User $actor, array $data): AIPromptProfile
    {
        $feature = (string) ($data['feature'] ?? '');
        $prompt = trim((string) ($data['system_prompt'] ?? ''));

        if (! in_array($feature, AIPromptProfile::FEATURES, true)) {
            throw ValidationException::withMessages(['promptFeature' => 'Feature prompt tidak valid.']);
        }

        if ($prompt === '' || mb_strlen($prompt) < 30) {
            throw ValidationException::withMessages(['promptBody' => 'Prompt minimal 30 karakter.']);
        }

        $profile = AIPromptProfile::create([
            'feature' => $feature,
            'name' => trim((string) ($data['name'] ?? 'Draft prompt')),
            'system_prompt' => $prompt,
            'status' => AIPromptProfile::STATUS_DRAFT,
            'version' => $this->nextPromptVersion($feature),
            'parent_id' => $this->latestPromptId($feature),
            'created_by' => $actor->id,
            'metadata' => ['source' => 'admin_ui'],
        ]);

        $this->audits->record($actor, $profile, AIConfigAudit::ACTION_CREATED, after: $profile->toArray());

        return $profile;
    }

    public function activatePrompt(User $actor, AIPromptProfile $profile, ?string $reason = null): AIPromptProfile
    {
        return DB::transaction(function () use ($actor, $profile, $reason) {
            $before = $profile->fresh()?->toArray();
            AIPromptProfile::query()
                ->where('feature', $profile->feature)
                ->where('status', AIPromptProfile::STATUS_ACTIVE)
                ->whereKeyNot($profile->id)
                ->update([
                    'status' => AIPromptProfile::STATUS_ARCHIVED,
                    'archived_at' => now(),
                    'updated_at' => now(),
                ]);

            $action = $profile->status === AIPromptProfile::STATUS_ARCHIVED
                ? AIConfigAudit::ACTION_ROLLED_BACK
                : AIConfigAudit::ACTION_ACTIVATED;

            $profile->forceFill([
                'status' => AIPromptProfile::STATUS_ACTIVE,
                'activated_by' => $actor->id,
                'activated_at' => now(),
                'archived_at' => null,
            ])->save();

            $this->audits->record($actor, $profile, $action, $before, $profile->fresh()?->toArray(), $reason);

            return $profile->fresh();
        });
    }

    public function archivePrompt(User $actor, AIPromptProfile $profile, ?string $reason = null): AIPromptProfile
    {
        $before = $profile->toArray();
        $profile->forceFill([
            'status' => AIPromptProfile::STATUS_ARCHIVED,
            'archived_at' => now(),
        ])->save();

        $this->audits->record($actor, $profile, AIConfigAudit::ACTION_ARCHIVED, $before, $profile->fresh()?->toArray(), $reason);

        return $profile->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createModelDraft(User $actor, array $data): AIModelConfig
    {
        $feature = (string) ($data['feature'] ?? '');
        $provider = (string) ($data['provider'] ?? '');
        $modelName = (string) ($data['model_name'] ?? '');
        $fallbackModelName = trim((string) ($data['fallback_model_name'] ?? ''));
        $modelRoute = $this->normalizeModelRoute($data['model_route'] ?? []);
        $temperature = $this->nullableFloat($data['temperature'] ?? null, 0, 2, 'modelTemperature');
        $maxTokens = $this->nullableInt($data['max_tokens'] ?? null, 128, 8192, 'modelMaxTokens');
        $timeoutSeconds = $this->nullableInt($data['timeout_seconds'] ?? null, 5, 180, 'modelTimeoutSeconds');
        $retrievalTopK = $this->nullableInt($data['retrieval_top_k'] ?? null, 1, 8, 'modelRetrievalTopK');

        if (! in_array($feature, AIPromptProfile::FEATURES, true)) {
            throw ValidationException::withMessages(['modelFeature' => 'Feature model tidak valid.']);
        }

        if ($modelRoute !== []) {
            $first = $this->resolver->modelCatalogEntryByKey($modelRoute[0]);
            if ($first === null) {
                throw ValidationException::withMessages(['modelRoute' => 'Route model tidak valid.']);
            }

            $provider = (string) ($first['provider'] ?? $provider);
            $modelName = (string) ($first['model_name'] ?? $modelName);
            $fallback = isset($modelRoute[1]) ? $this->resolver->modelCatalogEntryByKey($modelRoute[1]) : null;
            $fallbackModelName = is_array($fallback) ? (string) ($fallback['model_name'] ?? '') : $fallbackModelName;
        }

        if (! $this->resolver->isAllowedModel($provider, $modelName)) {
            throw ValidationException::withMessages(['modelName' => 'Model utama tidak ada di allowlist server.']);
        }

        if ($modelRoute === [] && $fallbackModelName !== '' && ! $this->resolver->isAllowedModel($provider, $fallbackModelName)) {
            throw ValidationException::withMessages(['fallbackModelName' => 'Fallback model tidak ada di allowlist server.']);
        }

        $metadata = ['source' => 'admin_ui'];
        if ($modelRoute !== []) {
            $metadata['model_route'] = $modelRoute;
            $metadata['route_count'] = count($modelRoute);
        }

        $config = AIModelConfig::create([
            'feature' => $feature,
            'name' => trim((string) ($data['name'] ?? 'Draft model')),
            'provider' => $provider,
            'model_name' => $modelName,
            'fallback_model_name' => $fallbackModelName !== '' ? $fallbackModelName : null,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
            'timeout_seconds' => $timeoutSeconds,
            'retrieval_top_k' => $retrievalTopK,
            'status' => AIModelConfig::STATUS_DRAFT,
            'version' => $this->nextModelVersion($feature),
            'parent_id' => $this->latestModelId($feature),
            'created_by' => $actor->id,
            'metadata' => $metadata,
        ]);

        $this->audits->record($actor, $config, AIConfigAudit::ACTION_CREATED, after: $config->toArray());

        return $config;
    }

    public function activateModel(User $actor, AIModelConfig $config, ?string $reason = null): AIModelConfig
    {
        return DB::transaction(function () use ($actor, $config, $reason) {
            $before = $config->fresh()?->toArray();
            AIModelConfig::query()
                ->where('feature', $config->feature)
                ->where('status', AIModelConfig::STATUS_ACTIVE)
                ->whereKeyNot($config->id)
                ->update([
                    'status' => AIModelConfig::STATUS_ARCHIVED,
                    'archived_at' => now(),
                    'updated_at' => now(),
                ]);

            $action = $config->status === AIModelConfig::STATUS_ARCHIVED
                ? AIConfigAudit::ACTION_ROLLED_BACK
                : AIConfigAudit::ACTION_ACTIVATED;

            $config->forceFill([
                'status' => AIModelConfig::STATUS_ACTIVE,
                'activated_by' => $actor->id,
                'activated_at' => now(),
                'archived_at' => null,
            ])->save();

            $this->audits->record($actor, $config, $action, $before, $config->fresh()?->toArray(), $reason);

            return $config->fresh();
        });
    }

    public function archiveModel(User $actor, AIModelConfig $config, ?string $reason = null): AIModelConfig
    {
        $before = $config->toArray();
        $config->forceFill([
            'status' => AIModelConfig::STATUS_ARCHIVED,
            'archived_at' => now(),
        ])->save();

        $this->audits->record($actor, $config, AIConfigAudit::ACTION_ARCHIVED, $before, $config->fresh()?->toArray(), $reason);

        return $config->fresh();
    }

    private function nextPromptVersion(string $feature): int
    {
        return ((int) AIPromptProfile::query()->where('feature', $feature)->max('version')) + 1;
    }

    private function latestPromptId(string $feature): ?int
    {
        return AIPromptProfile::query()->where('feature', $feature)->latest('version')->value('id');
    }

    private function nextModelVersion(string $feature): int
    {
        return ((int) AIModelConfig::query()->where('feature', $feature)->max('version')) + 1;
    }

    private function latestModelId(string $feature): ?int
    {
        return AIModelConfig::query()->where('feature', $feature)->latest('version')->value('id');
    }

    /**
     * @return array<int, string>
     */
    private function normalizeModelRoute(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $route = [];
        foreach ($value as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $entry = trim($entry);
            if ($entry === '' || in_array($entry, $route, true)) {
                continue;
            }

            if ($this->resolver->modelCatalogEntryByKey($entry) === null) {
                throw ValidationException::withMessages(['modelRoute' => 'Route berisi model yang tidak tersedia di katalog server.']);
            }

            $route[] = $entry;
            if (count($route) >= 24) {
                break;
            }
        }

        return $route;
    }

    private function nullableFloat(mixed $value, float $min, float $max, string $field): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            throw ValidationException::withMessages([$field => 'Nilai harus berupa angka.']);
        }

        $number = (float) $value;
        if ($number < $min || $number > $max) {
            throw ValidationException::withMessages([$field => "Nilai harus di antara {$min} dan {$max}."]);
        }

        return $number;
    }

    private function nullableInt(mixed $value, int $min, int $max, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw ValidationException::withMessages([$field => 'Nilai harus berupa angka.']);
        }

        $number = (int) $value;
        if ($number < $min || $number > $max) {
            throw ValidationException::withMessages([$field => "Nilai harus di antara {$min} dan {$max}."]);
        }

        return $number;
    }
}
