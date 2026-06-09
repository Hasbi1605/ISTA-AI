<?php

namespace App\Livewire\Admin;

use App\Models\AIUsageEvent;
use App\Services\Admin\AdminMetricsService;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.admin', ['title' => 'Errors', 'heading' => 'Errors'])]
class AdminErrors extends Component
{
    use WithPagination;

    private const ERRORS_PER_PAGE = 5;

    public string $feature = '';

    public string $startDate = '';

    public string $endDate = '';

    public string $requestId = '';

    public ?int $selectedErrorId = null;

    /**
     * @var array<string, array<int, string>>
     */
    protected $queryString = [
        'feature' => ['except' => ''],
        'startDate' => ['except' => ''],
        'endDate' => ['except' => ''],
        'requestId' => ['except' => ''],
    ];

    public function updatingFeature(): void
    {
        $this->resetPage();
        $this->closeDetail();
    }

    public function updatingStartDate(): void
    {
        $this->resetPage();
        $this->closeDetail();
    }

    public function updatingEndDate(): void
    {
        $this->resetPage();
        $this->closeDetail();
    }

    public function updatingRequestId(): void
    {
        $this->resetPage();
        $this->closeDetail();
    }

    public function resetFilters(): void
    {
        $this->reset(['feature', 'startDate', 'endDate', 'requestId']);
        $this->resetPage();
        $this->closeDetail();
    }

    public function showDetail(int $eventId): void
    {
        $this->selectedErrorId = $eventId;
    }

    public function closeDetail(): void
    {
        $this->selectedErrorId = null;
    }

    public function render(AdminMetricsService $metrics)
    {
        $filters = [
            'feature' => $this->feature ?: null,
            'start_date' => $this->startDate ?: null,
            'end_date' => $this->endDate ?: null,
            'request_id' => $this->requestId ?: null,
        ];

        $errors = $metrics->errorEventsListing($filters, self::ERRORS_PER_PAGE, $this->getPage());
        $errorSummary = $metrics->errorEventSummary($filters);
        $selectedError = $this->selectedErrorId !== null
            ? $metrics->errorEventDetail($this->selectedErrorId)
            : null;

        return view('livewire.admin.admin-errors', [
            'errors' => $errors,
            'errorsPerPage' => self::ERRORS_PER_PAGE,
            'selectedError' => $selectedError,
            'errorSummary' => $errorSummary,
            'byFeature' => $errorSummary['by_feature'],
            'byCode' => $errorSummary['by_code'],
            'featureOptions' => [
                AIUsageEvent::FEATURE_CHAT => 'Chat',
                AIUsageEvent::FEATURE_DOCUMENT_RAG => 'Chat Dokumen (RAG)',
                AIUsageEvent::FEATURE_WEB_SEARCH => 'Web Search',
                AIUsageEvent::FEATURE_DOCUMENT_UPLOAD => 'Upload Dokumen',
                AIUsageEvent::FEATURE_DOCUMENT_PROCESSING => 'Proses Dokumen',
                AIUsageEvent::FEATURE_MEMO_GENERATION => 'Memo: Generate',
                AIUsageEvent::FEATURE_MEMO_REVISION => 'Memo: Revisi',
            ],
        ]);
    }
}
