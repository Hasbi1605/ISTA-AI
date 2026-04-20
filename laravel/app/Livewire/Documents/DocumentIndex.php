<?php

namespace App\Livewire\Documents;

use App\Jobs\ProcessDocument;
use App\Models\Document;
use App\Services\AIService;
use App\Services\DocumentLifecycleService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.app')]
class DocumentIndex extends Component
{
    use WithFileUploads;

    private const ALLOWED_ATTACHMENT_MIME_TYPES = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public $file;
    public $isUploading = false;
    public $summarizingDocumentId = null;
    public $summaryResult = null;
    public $showSummaryModal = false;

    protected $rules = [
        'file' => [
            'required',
            'file',
            'mimes:pdf,docx,xlsx',
            'mimetypes:application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'max:51200',
        ],
    ];

    public function updatingFile($value)
    {
        logger()->info('Livewire is starting to handle a file upload: ', [
            'filename' => is_object($value) ? $value->getClientOriginalName() : 'unknown',
            'size' => is_object($value) ? $value->getSize() : 'unknown',
        ]);
    }

    public function updatedFile()
    {
        if ($this->file) {
            logger()->info('Livewire successfully received temporary file:', [
                'name' => $this->file->getClientOriginalName(),
                'size' => $this->file->getSize(),
                'mime' => $this->file->getMimeType(),
                'error' => $this->file->getError()
            ]);
        } else {
            logger()->warning('Livewire updated file property, but it is null or empty.');
        }
    }

    public function saveDocument(DocumentLifecycleService $documentLifecycleService)
    {
        $this->validate();

        $this->isUploading = true;

        try {
            $documentLifecycleService->uploadDocument($this->file, Auth::id());

            $this->reset('file');
            session()->flash('message', 'Dokumen berhasil diunggah dan sedang diproses.');
        } catch (ValidationException $e) {
            $errors = $e->validator->errors();
            $message = $errors->first('file') ?: 'Upload gagal. Periksa format file dan coba lagi.';
            $this->addError('file', $message);
        } catch (\Exception $e) {
            session()->flash('error', 'Gagal mengunggah dokumen: ' . $e->getMessage());
        } finally {
            $this->isUploading = false;
        }
    }

    public function delete($id, DocumentLifecycleService $documentLifecycleService)
    {
        $document = Document::where('user_id', Auth::id())->findOrFail($id);

        try {
            $documentLifecycleService->deleteDocument($document);
            session()->flash('message', 'Dokumen berhasil dihapus.');
        } catch (\Exception $e) {
            session()->flash('error', 'Gagal menghapus dokumen: ' . $e->getMessage());
        }
    }

    public function summarize($id, AIService $aiService, DocumentLifecycleService $documentLifecycleService)
    {
        $document = Document::where('user_id', Auth::id())->findOrFail($id);

        $this->summarizingDocumentId = $id;

        try {
            $result = $documentLifecycleService->summarizeDocument($document, $aiService);

            if ($result['status'] === 'success') {
                $this->summaryResult = $result['summary'];
                $this->showSummaryModal = true;
            } else {
                session()->flash('error', 'Gagal merangkum dokumen: ' . ($result['message'] ?? 'Unknown error'));
            }
        } catch (\InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());
        } catch (\Exception $e) {
            session()->flash('error', 'Gagal merangkum dokumen: ' . $e->getMessage());
        } finally {
            $this->summarizingDocumentId = null;
        }
    }

    public function closeSummaryModal()
    {
        $this->showSummaryModal = false;
        $this->summaryResult = null;
    }

    public function render()
    {
        $documents = Document::where('user_id', Auth::id())
            ->latest()
            ->get();

        return view('livewire.documents.document-index', [
            'documents' => $documents,
        ]);
    }
}
