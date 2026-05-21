<?php

namespace App\Livewire\Admin;

use App\Models\AIConfigAudit;
use App\Models\AIModelConfig;
use App\Models\AIPromptProfile;
use App\Services\AI\AIConfigurationManagementService;
use App\Services\AI\AIConfigurationDefaults;
use App\Services\AI\AIConfigurationResolver;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin', ['title' => 'AI Configuration', 'heading' => 'AI Configuration'])]
class AdminAIConfig extends Component
{
    public string $configFeature = AIPromptProfile::FEATURE_CHAT;

    public string $promptFeature = AIPromptProfile::FEATURE_CHAT;

    public string $promptName = '';

    public string $promptBody = '';

    public string $modelFeature = AIPromptProfile::FEATURE_CHAT;

    public string $modelName = '';

    public string $modelProvider = 'litellm';

    public string $modelModelName = 'openai/gpt-4.1-mini';

    public string $modelSelection = 'litellm|openai/gpt-4.1-mini';

    public string $fallbackModelName = '';

    public string $fallbackModelSelection = '';

    /** @var array<int, string> */
    public array $modelRouteSelections = [];

    public ?float $modelTemperature = 0.2;

    public ?int $modelMaxTokens = 1024;

    public ?int $modelTimeoutSeconds = 60;

    public ?int $modelRetrievalTopK = 3;

    public ?string $flashMessage = null;

    public ?string $flashError = null;

    public function mount(): void
    {
        if (! auth()->user() || ! auth()->user()->isSuperAdmin() || ! auth()->user()->isActive()) {
            abort(403, 'Hanya super admin aktif yang dapat mengakses halaman ini.');
        }

        $this->syncFeatureFields($this->configFeature);
        $this->syncModelRouteFromFeature($this->configFeature);
    }

    public function updatedConfigFeature(string $feature): void
    {
        $this->syncFeatureFields($feature);
        $this->syncModelRouteFromFeature($feature);
    }

    public function savePromptDraft(AIConfigurationManagementService $service): void
    {
        $validated = $this->validate([
            'promptFeature' => ['required', 'in:'.implode(',', AIPromptProfile::FEATURES)],
            'promptName' => ['required', 'string', 'max:120'],
            'promptBody' => ['required', 'string', 'min:30', 'max:20000'],
        ]);

        try {
            $profile = $service->createPromptDraft(auth()->user(), [
                'feature' => $validated['promptFeature'],
                'name' => $validated['promptName'],
                'system_prompt' => $validated['promptBody'],
            ]);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        $this->reset(['promptName', 'promptBody']);
        $this->flashMessage = "Draft prompt v{$profile->version} berhasil dibuat.";
    }

    public function loadDefaultPrompt(AIConfigurationResolver $resolver): void
    {
        $definition = $resolver->defaultPromptDefinition($this->promptFeature);

        $this->promptName = $definition['name'].' copy';
        $this->promptBody = $definition['body'];
        $this->flashMessage = 'Prompt baseline dimuat ke editor. Simpan sebagai draft sebelum aktivasi.';
    }

    public function activatePrompt(int $profileId, AIConfigurationManagementService $service): void
    {
        $profile = AIPromptProfile::query()->findOrFail($profileId);
        $service->activatePrompt(auth()->user(), $profile);
        $this->flashMessage = "Prompt {$profile->name} diaktifkan.";
    }

    public function archivePrompt(int $profileId, AIConfigurationManagementService $service): void
    {
        $profile = AIPromptProfile::query()->findOrFail($profileId);
        $service->archivePrompt(auth()->user(), $profile);
        $this->flashMessage = "Prompt {$profile->name} diarsipkan.";
    }

    public function saveModelDraft(AIConfigurationManagementService $service): void
    {
        $validated = $this->validate([
            'modelFeature' => ['required', 'in:'.implode(',', AIPromptProfile::FEATURES)],
            'modelName' => ['required', 'string', 'max:120'],
            'modelRouteSelections' => ['required', 'array', 'min:1', 'max:24'],
            'modelRouteSelections.*' => ['required', 'string', 'max:320'],
            'modelSelection' => ['nullable', 'string', 'max:320'],
            'fallbackModelSelection' => ['nullable', 'string', 'max:260'],
            'modelTemperature' => ['nullable', 'numeric', 'min:0', 'max:2'],
            'modelMaxTokens' => ['nullable', 'integer', 'min:128', 'max:8192'],
            'modelTimeoutSeconds' => ['nullable', 'integer', 'min:5', 'max:180'],
            'modelRetrievalTopK' => ['nullable', 'integer', 'min:1', 'max:8'],
        ]);
        $route = $this->cleanModelRoute($validated['modelRouteSelections']);
        if ($route === []) {
            $this->addError('modelRoute', 'Pilih minimal satu model untuk route.');

            return;
        }

        [$provider, $modelName] = $this->parseModelSelection($route[0]);

        try {
            $config = $service->createModelDraft(auth()->user(), [
                'feature' => $validated['modelFeature'],
                'name' => $validated['modelName'],
                'provider' => $provider,
                'model_name' => $modelName,
                'fallback_model_name' => null,
                'model_route' => $route,
                'temperature' => $validated['modelTemperature'] ?? null,
                'max_tokens' => $validated['modelMaxTokens'] ?? null,
                'timeout_seconds' => $validated['modelTimeoutSeconds'] ?? null,
                'retrieval_top_k' => $validated['modelRetrievalTopK'] ?? null,
            ]);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        $this->modelName = '';
        $this->modelProvider = $provider;
        $this->modelModelName = $modelName;
        $this->modelSelection = $route[0] ?? $this->modelSelection;
        $this->fallbackModelName = '';
        $this->fallbackModelSelection = $route[1] ?? '';
        $this->modelRouteSelections = $route;
        $this->flashMessage = "Draft model v{$config->version} berhasil dibuat.";
    }

    public function activateModel(int $configId, AIConfigurationManagementService $service): void
    {
        $config = AIModelConfig::query()->findOrFail($configId);
        $service->activateModel(auth()->user(), $config);
        $this->flashMessage = "Model {$config->name} diaktifkan.";
    }

    public function archiveModel(int $configId, AIConfigurationManagementService $service): void
    {
        $config = AIModelConfig::query()->findOrFail($configId);
        $service->archiveModel(auth()->user(), $config);
        $this->flashMessage = "Model {$config->name} diarsipkan.";
    }

    public function moveModelRoute(int $index, int $direction): void
    {
        $target = $index + $direction;
        if (! isset($this->modelRouteSelections[$index], $this->modelRouteSelections[$target])) {
            return;
        }

        [$this->modelRouteSelections[$index], $this->modelRouteSelections[$target]] = [
            $this->modelRouteSelections[$target],
            $this->modelRouteSelections[$index],
        ];
        $this->modelRouteSelections = array_values($this->modelRouteSelections);
    }

    public function removeModelRoute(int $index): void
    {
        unset($this->modelRouteSelections[$index]);
        $this->modelRouteSelections = array_values($this->modelRouteSelections);
    }

    public function addModelRoute(AIConfigurationResolver $resolver): void
    {
        foreach ($resolver->modelCatalog() as $model) {
            $key = $model['catalog_key'] ?? null;
            if (is_string($key) && ! in_array($key, $this->modelRouteSelections, true)) {
                $this->modelRouteSelections[] = $key;

                return;
            }
        }
    }

    public function resetModelRoute(AIConfigurationResolver $resolver): void
    {
        $this->modelRouteSelections = $resolver->defaultModelRoute($this->modelFeature);
        $this->flashMessage = 'Route model dikembalikan ke baseline sistem saat ini.';
    }

    public function clearFlash(): void
    {
        $this->flashMessage = null;
        $this->flashError = null;
    }

    public function render(AIConfigurationResolver $resolver)
    {
        $feature = in_array($this->configFeature, AIPromptProfile::FEATURES, true)
            ? $this->configFeature
            : AIPromptProfile::FEATURE_CHAT;

        return view('livewire.admin.admin-ai-config', [
            'runtimeEnabled' => $resolver->runtimeEnabled(),
            'featureOptions' => $this->featureOptions(),
            'modelCatalog' => $resolver->modelCatalog(),
            'embeddingCatalog' => $resolver->embeddingCatalog(),
            'credentialStatuses' => $resolver->credentialStatuses(),
            'retrievalDefaults' => $resolver->retrievalDefaults(),
            'promptLibrary' => $resolver->promptLibrary(),
            'defaultPrompt' => $resolver->defaultPromptDefinition($feature),
            'effectiveModelRoute' => $resolver->effectiveModelRoute($feature),
            'activePrompt' => $resolver->activePrompt($feature),
            'activeModel' => $resolver->activeModelConfig($feature),
            'promptProfiles' => AIPromptProfile::query()
                ->with(['creator', 'activator'])
                ->latest('updated_at')
                ->limit(20)
                ->get(),
            'modelConfigs' => AIModelConfig::query()
                ->with(['creator', 'activator'])
                ->latest('updated_at')
                ->limit(20)
                ->get(),
            'audits' => AIConfigAudit::query()
                ->with('user')
                ->latest()
                ->limit(8)
                ->get(),
            'lastAudit' => AIConfigAudit::query()
                ->with('user')
                ->latest()
                ->first(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function featureOptions(): array
    {
        return AIConfigurationDefaults::featureLabels();
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: string|null}
     */
    private function parseModelSelection(?string $selection): array
    {
        $selection = trim((string) $selection);
        if ($selection === '') {
            return [null, null, null];
        }

        $parts = explode('|', $selection, 3);
        if (count($parts) < 2) {
            return [null, $selection, null];
        }

        return [
            trim($parts[0]) !== '' ? trim($parts[0]) : null,
            trim($parts[1]) !== '' ? trim($parts[1]) : null,
            isset($parts[2]) && trim($parts[2]) !== '' ? trim($parts[2]) : null,
        ];
    }

    private function syncFeatureFields(string $feature): void
    {
        if (! in_array($feature, AIPromptProfile::FEATURES, true)) {
            $feature = AIPromptProfile::FEATURE_CHAT;
            $this->configFeature = $feature;
        }

        $this->promptFeature = $feature;
        $this->modelFeature = $feature;
    }

    private function syncModelRouteFromFeature(string $feature): void
    {
        $resolver = app(AIConfigurationResolver::class);
        $model = $resolver->activeModelConfig($feature);
        $route = data_get($model?->metadata, 'model_route');

        $this->modelRouteSelections = is_array($route) && $route !== []
            ? array_values(array_filter($route, 'is_string'))
            : $resolver->defaultModelRoute($feature);

        $this->modelSelection = $this->modelRouteSelections[0] ?? $this->modelSelection;
        $this->fallbackModelSelection = $this->modelRouteSelections[1] ?? '';
    }

    /**
     * @param  array<int, mixed>  $route
     * @return array<int, string>
     */
    private function cleanModelRoute(array $route): array
    {
        $clean = [];
        foreach ($route as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $entry = trim($entry);
            if ($entry === '' || in_array($entry, $clean, true)) {
                continue;
            }

            $clean[] = $entry;
        }

        return $clean;
    }
}
