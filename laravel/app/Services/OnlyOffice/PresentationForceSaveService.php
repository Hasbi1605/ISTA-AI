<?php

namespace App\Services\OnlyOffice;

use App\Models\Presentation;
use App\Models\PresentationVersion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Force-save editor OnlyOffice Slides untuk presentasi (#226), setara
 * MemoForceSaveService. Memerintahkan Document Server menyimpan editan terbaru
 * lalu menunggu callback status 6 menulis file PPTX baru.
 */
class PresentationForceSaveService
{
    private const CACHE_PREFIX = 'oo_presentation_forcesave:';

    /**
     * @return array{status: string, key: string, userdata: string|null}
     */
    public function forceSave(Presentation $presentation, ?PresentationVersion $version = null): array
    {
        $documentKey = app(PresentationDocumentKey::class)->forEditor($presentation, $version);
        $userdata = $this->newUserdata($presentation, $version);
        $cacheKey = $this->cacheKey($userdata);
        $waitSeconds = $this->waitSeconds();

        Cache::put($cacheKey, [
            'status' => 'pending',
            'presentation_id' => $presentation->id,
            'version_id' => $version?->id,
        ], now()->addSeconds($waitSeconds + 60));

        $payload = [
            'c' => 'forcesave',
            'key' => $documentKey,
            'userdata' => $userdata,
            'exp' => time() + 300,
        ];

        $response = null;
        $lastException = null;

        for ($attempt = 1; $attempt <= $this->commandAttempts(); $attempt++) {
            try {
                $response = Http::acceptJson()
                    ->asJson()
                    ->timeout($this->commandTimeout())
                    ->post($this->commandUrl($documentKey), [
                        'token' => app(JwtSigner::class)->sign($payload),
                    ]);

                if ($response->successful()) {
                    break;
                }
            } catch (Throwable $e) {
                $lastException = $e;
            }

            if ($attempt < $this->commandAttempts()) {
                usleep($this->commandRetryMicroseconds());
            }
        }

        if (! $response?->successful()) {
            Cache::forget($cacheKey);
            logger()->warning('OnlyOffice presentation force-save command failed', [
                'presentation_id' => $presentation->id,
                'version_id' => $version?->id,
                'status' => $response?->status(),
                'error' => $lastException?->getMessage(),
            ]);

            throw new ForceSaveException(
                'OnlyOffice belum bisa menyimpan perubahan editor.',
                Response::HTTP_BAD_GATEWAY,
            );
        }

        $result = $response->json() ?: [];
        $error = (int) ($result['error'] ?? 0);

        if ($error === 4) {
            Cache::forget($cacheKey);

            return [
                'status' => 'no_changes',
                'key' => $documentKey,
                'userdata' => null,
            ];
        }

        if ($error !== 0) {
            Cache::forget($cacheKey);

            throw new ForceSaveException(
                'OnlyOffice belum siap menyimpan dokumen. Kode error: '.$error,
                Response::HTTP_CONFLICT,
            );
        }

        return [
            'status' => $this->waitUntilSaved($cacheKey, $waitSeconds),
            'key' => $documentKey,
            'userdata' => $userdata,
        ];
    }

    public function markSucceeded(string $userdata, Presentation $presentation, ?PresentationVersion $version = null): void
    {
        if (! $this->isManagedUserdata($userdata)) {
            return;
        }

        Cache::put($this->cacheKey($userdata), [
            'status' => 'saved',
            'presentation_id' => $presentation->id,
            'version_id' => $version?->id,
        ], now()->addMinutes(5));
    }

    public function markFailed(string $userdata, Presentation $presentation, ?PresentationVersion $version = null): void
    {
        if (! $this->isManagedUserdata($userdata)) {
            return;
        }

        Cache::put($this->cacheKey($userdata), [
            'status' => 'failed',
            'presentation_id' => $presentation->id,
            'version_id' => $version?->id,
        ], now()->addMinutes(5));
    }

    protected function waitUntilSaved(string $cacheKey, int $waitSeconds): string
    {
        $deadline = microtime(true) + $waitSeconds;

        do {
            $state = Cache::get($cacheKey);
            $status = is_array($state) ? (string) ($state['status'] ?? '') : '';

            if ($status === 'saved') {
                Cache::forget($cacheKey);

                return 'saved';
            }

            if ($status === 'failed') {
                Cache::forget($cacheKey);

                throw new ForceSaveException(
                    'OnlyOffice gagal menyimpan perubahan editor.',
                    Response::HTTP_CONFLICT,
                );
            }

            usleep($this->pollMicroseconds());
        } while (microtime(true) < $deadline);

        throw new ForceSaveException(
            'Perubahan editor belum selesai tersimpan. Coba lagi sebentar.',
            Response::HTTP_GATEWAY_TIMEOUT,
        );
    }

    protected function commandUrl(string $documentKey): string
    {
        $internalUrl = rtrim((string) config('services.onlyoffice.internal_url', 'http://onlyoffice'), '/');

        return $internalUrl.'/command?shardkey='.rawurlencode($documentKey);
    }

    protected function newUserdata(Presentation $presentation, ?PresentationVersion $version = null): string
    {
        return implode(':', [
            'presentation-force-save',
            $presentation->id,
            $version?->id ?? 'current',
            (string) Str::uuid(),
        ]);
    }

    protected function isManagedUserdata(string $userdata): bool
    {
        return str_starts_with($userdata, 'presentation-force-save:');
    }

    protected function cacheKey(string $userdata): string
    {
        return self::CACHE_PREFIX.hash('sha256', $userdata);
    }

    protected function waitSeconds(): int
    {
        return max(1, (int) config('services.onlyoffice.force_save_wait_seconds', 12));
    }

    protected function commandTimeout(): int
    {
        return max(1, (int) config('services.onlyoffice.force_save_command_timeout', 10));
    }

    protected function commandAttempts(): int
    {
        return max(1, (int) config('services.onlyoffice.force_save_command_attempts', 2));
    }

    protected function commandRetryMicroseconds(): int
    {
        return max(50_000, (int) config('services.onlyoffice.force_save_command_retry_microseconds', 300_000));
    }

    protected function pollMicroseconds(): int
    {
        return max(50_000, (int) config('services.onlyoffice.force_save_poll_microseconds', 250_000));
    }
}
