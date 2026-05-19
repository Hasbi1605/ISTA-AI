<?php

namespace App\Livewire\Admin;

use App\Services\Admin\AdminMetricsService;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.admin', ['title' => 'Documents', 'heading' => 'Dokumen'])]
class AdminDocuments extends Component
{
    use WithPagination;

    private const DOCUMENTS_PER_PAGE = 10;

    public string $search = '';

    public string $status = '';

    public string $type = '';

    public string $ownerId = '';

    public string $startDate = '';

    public string $endDate = '';

    public ?int $selectedDocumentId = null;

    /**
     * @var array<string, array<int, string>>
     */
    protected $queryString = [
        'search' => ['except' => ''],
        'status' => ['except' => ''],
        'type' => ['except' => ''],
        'ownerId' => ['except' => ''],
        'startDate' => ['except' => ''],
        'endDate' => ['except' => ''],
    ];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function updatingType(): void
    {
        $this->resetPage();
    }

    public function updatingOwnerId(): void
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

    public function resetFilters(): void
    {
        $this->reset(['search', 'status', 'type', 'ownerId', 'startDate', 'endDate']);
        $this->resetPage();
    }

    public function showDetail(int $documentId): void
    {
        $this->selectedDocumentId = $documentId;
    }

    public function closeDetail(): void
    {
        $this->selectedDocumentId = null;
    }

    public function render(AdminMetricsService $metrics)
    {
        $payload = $metrics->documentListing([
            'search' => $this->search ?: null,
            'status' => $this->normalizedStatus(),
            'type' => $this->normalizedType(),
            'user_id' => $this->normalizedOwnerId(),
            'start_date' => $this->startDate ?: null,
            'end_date' => $this->endDate ?: null,
        ], self::DOCUMENTS_PER_PAGE, $this->getPage());

        return view('livewire.admin.admin-documents', [
            'documents' => $payload['rows'],
            'documentsPerPage' => self::DOCUMENTS_PER_PAGE,
            'statusCounts' => $payload['status_counts'],
            'mimeCounts' => $payload['mime_counts'],
            'totalSizeBytes' => $payload['total_size_bytes'],
            'ownerOptions' => $metrics->documentOwnerOptions(),
            'selectedDocument' => $metrics->documentDetail($this->selectedDocumentId),
            'typeOptions' => [
                'pdf' => 'PDF',
                'csv' => 'CSV',
                'xlsx' => 'XLS / XLSX',
                'docx' => 'DOCX',
            ],
            'statusOptions' => [
                'pending' => 'Pending',
                'processing' => 'Processing',
                'ready' => 'Ready',
                'error' => 'Failed',
            ],
        ]);
    }

    private function normalizedStatus(): ?string
    {
        return in_array($this->status, ['pending', 'processing', 'ready', 'error'], true)
            ? $this->status
            : null;
    }

    private function normalizedType(): ?string
    {
        return in_array($this->type, ['pdf', 'csv', 'xlsx', 'docx'], true) ? $this->type : null;
    }

    private function normalizedOwnerId(): ?int
    {
        return ctype_digit($this->ownerId) ? (int) $this->ownerId : null;
    }
}
