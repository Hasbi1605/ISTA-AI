<?php

namespace App\Livewire\Admin;

use App\Models\AIUsageEvent;
use App\Services\Admin\AdminMetricsService;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin', ['title' => 'Errors', 'heading' => 'AI Errors'])]
class AdminErrors extends Component
{
    public string $feature = '';

    public string $startDate = '';

    public string $endDate = '';

    public string $requestId = '';

    /**
     * @var array<string, array<int, string>>
     */
    protected $queryString = [
        'feature' => ['except' => ''],
        'startDate' => ['except' => ''],
        'endDate' => ['except' => ''],
        'requestId' => ['except' => ''],
    ];

    public function resetFilters(): void
    {
        $this->reset(['feature', 'startDate', 'endDate', 'requestId']);
    }

    public function render(AdminMetricsService $metrics)
    {
        $filters = [
            'feature' => $this->feature ?: null,
            'start_date' => $this->startDate ?: null,
            'end_date' => $this->endDate ?: null,
            'request_id' => $this->requestId ?: null,
        ];

        $errors = $metrics->recentErrors($filters, AdminMetricsService::RECENT_ROWS_LIMIT);

        $byFeature = $errors->groupBy('feature')->map->count()->sortDesc();
        $byCode = $errors->whereNotNull('error_code')->groupBy('error_code')->map->count()->sortDesc();

        return view('livewire.admin.admin-errors', [
            'errors' => $errors,
            'byFeature' => $byFeature,
            'byCode' => $byCode,
            'featureOptions' => [
                AIUsageEvent::FEATURE_CHAT => 'Chat',
                AIUsageEvent::FEATURE_DOCUMENT_RAG => 'Chat Dokumen (RAG)',
                AIUsageEvent::FEATURE_WEB_SEARCH => 'Web Search',
                AIUsageEvent::FEATURE_DOCUMENT_UPLOAD => 'Upload Dokumen',
                AIUsageEvent::FEATURE_DOCUMENT_PROCESSING => 'Proses Dokumen',
                AIUsageEvent::FEATURE_MEMO_GENERATION => 'Memo: Generate',
                AIUsageEvent::FEATURE_MEMO_REVISION => 'Memo: Revisi',
                AIUsageEvent::FEATURE_GOOGLE_DRIVE_IMPORT => 'Drive Import',
                AIUsageEvent::FEATURE_GOOGLE_DRIVE_EXPORT => 'Drive Export',
            ],
        ]);
    }
}
