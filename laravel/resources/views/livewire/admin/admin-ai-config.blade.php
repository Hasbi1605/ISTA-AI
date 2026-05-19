@php
    $statusTone = fn (?string $status): string => match ($status) {
        'active' => 'success',
        'draft' => 'warning',
        'archived' => 'neutral',
        default => 'neutral',
    };
    $featureLabel = fn (?string $feature): string => $featureOptions[$feature] ?? strtoupper(str_replace('_', ' ', (string) $feature));
    $modelOptions = collect($modelCatalog)->mapWithKeys(fn ($model) => [
        ($model['provider'] ?? '') . '|' . ($model['model_name'] ?? '') => ($model['label'] ?? $model['model_name'] ?? 'Unknown') . ' - ' . ($model['provider'] ?? '-'),
    ])->all();
    $selectedFeatureLabel = $featureLabel($configFeature);
    $activePromptName = $activePrompt?->name ?: 'Belum aktif';
    $activeModelName = $activeModel?->model_name ?: 'Belum aktif';
    $activeModelLabel = $activeModel ? ($activeModel->provider . ' - ' . $activeModel->name) : 'Menunggu draft aktif';
    $lastAuditLabel = $lastAudit?->created_at?->diffForHumans() ?: 'Belum ada';
    $lastAuditActor = $lastAudit?->user?->email ?: 'Audit kosong';
    $runtimeLabel = $runtimeEnabled ? 'Database aktif' : 'Fallback env';
    $runtimeDescription = $runtimeEnabled
        ? 'Request memakai konfigurasi aktif per fitur.'
        : 'Perubahan draft aman; runtime masih membaca environment.';
    $configCards = [
        [
            'label' => 'Runtime config',
            'value' => $runtimeLabel,
            'description' => $runtimeDescription,
            'tone' => $runtimeEnabled ? 'success' : 'warning',
            'icon' => 'M12 6v6l4 2M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        ],
        [
            'label' => 'Active prompt',
            'value' => $activePromptName,
            'description' => $selectedFeatureLabel . ($activePrompt ? ' v' . $activePrompt->version : ' belum punya versi aktif'),
            'tone' => $activePrompt ? 'primary' : 'neutral',
            'icon' => 'M8 7h8M8 11h8M8 15h5M5 4h14v16H5z',
        ],
        [
            'label' => 'Active model',
            'value' => $activeModelName,
            'description' => $activeModelLabel,
            'tone' => $activeModel ? 'primary' : 'neutral',
            'icon' => 'M6 8h12M6 12h12M6 16h12M4 5h16v14H4z',
        ],
        [
            'label' => 'Last changed',
            'value' => $lastAuditLabel,
            'description' => $lastAuditActor,
            'tone' => $lastAudit ? 'primary' : 'neutral',
            'icon' => 'M12 8v4l2.5 2M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        ],
    ];
@endphp

<div class="admin-ai-config-page">
    <div class="admin-ai-config-hero">
        <div class="max-w-2xl">
            <p class="admin-ai-config-eyebrow">Super Admin</p>
            <h2 class="admin-ai-config-title">AI Configuration</h2>
            <p class="admin-ai-config-description">
                Atur prompt, model, preview, dan audit per fitur tanpa menyimpan secret di database.
            </p>
        </div>
        <div class="admin-ai-config-hero__actions">
            <span class="admin-ai-config-access-badge">
                <span></span>
                Akses terbatas
            </span>
            <x-admin.badge :tone="$runtimeEnabled ? 'success' : 'warning'" class="admin-ai-config-readonly">
                {{ $runtimeLabel }}
            </x-admin.badge>
        </div>
    </div>

    @if ($flashMessage)
        <div class="admin-ai-config-alert admin-ai-config-alert--success">
            {{ $flashMessage }}
        </div>
    @endif

    @unless ($runtimeEnabled)
        <div class="admin-ai-config-runtime-note">
            <div>
                <strong>Runtime masih fallback environment.</strong>
                <p>Draft, preview, dan aktivasi tetap bisa disiapkan. Dampak ke request user baru aktif setelah flag database configuration dinyalakan.</p>
            </div>
        </div>
    @endunless

    <div class="admin-ai-config-feature-switch" aria-label="Pilih fitur konfigurasi">
        @foreach ($featureOptions as $key => $label)
            <label class="admin-ai-config-feature-option @if ($configFeature === $key) admin-ai-config-feature-option--active @endif">
                <input type="radio" wire:model.live="configFeature" value="{{ $key }}">
                <span>{{ $label }}</span>
            </label>
        @endforeach
    </div>

    <div class="admin-ai-config-kpi-grid">
        @foreach ($configCards as $card)
            <article class="admin-ai-config-kpi-card admin-ai-config-kpi-card--{{ $card['tone'] }}">
                <div class="admin-ai-config-kpi-card__header">
                    <span class="admin-ai-config-kpi-card__icon" aria-hidden="true">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="{{ $card['icon'] }}" />
                        </svg>
                    </span>
                    <p class="admin-ai-config-kpi-card__label">{{ $card['label'] }}</p>
                </div>
                <div class="admin-ai-config-kpi-card__body">
                    <strong title="{{ $card['value'] }}">{{ $card['value'] }}</strong>
                    <p class="admin-ai-config-kpi-card__description">{{ $card['description'] }}</p>
                </div>
            </article>
        @endforeach
    </div>

    <div class="admin-ai-config-workspace">
        <section class="admin-ai-config-active-panel admin-section">
            <header class="admin-ai-config-panel__header">
                <div>
                    <h3>Konfigurasi aktif</h3>
                    <p>Snapshot runtime untuk {{ $selectedFeatureLabel }}.</p>
                </div>
                <x-admin.badge :tone="$runtimeEnabled ? 'success' : 'warning'">{{ $runtimeLabel }}</x-admin.badge>
            </header>

            <div class="admin-ai-config-active-grid">
                <div>
                    <span>Prompt</span>
                    <strong>{{ $activePromptName }}</strong>
                    <em>{{ $activePrompt ? 'v' . $activePrompt->version . ' - ' . ucfirst($activePrompt->status) : 'Belum ada prompt aktif' }}</em>
                </div>
                <div>
                    <span>Model</span>
                    <strong>{{ $activeModelName }}</strong>
                    <em>{{ $activeModel ? $activeModel->provider . ' - fallback ' . ($activeModel->fallback_model_name ?: 'tidak ada') : 'Belum ada model aktif' }}</em>
                </div>
                <div>
                    <span>Parameter</span>
                    <strong>{{ $activeModel ? number_format((float) $activeModel->temperature, 1, ',', '.') . ' temp' : '-' }}</strong>
                    <em>{{ $activeModel ? ($activeModel->max_tokens . ' tokens - ' . $activeModel->timeout_seconds . ' detik - top ' . $activeModel->retrieval_top_k) : 'Menunggu model aktif' }}</em>
                </div>
                <div>
                    <span>Audit terakhir</span>
                    <strong>{{ $lastAuditLabel }}</strong>
                    <em>{{ $lastAuditActor }}</em>
                </div>
            </div>
        </section>

        <section class="admin-ai-config-editor-panel admin-section">
            <header class="admin-ai-config-panel__header">
                <div>
                    <h3>Prompt Profile</h3>
                    <p>Draft tidak memengaruhi runtime sampai diaktifkan.</p>
                </div>
                <x-admin.badge tone="neutral">{{ $selectedFeatureLabel }}</x-admin.badge>
            </header>

            <form wire:submit.prevent="savePromptDraft" class="admin-ai-config-form">
                <label class="admin-ai-config-field">
                    <span>Nama prompt</span>
                    <input wire:model="promptName" type="text" class="admin-ai-config-control" placeholder="Contoh: {{ $selectedFeatureLabel }} formal v1">
                    @error('promptName') <em>{{ $message }}</em> @enderror
                </label>

                <label class="admin-ai-config-field">
                    <span>System prompt</span>
                    <textarea wire:model="promptBody" rows="8" class="admin-ai-config-control admin-ai-config-control--textarea" placeholder="Tulis perilaku AI yang ingin diuji untuk fitur ini."></textarea>
                    @error('promptBody') <em>{{ $message }}</em> @enderror
                </label>

                <button type="submit" class="admin-ai-config-primary-button">
                    Simpan Draft Prompt
                </button>
            </form>
        </section>

        <section class="admin-ai-config-editor-panel admin-section">
            <header class="admin-ai-config-panel__header">
                <div>
                    <h3>Model Config</h3>
                    <p>Model hanya bisa dipilih dari allowlist server.</p>
                </div>
                <x-admin.badge tone="neutral">No secrets</x-admin.badge>
            </header>

            <form wire:submit.prevent="saveModelDraft" class="admin-ai-config-form">
                <label class="admin-ai-config-field">
                    <span>Nama konfigurasi</span>
                    <input wire:model="modelName" type="text" class="admin-ai-config-control" placeholder="Contoh: {{ $selectedFeatureLabel }} GPT Mini">
                    @error('modelName') <em>{{ $message }}</em> @enderror
                </label>

                <div class="admin-ai-config-form-grid admin-ai-config-form-grid--two">
                    <label class="admin-ai-config-field">
                        <span>Model utama</span>
                        <select wire:model="modelSelection" class="admin-ai-config-control">
                            @foreach ($modelOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('modelSelection') <em>{{ $message }}</em> @enderror
                    </label>

                    <label class="admin-ai-config-field">
                        <span>Fallback model</span>
                        <select wire:model="fallbackModelSelection" class="admin-ai-config-control">
                            <option value="">Tidak ada</option>
                            @foreach ($modelOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('fallbackModelSelection') <em>{{ $message }}</em> @enderror
                    </label>
                </div>

                <div class="admin-ai-config-form-grid admin-ai-config-form-grid--four">
                    <label class="admin-ai-config-field">
                        <span>Temperature</span>
                        <input wire:model="modelTemperature" type="number" step="0.1" min="0" max="2" class="admin-ai-config-control">
                    </label>
                    <label class="admin-ai-config-field">
                        <span>Max tokens</span>
                        <input wire:model="modelMaxTokens" type="number" min="128" max="8192" class="admin-ai-config-control">
                    </label>
                    <label class="admin-ai-config-field">
                        <span>Timeout</span>
                        <input wire:model="modelTimeoutSeconds" type="number" min="5" max="180" class="admin-ai-config-control">
                    </label>
                    <label class="admin-ai-config-field">
                        <span>Top-k</span>
                        <input wire:model="modelRetrievalTopK" type="number" min="1" max="8" class="admin-ai-config-control">
                    </label>
                </div>

                <button type="submit" class="admin-ai-config-primary-button">
                    Simpan Draft Model
                </button>
            </form>
        </section>

        <section class="admin-ai-config-preview-panel admin-section">
            <header class="admin-ai-config-panel__header">
                <div>
                    <h3>Playground preview</h3>
                    <p>Dry-run aman untuk mengecek kesiapan konfigurasi.</p>
                </div>
            </header>

            <form wire:submit.prevent="runPlayground" class="admin-ai-config-form">
                <label class="admin-ai-config-field">
                    <span>Pertanyaan uji</span>
                    <textarea wire:model="playgroundInput" rows="4" class="admin-ai-config-control admin-ai-config-control--textarea"></textarea>
                    @error('playgroundInput') <em>{{ $message }}</em> @enderror
                </label>
                <button type="submit" class="admin-ai-config-secondary-button">
                    Run Preview
                </button>
            </form>

            <dl class="admin-ai-config-preview-result">
                <div>
                    <dt>Ready</dt>
                    <dd>{{ $playgroundResult ? ($playgroundResult['ready'] ? 'Ya' : 'Belum') : '-' }}</dd>
                </div>
                <div>
                    <dt>Prompt</dt>
                    <dd>{{ $playgroundResult['prompt_profile_name'] ?? '-' }}</dd>
                </div>
                <div>
                    <dt>Model</dt>
                    <dd>{{ $playgroundResult['model_name'] ?? '-' }}</dd>
                </div>
                <div>
                    <dt>Model count</dt>
                    <dd>{{ $playgroundResult['chat_model_count'] ?? 0 }}</dd>
                </div>
            </dl>
        </section>
    </div>

    <div class="admin-ai-config-history-grid">
        <section class="admin-ai-config-table-panel admin-section">
            <header class="admin-ai-config-panel__header">
                <div>
                    <h3>Prompt Profiles</h3>
                    <p>Aktifkan draft atau rollback ke versi arsip bila diperlukan.</p>
                </div>
            </header>
            <div class="admin-ai-config-table-panel__body">
                <x-admin.table :columns="[
                    ['key' => 'name', 'label' => 'Nama', 'width' => '31%'],
                    ['key' => 'feature', 'label' => 'Feature', 'width' => '20%'],
                    ['key' => 'status', 'label' => 'Status', 'width' => '14%'],
                    ['key' => 'version', 'label' => 'Versi', 'width' => '10%'],
                    ['key' => 'actions', 'label' => 'Aksi', 'align' => 'right', 'width' => '25%'],
                ]">
                    @forelse ($promptProfiles as $profile)
                        <tr>
                            <td class="admin-table__td"><span class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $profile->name }}</span></td>
                            <td class="admin-table__td">{{ $featureLabel($profile->feature) }}</td>
                            <td class="admin-table__td">
                                <span class="admin-status-chip admin-status-chip--{{ $statusTone($profile->status) }}">
                                    <span aria-hidden="true"></span>
                                    {{ ucfirst($profile->status) }}
                                </span>
                            </td>
                            <td class="admin-table__td">v{{ $profile->version }}</td>
                            <td class="admin-table__td" data-align="right">
                                <div class="admin-ai-config-action-group">
                                    @if ($profile->status !== 'active')
                                        <button wire:click="activatePrompt({{ $profile->id }})" class="admin-ai-config-table-button admin-ai-config-table-button--primary">Activate</button>
                                    @endif
                                    @if ($profile->status !== 'archived')
                                        <button wire:click="archivePrompt({{ $profile->id }})" class="admin-ai-config-table-button">Archive</button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="admin-table__td">Belum ada prompt profile.</td></tr>
                    @endforelse
                </x-admin.table>
            </div>
        </section>

        <section class="admin-ai-config-table-panel admin-section">
            <header class="admin-ai-config-panel__header">
                <div>
                    <h3>Model Configs</h3>
                    <p>Secret tetap berada di environment, bukan database.</p>
                </div>
            </header>
            <div class="admin-ai-config-table-panel__body">
                <x-admin.table :columns="[
                    ['key' => 'name', 'label' => 'Nama', 'width' => '28%'],
                    ['key' => 'model', 'label' => 'Model', 'width' => '28%'],
                    ['key' => 'status', 'label' => 'Status', 'width' => '14%'],
                    ['key' => 'version', 'label' => 'Versi', 'width' => '10%'],
                    ['key' => 'actions', 'label' => 'Aksi', 'align' => 'right', 'width' => '20%'],
                ]">
                    @forelse ($modelConfigs as $config)
                        <tr>
                            <td class="admin-table__td"><span class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $config->name }}</span></td>
                            <td class="admin-table__td"><span class="text-xs">{{ $config->model_name }}</span></td>
                            <td class="admin-table__td">
                                <span class="admin-status-chip admin-status-chip--{{ $statusTone($config->status) }}">
                                    <span aria-hidden="true"></span>
                                    {{ ucfirst($config->status) }}
                                </span>
                            </td>
                            <td class="admin-table__td">v{{ $config->version }}</td>
                            <td class="admin-table__td" data-align="right">
                                <div class="admin-ai-config-action-group">
                                    @if ($config->status !== 'active')
                                        <button wire:click="activateModel({{ $config->id }})" class="admin-ai-config-table-button admin-ai-config-table-button--primary">Activate</button>
                                    @endif
                                    @if ($config->status !== 'archived')
                                        <button wire:click="archiveModel({{ $config->id }})" class="admin-ai-config-table-button">Archive</button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="admin-table__td">Belum ada model config.</td></tr>
                    @endforelse
                </x-admin.table>
            </div>
        </section>

        <section class="admin-ai-config-table-panel admin-section admin-ai-config-audit-panel">
            <header class="admin-ai-config-panel__header">
                <div>
                    <h3>Audit Log</h3>
                    <p>Jejak perubahan konfigurasi terbaru.</p>
                </div>
            </header>
            <div class="admin-ai-config-table-panel__body">
                <x-admin.table :columns="[
                    ['key' => 'action', 'label' => 'Action', 'width' => '22%'],
                    ['key' => 'actor', 'label' => 'Actor', 'width' => '28%'],
                    ['key' => 'reason', 'label' => 'Reason', 'width' => '28%'],
                    ['key' => 'time', 'label' => 'Waktu', 'align' => 'right', 'width' => '22%'],
                ]">
                    @forelse ($audits as $audit)
                        <tr>
                            <td class="admin-table__td"><x-admin.badge tone="neutral">{{ strtoupper(str_replace('_', ' ', $audit->action)) }}</x-admin.badge></td>
                            <td class="admin-table__td">{{ $audit->user?->email ?? 'Sistem' }}</td>
                            <td class="admin-table__td">{{ $audit->reason ?: '-' }}</td>
                            <td class="admin-table__td" data-align="right">{{ $audit->created_at?->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="admin-table__td">Belum ada audit.</td></tr>
                    @endforelse
                </x-admin.table>
            </div>
        </section>
    </div>
</div>
