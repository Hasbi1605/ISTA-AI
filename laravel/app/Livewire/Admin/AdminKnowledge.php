<?php

namespace App\Livewire\Admin;

use App\Models\KnowledgeDocument;
use App\Models\KnowledgeSource;
use App\Services\Knowledge\KnowledgeLifecycleService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

#[Layout('layouts.admin', ['title' => 'Knowledge', 'heading' => 'Knowledge Base Internal'])]
class AdminKnowledge extends Component
{
    use WithFileUploads;
    use WithPagination;

    private const DOCUMENTS_PER_PAGE = 10;

    public string $search = '';

    public string $status = '';

    public string $sourceFilter = '';

    public string $title = '';

    public string $newSourceName = '';

    public ?int $sourceId = null;

    public string $notes = '';

    public bool $showUploadModal = false;

    /**
     * @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null
     */
    public $file = null;

    /**
     * @var array<string, array<int, string>>
     */
    protected $queryString = [
        'search' => ['except' => ''],
        'status' => ['except' => ''],
        'sourceFilter' => ['except' => ''],
    ];

    public function resetFilters(): void
    {
        $this->reset(['search', 'status', 'sourceFilter']);
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function updatingSourceFilter(): void
    {
        $this->resetPage();
    }

    public function openUploadModal(): void
    {
        $this->resetValidation();
        $this->showUploadModal = true;
    }

    public function closeUploadModal(): void
    {
        $this->resetValidation();
        $this->showUploadModal = false;
    }

    public function updatedFile(): void
    {
        $this->resetValidation('file');
    }

    public function updatedSourceId(): void
    {
        $this->resetValidation(['sourceId', 'newSourceName']);
    }

    public function updatedNewSourceName(): void
    {
        $this->resetValidation(['sourceId', 'newSourceName']);
    }

    public function upload(KnowledgeLifecycleService $lifecycle): void
    {
        $this->validate([
            'file' => [
                'required',
                'file',
                'max:'.KnowledgeLifecycleService::MAX_DOCUMENT_SIZE_KILOBYTES,
                'extensions:'.implode(',', KnowledgeLifecycleService::ALLOWED_EXTENSIONS),
                'mimetypes:'.implode(',', KnowledgeLifecycleService::ALLOWED_MIME_TYPES),
            ],
            'title' => ['nullable', 'string', 'max:191'],
            'newSourceName' => ['required_without:sourceId', 'nullable', 'string', 'max:191'],
            'sourceId' => ['nullable', 'integer', 'exists:knowledge_sources,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'file.required' => 'Pilih file knowledge terlebih dahulu.',
            'newSourceName.required_without' => 'Pilih source existing atau isi source baru.',
        ]);

        $admin = auth()->user();

        if ($admin === null) {
            $this->addError('file', 'Sesi admin tidak valid. Silakan login ulang.');

            return;
        }

        $sourceArg = $this->resolveSourceArgument();

        try {
            $lifecycle->upload($this->file, $admin, [
                'title' => $this->title ?: null,
                'knowledge_source_id' => $sourceArg,
                'notes' => $this->notes ?: null,
            ]);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                foreach ((array) $messages as $message) {
                    $this->addError($field, $message);
                }
            }

            return;
        }

        $this->reset(['file', 'title', 'newSourceName', 'sourceId', 'notes']);
        $this->showUploadModal = false;
        $this->dispatch('knowledge-uploaded');
        session()->flash('knowledge_status', 'Dokumen knowledge berhasil di-upload dan sedang diproses.');
    }

    public function activate(int $documentId, KnowledgeLifecycleService $lifecycle): void
    {
        $document = KnowledgeDocument::findOrFail($documentId);
        $admin = auth()->user();

        if ($admin === null) {
            return;
        }

        $lifecycle->activate($document, $admin);
        session()->flash('knowledge_status', 'Dokumen knowledge diaktifkan.');
    }

    public function archive(int $documentId, KnowledgeLifecycleService $lifecycle): void
    {
        $document = KnowledgeDocument::findOrFail($documentId);
        $admin = auth()->user();

        if ($admin === null) {
            return;
        }

        $lifecycle->archive($document, $admin);
        session()->flash('knowledge_status', 'Dokumen knowledge di-archive.');
    }

    public function reprocess(int $documentId, KnowledgeLifecycleService $lifecycle): void
    {
        $document = KnowledgeDocument::findOrFail($documentId);
        $admin = auth()->user();

        if ($admin === null) {
            return;
        }

        $lifecycle->reprocess($document, $admin);
        session()->flash('knowledge_status', 'Dokumen knowledge dijadwalkan untuk diproses ulang.');
    }

    public function delete(int $documentId, KnowledgeLifecycleService $lifecycle): void
    {
        $document = KnowledgeDocument::findOrFail($documentId);
        $admin = auth()->user();

        if ($admin === null) {
            return;
        }

        $lifecycle->delete($document, $admin);
        session()->flash('knowledge_status', 'Dokumen knowledge berhasil dihapus beserta vektornya.');
    }

    public function render()
    {
        $documentsQuery = KnowledgeDocument::query()
            ->select([
                'id',
                'knowledge_source_id',
                'uploaded_by_id',
                'title',
                'original_name',
                'mime_type',
                'file_size_bytes',
                'scope',
                'audience',
                'status',
                'processed_at',
                'archived_at',
                'failed_at',
                'error_code',
                'error_message',
                'created_at',
                'updated_at',
            ])
            ->with(['source:id,name,slug', 'uploader:id,name,email', 'chunks']);

        if ($this->status !== '') {
            $documentsQuery->where('status', $this->status);
        }

        if (ctype_digit($this->sourceFilter)) {
            $documentsQuery->where('knowledge_source_id', (int) $this->sourceFilter);
        }

        if ($this->search !== '') {
            $term = '%'.trim($this->search).'%';
            $documentsQuery->where(function ($builder) use ($term) {
                $builder->where('title', 'like', $term)
                    ->orWhere('original_name', 'like', $term);
            });
        }

        $documents = $documentsQuery->orderByDesc('created_at')->paginate(self::DOCUMENTS_PER_PAGE);

        $statusCounts = KnowledgeDocument::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->mapWithKeys(fn ($total, $status) => [(string) ($status ?: 'unknown') => (int) $total])
            ->all();

        $sources = KnowledgeSource::query()->orderBy('name')->get();

        return view('livewire.admin.admin-knowledge', [
            'documents' => $documents,
            'documentsPerPage' => self::DOCUMENTS_PER_PAGE,
            'statusCounts' => $statusCounts,
            'sources' => $sources,
            'statusOptions' => [
                KnowledgeDocument::STATUS_DRAFT => 'Draft',
                KnowledgeDocument::STATUS_PROCESSING => 'Processing',
                KnowledgeDocument::STATUS_ACTIVE => 'Active',
                KnowledgeDocument::STATUS_ERROR => 'Error',
                KnowledgeDocument::STATUS_ARCHIVED => 'Archived',
            ],
            'allowedExtensions' => ['pdf', 'docx', 'xlsx', 'csv'],
        ]);
    }

    private function resolveSourceArgument(): mixed
    {
        $newName = trim($this->newSourceName);

        if ($newName !== '') {
            return $newName;
        }

        if ($this->sourceId !== null) {
            return $this->sourceId;
        }

        return null;
    }
}
