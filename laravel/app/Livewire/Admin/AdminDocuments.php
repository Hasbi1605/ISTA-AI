<?php

namespace App\Livewire\Admin;

use App\Services\Admin\AdminMetricsService;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin', ['title' => 'Documents', 'heading' => 'Dokumen'])]
class AdminDocuments extends Component
{
    public string $search = '';

    public string $status = '';

    /**
     * @var array<string, array<int, string>>
     */
    protected $queryString = [
        'search' => ['except' => ''],
        'status' => ['except' => ''],
    ];

    public function resetFilters(): void
    {
        $this->reset(['search', 'status']);
    }

    public function render(AdminMetricsService $metrics)
    {
        $payload = $metrics->documentListing([
            'search' => $this->search ?: null,
            'status' => $this->status ?: null,
        ], AdminMetricsService::RECENT_ROWS_LIMIT);

        return view('livewire.admin.admin-documents', [
            'documents' => $payload['rows'],
            'statusCounts' => $payload['status_counts'],
            'mimeCounts' => $payload['mime_counts'],
            'totalSizeBytes' => $payload['total_size_bytes'],
            'statusOptions' => [
                'pending' => 'Pending',
                'processing' => 'Processing',
                'ready' => 'Ready',
                'error' => 'Error',
            ],
        ]);
    }
}
