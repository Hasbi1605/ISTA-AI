@php
    $statusTone = fn (?string $status): string => match ($status) {
        'active' => 'success',
        'draft' => 'warning',
        'archived' => 'neutral',
        default => 'neutral',
    };
    $featureLabel = fn (?string $feature): string => $featureOptions[$feature] ?? strtoupper(str_replace('_', ' ', (string) $feature));
    $modelOptions = collect($modelCatalog)->mapWithKeys(fn ($model) => [
        ($model['catalog_key'] ?? (($model['provider'] ?? '') . '|' . ($model['model_name'] ?? '') . '|' . ($model['api_key_env'] ?? ''))) =>
            ($model['label'] ?? $model['model_name'] ?? 'Unknown') . ' - ' . ($model['provider'] ?? '-') . ' / ' . ($model['api_key_env'] ?? 'no-env'),
    ])->all();
    $selectedFeatureLabel = $featureLabel($configFeature);
    $activePromptName = $activePrompt?->name ?: 'Baseline sistem';
    $activePromptDescription = $activePrompt
        ? 'DB v' . $activePrompt->version . ' - ' . ucfirst($activePrompt->status)
        : $defaultPrompt['path'];
    $activeModelName = $activeModel?->name ?: 'Baseline YAML/env';
    $activeRouteCount = is_countable($effectiveModelRoute) ? count($effectiveModelRoute) : 0;
    $lastAuditLabel = $lastAudit?->created_at?->diffForHumans() ?: 'Belum ada';
    $lastAuditActor = $lastAudit?->user?->email ?: 'Audit kosong';
    $runtimeLabel = $runtimeEnabled ? 'Database aktif' : 'Fallback env';
    $runtimeDescription = $runtimeEnabled
        ? 'Request memakai konfigurasi DB aktif bila valid; jika kosong Python tetap fallback ke YAML.'
        : 'Perubahan draft aman; runtime masih membaca YAML dan environment.';
    $missingCredentialCount = collect($credentialStatuses)->where('configured', false)->count();
    $configCards = [
        [
            'label' => 'Runtime config',
            'value' => $runtimeLabel,
            'description' => $runtimeDescription,
            'tone' => $runtimeEnabled ? 'success' : 'warning',
            'icon' => 'M12 6v6l4 2M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        ],
        [
            'label' => 'Prompt efektif',
            'value' => $activePromptName,
            'description' => $activePromptDescription,
            'tone' => $activePrompt ? 'primary' : 'neutral',
            'icon' => 'M8 7h8M8 11h8M8 15h5M5 4h14v16H5z',
        ],
        [
            'label' => 'Model route',
            'value' => $activeModelName,
            'description' => $activeRouteCount . ' model fallback tersedia untuk ' . $selectedFeatureLabel,
            'tone' => $activeModel ? 'primary' : 'neutral',
            'icon' => 'M6 8h12M6 12h12M6 16h12M4 5h16v14H4z',
        ],
        [
            'label' => 'Credential alias',
            'value' => $missingCredentialCount > 0 ? $missingCredentialCount . ' belum terisi' : 'Siap',
            'description' => 'Alias env saja, raw API key tidak ditampilkan.',
            'tone' => $missingCredentialCount > 0 ? 'warning' : 'success',
            'icon' => 'M15 7a3 3 0 11-6 0 3 3 0 016 0zM7 14h10v6H7z',
        ],
    ];
@endphp

<div class="admin-ai-config-page">
    <div class="admin-ai-config-hero">
        <div class="max-w-3xl">
            <p class="admin-ai-config-eyebrow">Super Admin</p>
            <h2 class="admin-ai-config-title">AI Configuration</h2>
            <p class="admin-ai-config-description">
                Kelola baseline prompt, urutan model fallback, retrieval, credential alias, dan audit tanpa menyimpan raw secret di database.
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
                <p>Baseline di bawah merepresentasikan konfigurasi sistem saat ini. Draft dan aktivasi dapat disiapkan tanpa memengaruhi user sampai `AI_CONFIG_DB_ENABLED` dinyalakan.</p>
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
                    <h3>Snapshot aktif</h3>
                    <p>Konfigurasi efektif untuk {{ $selectedFeatureLabel }}.</p>
                </div>
                <x-admin.badge :tone="$runtimeEnabled ? 'success' : 'warning'">{{ $runtimeLabel }}</x-admin.badge>
            </header>

            <div class="admin-ai-config-active-grid">
                <div>
                    <span>Prompt</span>
                    <strong>{{ $activePromptName }}</strong>
                    <em>{{ $activePromptDescription }}</em>
                </div>
                <div>
                    <span>Route model</span>
                    <strong>{{ $activeRouteCount }} model</strong>
                    <em>{{ $activeModel ? 'DB v' . $activeModel->version : 'Baseline sesuai YAML saat ini' }}</em>
                </div>
                <div>
                    <span>Retrieval</span>
                    <strong>top {{ data_get($retrievalDefaults, 'semantic_rerank.top_k', 5) }}</strong>
                    <em>{{ data_get($retrievalDefaults, 'semantic_rerank.doc_candidates', 20) }} candidates - HyDE {{ data_get($retrievalDefaults, 'hyde.mode', 'smart') }}</em>
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
                    <h3>Prompt editor</h3>
                    <p>Baseline prompt saat ini dimuat utuh dan dapat disimpan sebagai draft.</p>
                </div>
                <x-admin.badge tone="neutral">{{ $defaultPrompt['path'] }}</x-admin.badge>
            </header>

            <form wire:submit.prevent="savePromptDraft" class="admin-ai-config-form">
                <label class="admin-ai-config-field">
                    <span>Nama prompt</span>
                    <input wire:model="promptName" type="text" class="admin-ai-config-control" placeholder="Contoh: {{ $selectedFeatureLabel }} revision v1">
                    @error('promptName') <em>{{ $message }}</em> @enderror
                </label>

                <label class="admin-ai-config-field">
                    <span>Prompt aktif/baseline</span>
                    <textarea wire:model="promptBody" rows="12" class="admin-ai-config-control admin-ai-config-control--textarea" placeholder="{{ Str::limit($defaultPrompt['body'], 120) }}"></textarea>
                    @error('promptBody') <em>{{ $message }}</em> @enderror
                </label>

                <div class="admin-ai-config-action-group">
                    <button type="button" wire:click="loadDefaultPrompt" class="admin-ai-config-secondary-button">
                        Muat Baseline
                    </button>
                    <button type="submit" class="admin-ai-config-primary-button">
                        Simpan Draft Prompt
                    </button>
                </div>
            </form>
        </section>

        <section class="admin-ai-config-editor-panel admin-section">
            <header class="admin-ai-config-panel__header">
                <div>
                    <h3>Model route</h3>
                    <p>Urutan menentukan primary dan fallback. Credential berupa alias env, bukan secret.</p>
                </div>
                <x-admin.badge tone="neutral">{{ count($modelRouteSelections) }} selected</x-admin.badge>
            </header>

            <form wire:submit.prevent="saveModelDraft" class="admin-ai-config-form">
                <label class="admin-ai-config-field">
                    <span>Nama konfigurasi</span>
                    <input wire:model="modelName" type="text" class="admin-ai-config-control" placeholder="Contoh: {{ $selectedFeatureLabel }} route v1">
                    @error('modelName') <em>{{ $message }}</em> @enderror
                </label>

                <div class="admin-ai-config-form-grid">
                    @foreach ($modelRouteSelections as $index => $selection)
                        <div class="admin-ai-config-field">
                            <span>{{ $index === 0 ? 'Primary' : 'Fallback ' . $index }}</span>
                            <div class="admin-ai-config-action-group">
                                <select wire:model="modelRouteSelections.{{ $index }}" class="admin-ai-config-control">
                                    @foreach ($modelOptions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                <button type="button" wire:click="moveModelRoute({{ $index }}, -1)" class="admin-ai-config-table-button" @disabled($index === 0)>Naik</button>
                                <button type="button" wire:click="moveModelRoute({{ $index }}, 1)" class="admin-ai-config-table-button" @disabled($index === count($modelRouteSelections) - 1)>Turun</button>
                                <button type="button" wire:click="removeModelRoute({{ $index }})" class="admin-ai-config-table-button" @disabled(count($modelRouteSelections) <= 1)>Hapus</button>
                            </div>
                        </div>
                    @endforeach
                    @error('modelRoute') <em>{{ $message }}</em> @enderror
                    @error('modelRouteSelections') <em>{{ $message }}</em> @enderror
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
                        <span>RAG top-k</span>
                        <input wire:model="modelRetrievalTopK" type="number" min="1" max="8" class="admin-ai-config-control">
                    </label>
                </div>

                <div class="admin-ai-config-action-group">
                    <button type="button" wire:click="addModelRoute" class="admin-ai-config-secondary-button">
                        Tambah Model
                    </button>
                    <button type="button" wire:click="resetModelRoute" class="admin-ai-config-secondary-button">
                        Reset Baseline
                    </button>
                    <button type="submit" class="admin-ai-config-primary-button">
                        Simpan Draft Route
                    </button>
                </div>
            </form>
        </section>
    </div>

    <div class="admin-ai-config-history-grid">
        <section class="admin-ai-config-table-panel admin-section">
            <header class="admin-ai-config-panel__header">
                <div>
                    <h3>Route efektif</h3>
                    <p>Urutan model yang akan dipakai bila runtime DB aktif dan route ini diaktifkan.</p>
                </div>
            </header>
            <div class="admin-ai-config-table-panel__body">
                <x-admin.table :columns="[
                    ['key' => 'order', 'label' => '#', 'width' => '8%'],
                    ['key' => 'label', 'label' => 'Model', 'width' => '34%'],
                    ['key' => 'provider', 'label' => 'Provider', 'width' => '18%'],
                    ['key' => 'credential', 'label' => 'Credential alias', 'width' => '22%'],
                    ['key' => 'timeout', 'label' => 'Timeout', 'align' => 'right', 'width' => '18%'],
                ]">
                    @foreach ($effectiveModelRoute as $index => $model)
                        <tr>
                            <td class="admin-table__td">{{ $index + 1 }}</td>
                            <td class="admin-table__td"><span class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $model['label'] ?? $model['model_name'] ?? '-' }}</span></td>
                            <td class="admin-table__td">{{ $model['provider'] ?? '-' }}</td>
                            <td class="admin-table__td"><span class="text-xs">{{ $model['api_key_env'] ?? '-' }}</span></td>
                            <td class="admin-table__td" data-align="right">{{ $model['timeout'] ?? 'default' }}</td>
                        </tr>
                    @endforeach
                </x-admin.table>
            </div>
        </section>

        <section class="admin-ai-config-table-panel admin-section">
            <header class="admin-ai-config-panel__header">
                <div>
                    <h3>Credential aliases</h3>
                    <p>Status hanya mengecek keberadaan env, tidak pernah menampilkan API key.</p>
                </div>
            </header>
            <div class="admin-ai-config-table-panel__body">
                <x-admin.table :columns="[
                    ['key' => 'alias', 'label' => 'Alias', 'width' => '45%'],
                    ['key' => 'source', 'label' => 'Source', 'width' => '25%'],
                    ['key' => 'status', 'label' => 'Status', 'align' => 'right', 'width' => '30%'],
                ]">
                    @foreach ($credentialStatuses as $credential)
                        <tr>
                            <td class="admin-table__td"><span class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $credential['alias'] }}</span></td>
                            <td class="admin-table__td">{{ $credential['source'] }}</td>
                            <td class="admin-table__td" data-align="right">
                                <span class="admin-status-chip admin-status-chip--{{ $credential['configured'] ? 'success' : 'warning' }}">
                                    <span aria-hidden="true"></span>
                                    {{ $credential['configured'] ? 'Terisi' : 'Belum terisi' }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </x-admin.table>
            </div>
        </section>

        <section class="admin-ai-config-table-panel admin-section">
            <header class="admin-ai-config-panel__header">
                <div>
                    <h3>Prompt library</h3>
                    <p>Semua prompt penting yang saat ini ada di sistem.</p>
                </div>
            </header>
            <div class="admin-ai-config-table-panel__body">
                <x-admin.table :columns="[
                    ['key' => 'name', 'label' => 'Nama', 'width' => '30%'],
                    ['key' => 'path', 'label' => 'Path', 'width' => '32%'],
                    ['key' => 'size', 'label' => 'Chars', 'align' => 'right', 'width' => '12%'],
                    ['key' => 'preview', 'label' => 'Preview', 'width' => '26%'],
                ]">
                    @foreach ($promptLibrary as $prompt)
                        <tr>
                            <td class="admin-table__td"><span class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $prompt['name'] }}</span></td>
                            <td class="admin-table__td"><span class="text-xs">{{ $prompt['path'] }}</span></td>
                            <td class="admin-table__td" data-align="right">{{ mb_strlen($prompt['body']) }}</td>
                            <td class="admin-table__td"><span class="text-xs">{{ Str::limit(preg_replace('/\s+/', ' ', trim($prompt['body'])), 90) }}</span></td>
                        </tr>
                    @endforeach
                </x-admin.table>
            </div>
        </section>

        <section class="admin-ai-config-table-panel admin-section">
            <header class="admin-ai-config-panel__header">
                <div>
                    <h3>Embedding route</h3>
                    <p>Embedding tetap mengikuti baseline saat ini agar dimensi vektor konsisten.</p>
                </div>
            </header>
            <div class="admin-ai-config-table-panel__body">
                <x-admin.table :columns="[
                    ['key' => 'name', 'label' => 'Model', 'width' => '38%'],
                    ['key' => 'provider', 'label' => 'Provider', 'width' => '20%'],
                    ['key' => 'dim', 'label' => 'Dimensi', 'align' => 'right', 'width' => '18%'],
                    ['key' => 'credential', 'label' => 'Credential', 'width' => '24%'],
                ]">
                    @foreach ($embeddingCatalog as $model)
                        <tr>
                            <td class="admin-table__td"><span class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $model['name'] }}</span></td>
                            <td class="admin-table__td">{{ $model['provider'] }}</td>
                            <td class="admin-table__td" data-align="right">{{ $model['dimensions'] }}</td>
                            <td class="admin-table__td"><span class="text-xs">{{ $model['api_key_env'] }}</span></td>
                        </tr>
                    @endforeach
                </x-admin.table>
            </div>
        </section>

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
                        <tr><td colspan="5" class="admin-table__td">Belum ada prompt profile. Baseline tetap ditampilkan di prompt library.</td></tr>
                    @endforelse
                </x-admin.table>
            </div>
        </section>

        <section class="admin-ai-config-table-panel admin-section">
            <header class="admin-ai-config-panel__header">
                <div>
                    <h3>Model Configs</h3>
                    <p>Draft route menyimpan urutan fallback di metadata audit-safe.</p>
                </div>
            </header>
            <div class="admin-ai-config-table-panel__body">
                <x-admin.table :columns="[
                    ['key' => 'name', 'label' => 'Nama', 'width' => '28%'],
                    ['key' => 'model', 'label' => 'Primary', 'width' => '24%'],
                    ['key' => 'route', 'label' => 'Route', 'width' => '14%'],
                    ['key' => 'status', 'label' => 'Status', 'width' => '14%'],
                    ['key' => 'actions', 'label' => 'Aksi', 'align' => 'right', 'width' => '20%'],
                ]">
                    @forelse ($modelConfigs as $config)
                        <tr>
                            <td class="admin-table__td"><span class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $config->name }}</span></td>
                            <td class="admin-table__td"><span class="text-xs">{{ $config->model_name }}</span></td>
                            <td class="admin-table__td">{{ count(data_get($config->metadata, 'model_route', [])) ?: ($config->fallback_model_name ? 2 : 1) }} model</td>
                            <td class="admin-table__td">
                                <span class="admin-status-chip admin-status-chip--{{ $statusTone($config->status) }}">
                                    <span aria-hidden="true"></span>
                                    {{ ucfirst($config->status) }}
                                </span>
                            </td>
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
                        <tr><td colspan="5" class="admin-table__td">Belum ada model config. Baseline route tetap memakai YAML/env.</td></tr>
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
