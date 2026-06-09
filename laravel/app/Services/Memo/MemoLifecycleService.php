<?php

namespace App\Services\Memo;

use App\Models\Memo;
use App\Models\MemoVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MemoLifecycleService
{
    /**
     * Delete a memo and clean up all associated DOCX files.
     *
     * MemoVersion rows are cascade-deleted by the DB foreign key when the memo
     * is force-deleted. For soft-delete we force-delete so the cascade fires and
     * no orphan version rows remain.
     */
    public function deleteMemo(Memo $memo): void
    {
        $filePaths = collect([$memo->file_path])
            ->merge($memo->versions->pluck('file_path'))
            ->filter(fn (?string $path) => filled($path))
            ->unique()
            ->values()
            ->all();

        DB::transaction(function () use ($memo) {
            $memo->versions()->forceDelete();
            $memo->forceDelete();
        });

        foreach ($filePaths as $path) {
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
        } catch (\Throwable $e) {
            logger()->warning('MemoLifecycleService: failed to delete file', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
