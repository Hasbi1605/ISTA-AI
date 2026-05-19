@php
    $aiServiceUrl = rtrim((string) config('services.ai_service.url', 'http://127.0.0.1:8001'), '/');
    $documentServiceUrl = rtrim((string) config('services.ai_document_service.url', $aiServiceUrl), '/');
    $connectTimeout = (int) config('services.ai_service.connect_timeout', 10);
    $requestTimeout = (int) config('services.ai_service.timeout', 120);
    $readTimeout = (int) config('services.ai_service.read_timeout', 120);
    $retryCount = (int) config('services.ai_service.retries', 2);
    $retryDelay = (int) config('services.ai_service.retry_delay_ms', 400);
    $maxHistory = (int) config('services.ai_service.max_history_messages', 20);
    $aiToken = config('services.ai_service.token');
    $documentToken = config('services.ai_document_service.token');
    $aiTokenConfigured = filled($aiToken);
    $documentTokenConfigured = filled($documentToken);
    $documentTokenStatus = match (true) {
        ! $documentTokenConfigured => 'Belum diset',
        $documentToken === $aiToken => 'Mengikuti AI token',
        default => 'Tersedia',
    };

    $hostLabel = function (string $url): string {
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);

        if (! is_string($host) || $host === '') {
            return '-';
        }

        return $host . ($port ? ':' . $port : '');
    };

    $runtimeCards = [
        [
            'label' => 'AI Service',
            'value' => $hostLabel($aiServiceUrl),
            'description' => 'Endpoint chat utama',
            'tone' => 'primary',
            'icon' => 'M5 12h14M7 5h10a2 2 0 012 2v10a2 2 0 01-2 2H7a2 2 0 01-2-2V7a2 2 0 012-2zM8 9h.01M8 15h.01',
        ],
        [
            'label' => 'Document Service',
            'value' => $hostLabel($documentServiceUrl),
            'description' => 'Parsing, RAG, dan indexing',
            'tone' => 'success',
            'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.5L19 9.5V19a2 2 0 01-2 2z',
        ],
        [
            'label' => 'Retry Policy',
            'value' => max(1, $retryCount) . 'x',
            'description' => number_format(max(0, $retryDelay), 0, ',', '.') . ' ms backoff',
            'tone' => 'warning',
            'icon' => 'M4 4v6h6M20 20v-6h-6M5.5 14a7 7 0 0012 3M18.5 10a7 7 0 00-12-3',
        ],
        [
            'label' => 'History Window',
            'value' => max(0, $maxHistory),
            'description' => 'Pesan konteks maksimum',
            'tone' => 'info',
            'icon' => 'M12 6v6l3 2M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        ],
    ];

    $routingRows = [
        [
            'feature' => 'Chat Assistant',
            'lane' => 'chat',
            'strategy' => 'Python cascade',
            'status' => 'Aktif',
            'tone' => 'success',
        ],
        [
            'feature' => 'Document RAG',
            'lane' => 'document_rag',
            'strategy' => 'Retrieval + chat cascade',
            'status' => 'Aktif',
            'tone' => 'success',
        ],
        [
            'feature' => 'Memo Generator',
            'lane' => 'memo_generation',
            'strategy' => 'Prompt template + chat cascade',
            'status' => 'Aktif',
            'tone' => 'success',
        ],
        [
            'feature' => 'Document Indexing',
            'lane' => 'embedding',
            'strategy' => 'Embedding fallback',
            'status' => 'Aktif',
            'tone' => 'success',
        ],
        [
            'feature' => 'Web Search',
            'lane' => 'web_search',
            'strategy' => 'Realtime context gate',
            'status' => 'Aktif',
            'tone' => 'success',
        ],
    ];

    $runtimeParameters = [
        ['label' => 'Connect timeout', 'value' => $connectTimeout . 's'],
        ['label' => 'Request timeout', 'value' => $requestTimeout . 's'],
        ['label' => 'Read timeout', 'value' => $readTimeout . 's'],
        ['label' => 'Retry delay', 'value' => number_format(max(0, $retryDelay), 0, ',', '.') . ' ms'],
        ['label' => 'Max history', 'value' => max(0, $maxHistory) . ' pesan'],
    ];

    $promptProfiles = [
        ['name' => 'System Prompt', 'scope' => 'Global assistant behavior', 'source' => 'Python config'],
        ['name' => 'RAG Prompt', 'scope' => 'Jawaban berbasis dokumen', 'source' => 'Python config'],
        ['name' => 'Memo Prompt', 'scope' => 'Generator memo dinas', 'source' => 'Python config'],
        ['name' => 'Fallback Copy', 'scope' => 'Pesan saat konteks tidak tersedia', 'source' => 'Python config'],
    ];

    $guardrails = [
        ['label' => 'API token', 'value' => $aiTokenConfigured ? 'Tersedia' : 'Belum diset', 'tone' => $aiTokenConfigured ? 'success' : 'warning'],
        ['label' => 'Document token', 'value' => $documentTokenStatus, 'tone' => $documentTokenConfigured ? 'success' : 'warning'],
        ['label' => 'Prompt logging', 'value' => 'Tidak disimpan', 'tone' => 'success'],
        ['label' => 'Error sentinel', 'value' => 'Aktif', 'tone' => 'success'],
    ];
@endphp

<x-layouts.admin title="AI Configuration" heading="AI Configuration">
    <div class="admin-ai-config-page">
        <div class="admin-ai-config-hero">
            <div class="max-w-2xl">
                <p class="admin-ai-config-eyebrow">Administration</p>
                <h2 class="admin-ai-config-title">AI Configuration</h2>
                <p class="admin-ai-config-description">
                    Pantau routing model, prompt profile, parameter service, dan guardrail runtime AI.
                </p>
            </div>
            <div class="admin-ai-config-hero__actions">
                <span class="admin-ai-config-access-badge">
                    <span></span>
                    Akses terbatas
                </span>
                <x-admin.badge tone="neutral" class="admin-ai-config-readonly">Read-only</x-admin.badge>
            </div>
        </div>

        <div class="admin-ai-config-kpi-grid">
            @foreach ($runtimeCards as $card)
                <article class="admin-ai-config-kpi-card admin-ai-config-kpi-card--{{ $card['tone'] }}">
                    <div class="admin-ai-config-kpi-card__header">
                        <span class="admin-ai-config-kpi-card__icon" aria-hidden="true">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="{{ $card['icon'] }}"/>
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

        <div class="admin-ai-config-content-grid">
            <section class="admin-ai-config-table-panel admin-section">
                <header class="admin-ai-config-panel__header">
                    <div>
                        <h3>Model Routing</h3>
                        <p>Alur fitur AI yang berjalan melalui service Python dan fallback provider.</p>
                    </div>
                    <span class="admin-ai-config-source-chip">Runtime</span>
                </header>

                <div class="admin-ai-config-table-panel__body">
                    <x-admin.table
                        class="admin-ai-config-table"
                        :columns="[
                            ['key' => 'feature', 'label' => 'Fitur', 'width' => '28%'],
                            ['key' => 'lane', 'label' => 'Lane', 'width' => '21%'],
                            ['key' => 'strategy', 'label' => 'Strategi', 'width' => '31%'],
                            ['key' => 'status', 'label' => 'Status', 'align' => 'right', 'width' => '20%'],
                        ]">
                        @foreach ($routingRows as $row)
                            <tr>
                                <td class="admin-table__td">
                                    <span class="admin-ai-config-feature">{{ $row['feature'] }}</span>
                                </td>
                                <td class="admin-table__td">
                                    <span class="admin-ai-config-code">{{ $row['lane'] }}</span>
                                </td>
                                <td class="admin-table__td">
                                    <span class="admin-ai-config-muted">{{ $row['strategy'] }}</span>
                                </td>
                                <td class="admin-table__td" data-align="right">
                                    <x-admin.badge :tone="$row['tone']" class="admin-ai-config-status-badge">
                                        {{ $row['status'] }}
                                    </x-admin.badge>
                                </td>
                            </tr>
                        @endforeach
                    </x-admin.table>
                </div>
            </section>

            <aside class="admin-ai-config-side-grid">
                <section class="admin-ai-config-parameter-panel admin-section">
                    <header class="admin-ai-config-panel__header">
                        <div>
                            <h3>Parameter Runtime</h3>
                            <p>Nilai dibaca dari Laravel config.</p>
                        </div>
                    </header>
                    <div class="admin-ai-config-parameter-panel__body">
                        <dl class="admin-ai-config-parameter-list">
                            @foreach ($runtimeParameters as $parameter)
                                <div>
                                    <dt>{{ $parameter['label'] }}</dt>
                                    <dd>{{ $parameter['value'] }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                </section>

                <section class="admin-ai-config-guardrail-panel admin-section">
                    <header class="admin-ai-config-panel__header">
                        <div>
                            <h3>Guardrail</h3>
                            <p>Status keamanan konfigurasi.</p>
                        </div>
                    </header>
                    <div class="admin-ai-config-guardrail-panel__body">
                        <ul class="admin-ai-config-guardrail-list" role="list">
                            @foreach ($guardrails as $item)
                                <li>
                                    <span class="admin-ai-config-guardrail-dot admin-ai-config-guardrail-dot--{{ $item['tone'] }}" aria-hidden="true"></span>
                                    <div>
                                        <strong>{{ $item['label'] }}</strong>
                                        <em>{{ $item['value'] }}</em>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </section>
            </aside>
        </div>

        <div class="admin-ai-config-lower-grid">
            <section class="admin-ai-config-prompt-panel admin-section">
                <header class="admin-ai-config-panel__header">
                    <div>
                        <h3>Prompt Profiles</h3>
                        <p>Profil prompt yang menjadi kontrak perilaku AI saat ini.</p>
                    </div>
                    <span class="admin-ai-config-source-chip">Config source</span>
                </header>
                <div class="admin-ai-config-prompt-panel__body">
                    <div class="admin-ai-config-profile-grid">
                        @foreach ($promptProfiles as $profile)
                            <article class="admin-ai-config-profile-card">
                                <span></span>
                                <div>
                                    <strong>{{ $profile['name'] }}</strong>
                                    <p>{{ $profile['scope'] }}</p>
                                    <em>{{ $profile['source'] }}</em>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </div>
            </section>

            <section class="admin-ai-config-endpoint-panel admin-section">
                <header class="admin-ai-config-panel__header">
                    <div>
                        <h3>Service Endpoints</h3>
                        <p>Endpoint runtime tanpa secret.</p>
                    </div>
                </header>
                <div class="admin-ai-config-endpoint-panel__body">
                    <dl class="admin-ai-config-endpoint-list">
                        <div>
                            <dt>AI service URL</dt>
                            <dd title="{{ $aiServiceUrl }}">{{ $aiServiceUrl }}</dd>
                        </div>
                        <div>
                            <dt>Document service URL</dt>
                            <dd title="{{ $documentServiceUrl }}">{{ $documentServiceUrl }}</dd>
                        </div>
                        <div>
                            <dt>Config ownership</dt>
                            <dd>Env + Python YAML</dd>
                        </div>
                    </dl>
                </div>
            </section>
        </div>
    </div>
</x-layouts.admin>
