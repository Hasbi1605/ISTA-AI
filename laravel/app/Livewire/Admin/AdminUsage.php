<?php

namespace App\Livewire\Admin;

use App\Models\AIUsageEvent;
use App\Services\Admin\AdminMetricsService;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.admin', ['title' => 'Usage', 'heading' => 'Usage'])]
class AdminUsage extends Component
{
    use WithPagination;

    private const EVENTS_PER_PAGE = 5;

    public string $feature = '';

    public string $status = '';

    public string $startDate = '';

    public string $endDate = '';

    public bool $showLifecycleEvents = false;

    /**
     * @var array<string, array<int, string>>
     */
    protected $queryString = [
        'feature' => ['except' => ''],
        'status' => ['except' => ''],
        'startDate' => ['except' => ''],
        'endDate' => ['except' => ''],
        'showLifecycleEvents' => ['except' => false],
    ];

    public function updatingFeature(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function updatingStartDate(): void
    {
        $this->resetPage();
    }

    public function updatingEndDate(): void
    {
        $this->resetPage();
    }

    public function updatingShowLifecycleEvents(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['feature', 'status', 'startDate', 'endDate', 'showLifecycleEvents']);
        $this->resetPage();
    }

    public function render(AdminMetricsService $metrics)
    {
        $filters = [
            'feature' => $this->feature ?: null,
            'status' => $this->status ?: null,
            'start_date' => $this->startDate ?: null,
            'end_date' => $this->endDate ?: null,
        ];

        $listingFilters = $filters;
        $hideLifecycleEvents = ! $this->showLifecycleEvents && $this->status === '';
        if ($hideLifecycleEvents) {
            $listingFilters['exclude_lifecycle'] = true;
        }

        $events = $metrics->usageEventsListing($listingFilters, self::EVENTS_PER_PAGE, $this->getPage());
        $totals = $metrics->usageEventSummary($listingFilters);

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

        return view('livewire.admin.admin-usage', [
            'events' => $events,
            'eventsPerPage' => self::EVENTS_PER_PAGE,
            'hideLifecycleEvents' => $hideLifecycleEvents,
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
            AIUsageEvent::FEATURE_KNOWLEDGE_ADMIN => 'Knowledge (Admin)',
            AIUsageEvent::FEATURE_PROMPT_GENERATION => 'Prompy Studio: Generate',
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
