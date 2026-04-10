<?php

namespace App\Livewire\Documents;

use App\Jobs\ProcessDocument;
use App\Models\Document;
use App\Services\AIService;
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

    public function saveDocument()
    {
        $this->validate();

        // 1. Check Document Limit (Max 10)
        $count = Document::where('user_id', Auth::id())->count();
        if ($count >= 10) {
            session()->flash('error', 'Limit kuota dokumen tercapai (Maksimal 10 dokumen).');
            return;
        }

        $this->isUploading = true;

        try {
            // 2. Store file securely
            $originalName = $this->file->getClientOriginalName();
            $detectedMimeType = (string) $this->file->getMimeType();

            if (!in_array($detectedMimeType, self::ALLOWED_ATTACHMENT_MIME_TYPES, true)) {
                throw ValidationException::withMessages([
                    'file' => 'Tipe MIME file tidak valid. Gunakan PDF, DOCX, atau XLSX.',
                ]);
            }

            $duplicateExists = Document::where('user_id', Auth::id())
                ->where('original_name', $originalName)
                ->exists();

            if ($duplicateExists) {
                $this->addError('file', 'File dengan nama yang sama sudah pernah diunggah.');
                return;
            }

            $filename = time() . '_' . $this->file->hashName();
            $filePath = $this->file->storeAs('documents/' . Auth::id(), $filename);

            // 3. Create Database Record
            $document = Document::create([
                'user_id' => Auth::id(),
                'filename' => $filename,
                'original_name' => $originalName,
                'file_path' => $filePath,
                'mime_type' => $detectedMimeType,
                'file_size_bytes' => $this->file->getSize(),
                'status' => 'pending',
            ]);

            // 4. Dispatch Background Job
            ProcessDocument::dispatch($document);

            $this->reset('file');
            session()->flash('message', 'Dokumen berhasil diunggah dan sedang diproses.');
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            session()->flash('error', 'Gagal mengunggah dokumen: ' . $e->getMessage());
        } finally {
            $this->isUploading = false;
        }
    }

    public function delete($id)
    {
        $document = Document::where('user_id', Auth::id())->findOrFail($id);

        try {
            // 1. Notify Python Microservice to delete vectors
            $pythonUrl = config('services.ai_service.url', 'http://127.0.0.1:8001') . '/api/documents/' . urlencode($document->original_name);
            $token = config('services.ai_service.token');

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$token}",
            ])->delete($pythonUrl);

            if (!$response->successful()) {
                logger()->warning("Vector deletion failed for {$document->original_name}, proceeding anyway: " . $response->body());
            }

            // 2. Delete file from storage
            Storage::delete($document->file_path);

            // 3. Delete database record (Soft Delete)
            $document->delete();

            session()->flash('message', 'Dokumen berhasil dihapus.');
        } catch (\Exception $e) {
            session()->flash('error', 'Gagal menghapus dokumen: ' . $e->getMessage());
        }
    }

    public function summarize($id, AIService $aiService)
    {
        $document = Document::where('user_id', Auth::id())->findOrFail($id);

        if ($document->status !== 'ready') {
            session()->flash('error', 'Dokumen belum selesai diproses. Tunggu hingga status menjadi "ready".');
            return;
        }

        $this->summarizingDocumentId = $id;

        try {
            $result = $aiService->summarizeDocument($document->original_name, (string) Auth::id());

            if ($result['status'] === 'success') {
                $this->summaryResult = $result['summary'];
                $this->showSummaryModal = true;
            } else {
                session()->flash('error', 'Gagal merangkum dokumen: ' . ($result['message'] ?? 'Unknown error'));
            }
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
