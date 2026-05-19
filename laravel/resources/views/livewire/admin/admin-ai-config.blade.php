@php
    $statusTone = fn (?string $status): string => match ($status) {
        'active' => 'success',
        'draft' => 'warning',
        'archived' => 'neutral',
        default => 'neutral',
    };
    $featureLabel = fn (?string $feature): string => $featureOptions[$feature] ?? strtoupper(str_replace('_', ' ', (string) $feature));
    $modelOptions = collect($modelCatalog)->mapWithKeys(fn ($model) => [
        ($model['provider'] ?? '') . '|' . ($model['model_name'] ?? '') => ($model['label'] ?? $model['model_name'] ?? 'Unknown') . ' · ' . ($model['provider'] ?? '-'),
    ])->all();
@endphp

<div class="admin-ai-config-page">
    <div class="admin-ai-config-hero">
        <div class="max-w-2xl">
            <p class="admin-ai-config-eyebrow">Super Admin</p>
            <h2 class="admin-ai-config-title">AI Configuration</h2>
            <p class="admin-ai-config-description">
                Kelola prompt profile, model runtime, playground, audit, dan rollback konfigurasi AI.
            </p>
        </div>
        <div class="admin-ai-config-hero__actions">
            <span class="admin-ai-config-access-badge">
                <span></span>
                Akses terbatas
            </span>
            <x-admin.badge :tone="$runtimeEnabled ? 'success' : 'warning'" class="admin-ai-config-readonly">
                Runtime {{ $runtimeEnabled ? 'DB aktif' : 'fallback env' }}
            </x-admin.badge>
        </div>
    </div>

    @if ($flashMessage)
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300">
            {{ $flashMessage }}
        </div>
    @endif

    <div class="admin-ai-config-kpi-grid">
        <article class="admin-ai-config-kpi-card admin-ai-config-kpi-card--primary">
            <div class="admin-ai-config-kpi-card__header">
                <span class="admin-ai-config-kpi-card__icon" aria-hidden="true"></span>
                <p class="admin-ai-config-kpi-card__label">Prompt Profiles</p>
            </div>
            <div class="admin-ai-config-kpi-card__body">
                <strong>{{ number_format($promptProfiles->count(), 0, ',', '.') }}</strong>
                <p class="admin-ai-config-kpi-card__description">Draft, active, archive</p>
            </div>
        </article>
        <article class="admin-ai-config-kpi-card admin-ai-config-kpi-card--success">
            <div class="admin-ai-config-kpi-card__header">
                <span class="admin-ai-config-kpi-card__icon" aria-hidden="true"></span>
                <p class="admin-ai-config-kpi-card__label">Model Configs</p>
            </div>
            <div class="admin-ai-config-kpi-card__body">
                <strong>{{ number_format($modelConfigs->count(), 0, ',', '.') }}</strong>
                <p class="admin-ai-config-kpi-card__description">Allowlist server</p>
            </div>
        </article>
        <article class="admin-ai-config-kpi-card admin-ai-config-kpi-card--warning">
            <div class="admin-ai-config-kpi-card__header">
                <span class="admin-ai-config-kpi-card__icon" aria-hidden="true"></span>
                <p class="admin-ai-config-kpi-card__label">Active Prompt</p>
            </div>
            <div class="admin-ai-config-kpi-card__body">
                <strong>{{ $promptProfiles->where('status', 'active')->count() }}</strong>
                <p class="admin-ai-config-kpi-card__description">Per fitur runtime</p>
            </div>
        </article>
        <article class="admin-ai-config-kpi-card admin-ai-config-kpi-card--info">
            <div class="admin-ai-config-kpi-card__header">
                <span class="admin-ai-config-kpi-card__icon" aria-hidden="true"></span>
                <p class="admin-ai-config-kpi-card__label">Active Model</p>
            </div>
            <div class="admin-ai-config-kpi-card__body">
                <strong>{{ $modelConfigs->where('status', 'active')->count() }}</strong>
                <p class="admin-ai-config-kpi-card__description">Dengan fallback aman</p>
            </div>
        </article>
    </div>

    <div class="grid gap-4 xl:grid-cols-[1.1fr_0.9fr]">
        <section class="admin-section rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950">
            <header class="mb-4 flex items-start justify-between gap-3">
                <div>
                    <h3 class="text-sm font-bold text-zinc-950 dark:text-zinc-50">Prompt Profile</h3>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">Draft tidak memengaruhi runtime sampai diaktifkan.</p>
                </div>
                <x-admin.badge tone="neutral">Versioned</x-admin.badge>
            </header>

            <form wire:submit="savePromptDraft" class="grid gap-3">
                <div class="grid gap-3 md:grid-cols-2">
                    <label class="grid gap-1 text-xs font-semibold text-zinc-600 dark:text-zinc-300">
                        Feature
                        <select wire:model="promptFeature" class="rounded-lg border-zinc-200 text-sm dark:border-zinc-700 dark:bg-zinc-900">
                            @foreach ($featureOptions as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="grid gap-1 text-xs font-semibold text-zinc-600 dark:text-zinc-300">
                        Nama
                        <input wire:model="promptName" type="text" class="rounded-lg border-zinc-200 text-sm dark:border-zinc-700 dark:bg-zinc-900" placeholder="Contoh: Chat formal v1">
                    </label>
                </div>
                <label class="grid gap-1 text-xs font-semibold text-zinc-600 dark:text-zinc-300">
                    System Prompt
                    <textarea wire:model="promptBody" rows="7" class="rounded-lg border-zinc-200 text-sm dark:border-zinc-700 dark:bg-zinc-900" placeholder="Tulis perilaku AI yang ingin diuji..."></textarea>
                </label>
                @error('promptBody') <p class="text-xs font-semibold text-red-600">{{ $message }}</p> @enderror
                <button type="submit" class="inline-flex w-fit items-center rounded-lg bg-ista-primary px-3 py-2 text-xs font-bold text-white shadow-sm hover:bg-red-700 dark:bg-red-500 dark:hover:bg-red-400">
                    Simpan Draft Prompt
                </button>
            </form>
        </section>

        <section class="admin-section rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950">
            <header class="mb-4 flex items-start justify-between gap-3">
                <div>
                    <h3 class="text-sm font-bold text-zinc-950 dark:text-zinc-50">Model Config</h3>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">Model hanya bisa dipilih dari allowlist server.</p>
                </div>
                <x-admin.badge tone="neutral">No secrets</x-admin.badge>
            </header>

            <form wire:submit="saveModelDraft" class="grid gap-3">
                <div class="grid gap-3 md:grid-cols-2">
                    <label class="grid gap-1 text-xs font-semibold text-zinc-600 dark:text-zinc-300">
                        Feature
                        <select wire:model="modelFeature" class="rounded-lg border-zinc-200 text-sm dark:border-zinc-700 dark:bg-zinc-900">
                            @foreach ($featureOptions as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="grid gap-1 text-xs font-semibold text-zinc-600 dark:text-zinc-300">
                        Nama
                        <input wire:model="modelName" type="text" class="rounded-lg border-zinc-200 text-sm dark:border-zinc-700 dark:bg-zinc-900" placeholder="Contoh: Chat GPT Mini">
                    </label>
                </div>
                <label class="grid gap-1 text-xs font-semibold text-zinc-600 dark:text-zinc-300">
                    Model Utama
                    <select wire:model="modelSelection" class="rounded-lg border-zinc-200 text-sm dark:border-zinc-700 dark:bg-zinc-900">
                        @foreach ($modelOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="grid gap-1 text-xs font-semibold text-zinc-600 dark:text-zinc-300">
                    Fallback Model
                    <select wire:model="fallbackModelSelection" class="rounded-lg border-zinc-200 text-sm dark:border-zinc-700 dark:bg-zinc-900">
                        <option value="">Tidak ada</option>
                        @foreach ($modelOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="grid gap-3 md:grid-cols-4">
                    <input wire:model="modelTemperature" type="number" step="0.1" min="0" max="2" class="rounded-lg border-zinc-200 text-sm dark:border-zinc-700 dark:bg-zinc-900" placeholder="Temp">
                    <input wire:model="modelMaxTokens" type="number" min="128" max="8192" class="rounded-lg border-zinc-200 text-sm dark:border-zinc-700 dark:bg-zinc-900" placeholder="Max token">
                    <input wire:model="modelTimeoutSeconds" type="number" min="5" max="180" class="rounded-lg border-zinc-200 text-sm dark:border-zinc-700 dark:bg-zinc-900" placeholder="Timeout">
                    <input wire:model="modelRetrievalTopK" type="number" min="1" max="8" class="rounded-lg border-zinc-200 text-sm dark:border-zinc-700 dark:bg-zinc-900" placeholder="Top K">
                </div>
                @error('modelName') <p class="text-xs font-semibold text-red-600">{{ $message }}</p> @enderror
                @error('modelSelection') <p class="text-xs font-semibold text-red-600">{{ $message }}</p> @enderror
                @error('fallbackModelSelection') <p class="text-xs font-semibold text-red-600">{{ $message }}</p> @enderror
                <button type="submit" class="inline-flex w-fit items-center rounded-lg bg-ista-primary px-3 py-2 text-xs font-bold text-white shadow-sm hover:bg-red-700 dark:bg-red-500 dark:hover:bg-red-400">
                    Simpan Draft Model
                </button>
            </form>
        </section>
    </div>

    <div class="grid gap-4 xl:grid-cols-2">
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
                            <td class="admin-table__td"><x-admin.badge :tone="$statusTone($profile->status)">{{ ucfirst($profile->status) }}</x-admin.badge></td>
                            <td class="admin-table__td">v{{ $profile->version }}</td>
                            <td class="admin-table__td" data-align="right">
                                <div class="flex justify-end gap-2">
                                    @if ($profile->status !== 'active')
                                        <button wire:click="activatePrompt({{ $profile->id }})" class="rounded-md border border-red-200 px-2 py-1 text-xs font-bold text-red-700 dark:border-red-500/30 dark:text-red-300">Activate</button>
                                    @endif
                                    @if ($profile->status !== 'archived')
                                        <button wire:click="archivePrompt({{ $profile->id }})" class="rounded-md border border-zinc-200 px-2 py-1 text-xs font-bold text-zinc-600 dark:border-zinc-700 dark:text-zinc-300">Archive</button>
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
                            <td class="admin-table__td"><x-admin.badge :tone="$statusTone($config->status)">{{ ucfirst($config->status) }}</x-admin.badge></td>
                            <td class="admin-table__td">v{{ $config->version }}</td>
                            <td class="admin-table__td" data-align="right">
                                <div class="flex justify-end gap-2">
                                    @if ($config->status !== 'active')
                                        <button wire:click="activateModel({{ $config->id }})" class="rounded-md border border-red-200 px-2 py-1 text-xs font-bold text-red-700 dark:border-red-500/30 dark:text-red-300">Activate</button>
                                    @endif
                                    @if ($config->status !== 'archived')
                                        <button wire:click="archiveModel({{ $config->id }})" class="rounded-md border border-zinc-200 px-2 py-1 text-xs font-bold text-zinc-600 dark:border-zinc-700 dark:text-zinc-300">Archive</button>
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
    </div>

    <div class="grid gap-4 xl:grid-cols-[0.95fr_1.05fr]">
        <section class="admin-section rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950">
            <header class="mb-4">
                <h3 class="text-sm font-bold text-zinc-950 dark:text-zinc-50">Playground Preview</h3>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">Dry-run aman: tidak mengirim prompt uji ke provider AI.</p>
            </header>
            <form wire:submit="runPlayground" class="grid gap-3">
                <select wire:model="playgroundFeature" class="rounded-lg border-zinc-200 text-sm dark:border-zinc-700 dark:bg-zinc-900">
                    @foreach ($featureOptions as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
                <textarea wire:model="playgroundInput" rows="4" class="rounded-lg border-zinc-200 text-sm dark:border-zinc-700 dark:bg-zinc-900"></textarea>
                <button type="submit" class="inline-flex w-fit items-center rounded-lg bg-ista-primary px-3 py-2 text-xs font-bold text-white shadow-sm hover:bg-red-700 dark:bg-red-500 dark:hover:bg-red-400">
                    Run Preview
                </button>
            </form>
            @if ($playgroundResult)
                <dl class="mt-4 grid gap-2 text-xs">
                    <div class="flex justify-between gap-3"><dt class="text-zinc-500">Ready</dt><dd class="font-bold">{{ $playgroundResult['ready'] ? 'Ya' : 'Belum' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-zinc-500">Prompt</dt><dd class="font-bold">{{ $playgroundResult['prompt_profile_name'] ?? '-' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-zinc-500">Model</dt><dd class="font-bold">{{ $playgroundResult['model_name'] ?? '-' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-zinc-500">Model count</dt><dd class="font-bold">{{ $playgroundResult['chat_model_count'] }}</dd></div>
                </dl>
            @endif
        </section>

        <section class="admin-ai-config-table-panel admin-section">
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
