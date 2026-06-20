<?php

namespace App\Jobs;

use App\Models\AIUsageEvent;
use App\Models\Presentation;
use App\Services\Admin\AIUsageEventService;
use App\Services\Presentations\PresentationGenerationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Generate presentasi async (epic #218, child #222).
 *
 * Memakai pola claim-token (seperti ProcessDocument) agar job lama tidak
 * menimpa hasil job baru. Status: pending -> processing -> ready/error.
 */
class GeneratePresentation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public array $backoff = [30, 90];

    public int $timeout = 300;

    public bool $deleteWhenMissingModels = true;

    protected string $claimToken;

    protected string $claimCacheKey;

    public function __construct(public Presentation $presentation)
    {
        $this->claimToken = Str::uuid()->toString();
        $this->claimCacheKey = 'presentation_generate_claim:'.$presentation->id;

        $this->configureQueue();
    }

    public function handle(PresentationGenerationService $service): void
    {
        $fresh = $this->presentation->fresh();
        if ($fresh === null) {
            logger()->info("GeneratePresentation skipped: presentation {$this->presentation->id} deleted before processing.");

            return;
        }
        $this->presentation = $fresh;

        // ── Stale-job guard: atomic claim pending -> processing ───────────────
        $claimedFromPending = Presentation::where('id', $fresh->id)
            ->where('status', Presentation::STATUS_PENDING)
            ->update(['status' => Presentation::STATUS_PROCESSING, 'error_message' => null]);

        if ($claimedFromPending > 0) {
            $ttl = ($this->timeout * $this->tries) + 300;
            Cache::put($this->claimCacheKey, $this->claimToken, $ttl);
        } else {
            $currentToken = Cache::get($this->claimCacheKey);
            if ($currentToken !== null && $currentToken !== $this->claimToken) {
                logger()->info('GeneratePresentation: skipped — claim superseded by newer job', [
                    'presentation_id' => $fresh->id,
                ]);

                return;
            }
        }

        // Validasi source documents masih milik user + ready (fail-closed).
        $sourceIds = is_array($fresh->source_document_ids) ? $fresh->source_document_ids : [];
        if (! Presentation::sourceDocumentsOwnedAndReady((int) $fresh->user_id, $sourceIds)) {
            $this->markErrorIfOwned('Dokumen sumber tidak valid atau belum siap. Pilih ulang dokumen lalu coba lagi.');

            return;
        }

        $usageEvents = app(AIUsageEventService::class);
        $startedAt = microtime(true);
        $requestId = $usageEvents->newRequestId();
        $usageMetadata = [
            'presentation_id' => (int) $fresh->id,
            'visual_template' => (string) $fresh->visual_template,
            'document_count' => count($sourceIds),
            'asset_mode' => 'local_assets_only',
        ];

        $usageEvents->started(
            feature: AIUsageEvent::FEATURE_PRESENTATION_GENERATION,
            userId: (int) $fresh->user_id,
            metadata: $usageMetadata,
            requestId: $requestId,
            subject: $fresh,
        );

        try {
            $result = $service->renderAndStore($fresh);
        } catch (Throwable $e) {
            $usageEvents->failed(
                feature: AIUsageEvent::FEATURE_PRESENTATION_GENERATION,
                userId: (int) $fresh->user_id,
                metadata: [...$usageMetadata, 'reason' => 'render_failed'],
                requestId: $requestId,
                latencyMs: $usageEvents->latencyMsSince($startedAt),
                errorCode: 'render_failed',
                subject: $fresh,
            );
            // Lempar ulang agar retry queue berjalan; failed() menandai error final.
            throw $e;
        }

        // ── Stale-job guard saat sukses: hanya tulis ready bila masih owner. ──
        $currentToken = Cache::get($this->claimCacheKey);
        if ($currentToken !== null && $currentToken !== $this->claimToken) {
            $this->deleteFile($result['path']);
            logger()->info('GeneratePresentation: stale — claim superseded mid-flight; discarding output', [
                'presentation_id' => $fresh->id,
            ]);

            return;
        }

        // Buat versi PPTX baru (#226) lalu jadikan versi aktif. Versioning di-set
        // di dalam transition yang sama agar editor OnlyOffice selalu memakai
        // PPTX hasil generate terbaru sebagai versi aktif.
        $versionNumber = ((int) $fresh->versions()->max('version_number')) + 1;
        $version = \App\Models\PresentationVersion::create([
            'presentation_id' => $fresh->id,
            'version_number' => $versionNumber,
            'label' => 'Versi '.$versionNumber,
            'pptx_path' => $result['path'],
            'status' => Presentation::STATUS_READY,
        ]);

        $saved = Presentation::where('id', $fresh->id)
            ->where('status', Presentation::STATUS_PROCESSING)
            ->update([
                'status' => Presentation::STATUS_READY,
                'pptx_path' => $result['path'],
                'pdf_path' => null, // invalidasi PDF lama saat PPTX di-regenerate (#224)
                'current_version_id' => $version->id,
                'error_message' => null,
                'generated_at' => now(),
            ]);

        if ($saved === 0) {
            // Job lain sudah mengambil alih: buang artefak agar tidak orphan.
            $version->delete();
            $this->deleteFile($result['path']);
            logger()->info('GeneratePresentation: stale — left processing state mid-flight; discarding output', [
                'presentation_id' => $fresh->id,
            ]);

            return;
        }

        // Bersihkan PPTX lama bila berbeda path.
        if ($fresh->pptx_path && $fresh->pptx_path !== $result['path']) {
            $this->deleteFile($fresh->pptx_path);
        }

        $usageEvents->completed(
            feature: AIUsageEvent::FEATURE_PRESENTATION_GENERATION,
            userId: (int) $fresh->user_id,
            metadata: [...$usageMetadata, 'slide_count' => $result['slide_count'], 'outcome' => 'ready'],
            requestId: $requestId,
            latencyMs: $usageEvents->latencyMsSince($startedAt),
            subject: $fresh,
        );
    }

    public function failed(Throwable $exception): void
    {
        $currentToken = Cache::get($this->claimCacheKey);
        if ($currentToken !== null && $currentToken !== $this->claimToken) {
            logger()->info('GeneratePresentation: failed() skipped — claim superseded', [
                'presentation_id' => $this->presentation->id,
            ]);

            return;
        }

        Presentation::query()
            ->whereKey($this->presentation->id)
            ->whereIn('status', [Presentation::STATUS_PENDING, Presentation::STATUS_PROCESSING])
            ->update([
                'status' => Presentation::STATUS_ERROR,
                'error_message' => 'Gagal membuat presentasi. Silakan coba generate ulang.',
            ]);

        logger()->error("GeneratePresentation permanently failed for ID {$this->presentation->id}: ".$exception->getMessage());
    }

    protected function markErrorIfOwned(string $message): void
    {
        $currentToken = Cache::get($this->claimCacheKey);
        if ($currentToken !== null && $currentToken !== $this->claimToken) {
            return;
        }

        Presentation::query()
            ->whereKey($this->presentation->id)
            ->whereIn('status', [Presentation::STATUS_PENDING, Presentation::STATUS_PROCESSING])
            ->update(['status' => Presentation::STATUS_ERROR, 'error_message' => $message]);
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
            logger()->warning('GeneratePresentation: failed to delete file', ['path' => $path, 'error' => $e->getMessage()]);
        }
    }

    protected function configureQueue(): void
    {
        $connection = trim((string) config('presentations.queue.connection', ''));
        if ($connection !== '') {
            $this->onConnection($connection);
        }

        $queue = trim((string) config('presentations.queue.name', 'default'));
        if ($queue !== '') {
            $this->onQueue($queue);
        }
    }
}
