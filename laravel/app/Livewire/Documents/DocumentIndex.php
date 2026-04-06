<?php

namespace App\Livewire\Documents;

use App\Jobs\ProcessDocument;
use App\Models\Document;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.app')]
class DocumentIndex extends Component
{
    use WithFileUploads;

    public $file;
    public $isUploading = false;

    protected $rules = [
        'file' => 'required|mimes:pdf,docx,xlsx|max:51200', // 50MB
    ];

    public function saveDocument()
    {
        $this->validate();

        // 1. Check Document Limit (Max 10)
        $count = Document::where('user_id', auth()->id())->count();
        if ($count >= 10) {
            session()->flash('error', 'Limit kuota dokumen tercapai (Maksimal 10 dokumen).');
            return;
        }

        $this->isUploading = true;

        try {
            // 2. Store file securely
            $originalName = $this->file->getClientOriginalName();
            $filename = time() . '_' . $this->file->hashName();
            $filePath = $this->file->storeAs('documents/' . auth()->id(), $filename);

            // 3. Create Database Record
            $document = Document::create([
                'user_id' => auth()->id(),
                'filename' => $filename,
                'original_name' => $originalName,
                'file_path' => $filePath,
                'status' => 'pending',
            ]);

            // 4. Dispatch Background Job
            ProcessDocument::dispatch($document);

            $this->reset('file');
            session()->flash('message', 'Dokumen berhasil diunggah dan sedang diproses.');

        } catch (\Exception $e) {
            session()->flash('error', 'Gagal mengunggah dokumen: ' . $e->getMessage());
        } finally {
            $this->isUploading = false;
        }
    }

    public function delete($id)
    {
        $document = Document::where('user_id', auth()->id())->findOrFail($id);

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

    public function render()
    {
        $documents = Document::where('user_id', auth()->id())
            ->latest()
            ->get();

        return view('livewire.documents.document-index', [
            'documents' => $documents,
        ]);
    }
}
