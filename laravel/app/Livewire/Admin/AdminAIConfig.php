<?php

namespace App\Livewire\Admin;

use App\Models\AIConfigAudit;
use App\Models\AIModelConfig;
use App\Models\AIPromptProfile;
use App\Services\AI\AIConfigurationManagementService;
use App\Services\AI\AIConfigurationPlaygroundService;
use App\Services\AI\AIConfigurationResolver;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin', ['title' => 'AI Configuration', 'heading' => 'AI Configuration'])]
class AdminAIConfig extends Component
{
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

    public ?float $modelTemperature = 0.2;

    public ?int $modelMaxTokens = 1024;

    public ?int $modelTimeoutSeconds = 60;

    public ?int $modelRetrievalTopK = 3;

    public string $playgroundFeature = AIPromptProfile::FEATURE_CHAT;

    public string $playgroundInput = 'Contoh pertanyaan untuk validasi konfigurasi.';

    /** @var array<string, mixed>|null */
    public ?array $playgroundResult = null;

    public ?string $flashMessage = null;

    public ?string $flashError = null;

    public function mount(): void
    {
        if (! auth()->user() || ! auth()->user()->isSuperAdmin() || ! auth()->user()->isActive()) {
            abort(403, 'Hanya super admin aktif yang dapat mengakses halaman ini.');
        }
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
            'modelSelection' => ['required', 'string', 'max:260'],
            'fallbackModelSelection' => ['nullable', 'string', 'max:260'],
            'modelTemperature' => ['nullable', 'numeric', 'min:0', 'max:2'],
            'modelMaxTokens' => ['nullable', 'integer', 'min:128', 'max:8192'],
            'modelTimeoutSeconds' => ['nullable', 'integer', 'min:5', 'max:180'],
            'modelRetrievalTopK' => ['nullable', 'integer', 'min:1', 'max:8'],
        ]);
        [$provider, $modelName] = $this->parseModelSelection($validated['modelSelection']);
        [$fallbackProvider, $fallbackModelName] = $this->parseModelSelection($validated['fallbackModelSelection'] ?? '');

        if ($fallbackModelName !== null && $fallbackProvider !== $provider) {
            $this->addError('fallbackModelSelection', 'Fallback model harus memakai provider yang sama dengan model utama.');

            return;
        }

        try {
            $config = $service->createModelDraft(auth()->user(), [
                'feature' => $validated['modelFeature'],
                'name' => $validated['modelName'],
                'provider' => $provider,
                'model_name' => $modelName,
                'fallback_model_name' => $fallbackModelName,
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
        $this->fallbackModelName = $fallbackModelName ?? '';
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

    public function runPlayground(AIConfigurationPlaygroundService $service): void
    {
        $validated = $this->validate([
            'playgroundFeature' => ['required', 'in:'.implode(',', AIPromptProfile::FEATURES)],
            'playgroundInput' => ['required', 'string', 'max:2000'],
        ]);

        $this->playgroundResult = $service->preview(
            auth()->user(),
            $validated['playgroundFeature'],
            $validated['playgroundInput'],
        );
        $this->flashMessage = 'Playground preview selesai.';
    }

    public function clearFlash(): void
    {
        $this->flashMessage = null;
        $this->flashError = null;
    }

    public function render(AIConfigurationResolver $resolver)
    {
        return view('livewire.admin.admin-ai-config', [
            'runtimeEnabled' => $resolver->runtimeEnabled(),
            'featureOptions' => $this->featureOptions(),
            'modelCatalog' => $resolver->modelCatalog(),
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
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function featureOptions(): array
    {
        return [
            AIPromptProfile::FEATURE_CHAT => 'Chat',
            AIPromptProfile::FEATURE_DOCUMENT_RAG => 'Document RAG',
            AIPromptProfile::FEATURE_WEB_SEARCH => 'Web Search',
            AIPromptProfile::FEATURE_MEMO_GENERATION => 'Memo Generation',
            AIPromptProfile::FEATURE_KNOWLEDGE_INTERNAL => 'Knowledge Internal',
        ];
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function parseModelSelection(?string $selection): array
    {
        $selection = trim((string) $selection);
        if ($selection === '') {
            return [null, null];
        }

        $parts = explode('|', $selection, 2);
        if (count($parts) !== 2) {
            return [null, $selection];
        }

        return [
            trim($parts[0]) !== '' ? trim($parts[0]) : null,
            trim($parts[1]) !== '' ? trim($parts[1]) : null,
        ];
    }
}
