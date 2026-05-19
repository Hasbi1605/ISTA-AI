<?php

namespace App\Livewire\Admin;

use App\Models\AIUsageEvent;
use App\Services\Admin\AdminMetricsService;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin', ['title' => 'Usage', 'heading' => 'AI Usage'])]
class AdminUsage extends Component
{
    public string $feature = '';

    public string $status = '';

    public string $startDate = '';

    public string $endDate = '';

    /**
     * @var array<string, array<int, string>>
     */
    protected $queryString = [
        'feature' => ['except' => ''],
        'status' => ['except' => ''],
        'startDate' => ['except' => ''],
        'endDate' => ['except' => ''],
    ];

    public function resetFilters(): void
    {
        $this->reset(['feature', 'status', 'startDate', 'endDate']);
    }

    public function render(AdminMetricsService $metrics)
    {
        $filters = [
            'feature' => $this->feature ?: null,
            'status' => $this->status ?: null,
            'start_date' => $this->startDate ?: null,
            'end_date' => $this->endDate ?: null,
        ];

        $events = $metrics->recentEvents($filters, AdminMetricsService::RECENT_ROWS_LIMIT);

        // Normalize dates safely. Malformed query strings are dropped here so
        // the dashboard never throws a 500 on unparseable input. Default
        // window matches the service-level default range.
        [$startInput, $endInput] = $metrics->safeDateRange(
            $this->startDate ?: null,
            $this->endDate ?: null,
        );

        $distributionStart = $startInput
            ?? now()->subDays(AdminMetricsService::DEFAULT_RANGE_DAYS - 1)->startOfDay();
        $distributionEnd = $endInput ?? now();

        $distribution = $metrics->featureDistribution($distributionStart, $distributionEnd);

        $totals = [
            'total' => $events->count(),
            'success' => $events->where('status', AIUsageEvent::STATUS_SUCCESS)->count(),
            'failed' => $events->where('status', AIUsageEvent::STATUS_ERROR)->count(),
            'pending' => $events->where('status', AIUsageEvent::STATUS_PENDING)->count(),
        ];

        return view('livewire.admin.admin-usage', [
            'events' => $events,
            'distribution' => $distribution,
            'totals' => $totals,
            'featureOptions' => $this->featureOptions(),
            'statusOptions' => $this->statusOptions(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function featureOptions(): array
    {
        return [
            AIUsageEvent::FEATURE_CHAT => 'Chat',
            AIUsageEvent::FEATURE_DOCUMENT_RAG => 'Chat Dokumen (RAG)',
            AIUsageEvent::FEATURE_WEB_SEARCH => 'Web Search',
            AIUsageEvent::FEATURE_DOCUMENT_UPLOAD => 'Upload Dokumen',
            AIUsageEvent::FEATURE_DOCUMENT_PROCESSING => 'Proses Dokumen',
            AIUsageEvent::FEATURE_MEMO_GENERATION => 'Memo: Generate',
            AIUsageEvent::FEATURE_MEMO_REVISION => 'Memo: Revisi',
            AIUsageEvent::FEATURE_GOOGLE_DRIVE_IMPORT => 'Drive Import',
            AIUsageEvent::FEATURE_GOOGLE_DRIVE_EXPORT => 'Drive Export',
            AIUsageEvent::FEATURE_KNOWLEDGE_ADMIN => 'Knowledge (Admin)',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function statusOptions(): array
    {
        return [
            AIUsageEvent::STATUS_PENDING => 'Pending',
            AIUsageEvent::STATUS_SUCCESS => 'Success',
            AIUsageEvent::STATUS_ERROR => 'Error',
            AIUsageEvent::STATUS_BLOCKED => 'Blocked',
        ];
    }
}
