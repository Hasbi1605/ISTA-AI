<?php

namespace App\Services\Presentations;

use App\Models\Presentation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PresentationLifecycleService
{
    /**
     * Hapus presentasi beserta artefak PPTX/PDF di private disk.
     */
    public function delete(Presentation $presentation): void
    {
        $versionPaths = $presentation->versions()->pluck('pptx_path')->all();

        $paths = array_values(array_unique(array_filter([
            $presentation->pptx_path,
            $presentation->pdf_path,
            ...$versionPaths,
        ], fn (?string $path) => filled($path))));

        DB::transaction(function () use ($presentation) {
            // FK presentation_versions.presentation_id cascadeOnDelete menghapus
            // baris versi; file fisik dibersihkan di bawah.
            $presentation->forceDelete();
        });

        foreach ($paths as $path) {
            $this->deleteFile($path);
        }
    }

    protected function deleteFile(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        try {
            if (Storage::disk('local')->exists($path)) {
                Storage::disk('local')->delete($path);
            }
        } catch (Throwable $e) {
            logger()->warning('PresentationLifecycleService: failed to delete file', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
