<?php

namespace App\Http\Controllers\Presentations;

use App\Http\Controllers\Controller;
use App\Models\Presentation;
use App\Models\PresentationVersion;
use App\Services\OnlyOffice\JwtSigner;
use App\Services\OnlyOffice\PptxValidator;
use App\Services\OnlyOffice\PresentationDocumentKey;
use App\Services\OnlyOffice\PresentationForceSaveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Callback OnlyOffice Slides untuk presentasi (epic #218, child #226).
 *
 * Mengikuti pola OnlyOfficeCallbackController memo namun di namespace presentasi
 * terpisah: prefix key `presentation-`, secret/key cache berbeda, validasi PPTX
 * (bukan DOCX). Persist editan manual ke versi aktif, force-save markers, dan
 * invalidasi PDF cache agar download memakai versi terbaru.
 */
class PresentationOnlyOfficeCallbackController extends Controller
{
    public function __invoke(Request $request, Presentation $presentation): JsonResponse
    {
        $token = $this->extractToken($request);

        if ($token === null) {
            abort(Response::HTTP_UNAUTHORIZED, 'Token OnlyOffice wajib dikirim.');
        }

        try {
            $payload = app(JwtSigner::class)->verify($token);
        } catch (RuntimeException) {
            abort(Response::HTTP_UNAUTHORIZED, 'Token OnlyOffice tidak valid.');
        }

        $callback = $this->normalizeSignedCallbackPayload($request, $payload);
        $this->validateSignedCallbackPayload($presentation, $callback);
        $version = $this->resolveVersion($request, $presentation, $callback);
        $this->validateFreshDocumentKey($presentation, $version, $callback);
        $status = (int) $callback['status'];

        // ── Status 1: sedang diedit ──────────────────────────────────────────
        if ($status === 1) {
            return response()->json(['error' => 0]);
        }

        // ── Status 4: tidak ada user mengedit ────────────────────────────────
        if ($status === 4) {
            app(PresentationDocumentKey::class)->invalidateEditorKey($presentation, $version);

            return response()->json(['error' => 0]);
        }

        // ── Status 3: error saat menyimpan ───────────────────────────────────
        if ($status === 3) {
            Log::warning('OnlyOffice presentation save error (status 3)', [
                'presentation_id' => $presentation->id,
                'key' => $callback['key'] ?? '',
                'status' => 3,
                'description' => 'Document saving error reported by OnlyOffice. Manual recovery may be required.',
            ]);

            return response()->json(['error' => 0]);
        }

        // ── Status 7: error saat force-save ──────────────────────────────────
        if ($status === 7) {
            Log::error('OnlyOffice presentation force-save error (status 7)', [
                'presentation_id' => $presentation->id,
                'key' => $callback['key'] ?? '',
                'status' => 7,
                'description' => 'Force-save error reported by OnlyOffice. Document may not have been saved.',
            ]);

            app(PresentationForceSaveService::class)->markFailed((string) ($callback['userdata'] ?? ''), $presentation, $version);

            return response()->json(['error' => 0]);
        }

        // ── Status 2 / 6: dokumen siap disimpan ──────────────────────────────
        if (in_array($status, [2, 6], true)) {
            $url = (string) $callback['url'];
            abort_if($url === '', Response::HTTP_BAD_REQUEST, 'URL file OnlyOffice kosong.');
            abort_unless($this->isTrustedOnlyOfficeUrl($url), Response::HTTP_FORBIDDEN, 'URL file OnlyOffice tidak dipercaya.');

            $replayCacheKey = $this->callbackReplayCacheKey($callback);
            if (Cache::has($replayCacheKey)) {
                abort(Response::HTTP_CONFLICT, 'Callback OnlyOffice sudah diproses (anti-replay).');
            }

            $response = Http::timeout(60)->get($url);
            abort_unless($response->successful(), Response::HTTP_BAD_GATEWAY, 'Gagal mengunduh file dari OnlyOffice.');

            $tempPath = $this->writeTemporaryPptx($response->body());

            try {
                try {
                    app(PptxValidator::class)->assertValidPath($tempPath, 'File dari OnlyOffice');
                } catch (RuntimeException $e) {
                    abort(Response::HTTP_BAD_GATEWAY, $e->getMessage());
                }

                $pptxBytes = file_get_contents($tempPath);
                abort_if($pptxBytes === false, Response::HTTP_BAD_GATEWAY, 'Gagal membaca file sementara dari OnlyOffice.');

                $path = $version?->pptx_path
                    ?: ($presentation->pptx_path ?: 'presentations/'.$presentation->user_id.'/'.$presentation->id.'.pptx');

                $lockKey = 'oo_presentation_save_lock:'.$presentation->id.':'.($version?->id ?? 'base');
                $lock = Cache::lock($lockKey, 30);

                $lock->block(10, function () use ($presentation, $version, $path, $pptxBytes, $replayCacheKey, $status, $callback) {
                    if (Cache::has($replayCacheKey)) {
                        abort(Response::HTTP_CONFLICT, 'Callback OnlyOffice sudah diproses (anti-replay).');
                    }

                    abort_unless(
                        Storage::disk('local')->put($path, $pptxBytes),
                        Response::HTTP_INTERNAL_SERVER_ERROR,
                        'Gagal menyimpan file presentasi dari OnlyOffice.'
                    );

                    if ($version) {
                        $version->forceFill([
                            'pptx_path' => $path,
                            'status' => PresentationVersion::STATUS_EDITED,
                        ])->save();
                    }

                    // Sinkronkan pptx_path presentasi ke versi yang aktif agar
                    // download/export memakai editan terbaru, dan invalidasi PDF.
                    if ($version === null
                        || (int) $presentation->current_version_id === (int) $version->id
                        || $presentation->current_version_id === null) {
                        $presentation->forceFill([
                            'pptx_path' => $path,
                            'pdf_path' => null,
                        ])->save();
                    }

                    $this->markCallbackProcessed($replayCacheKey, $callback);

                    if ($status === 6) {
                        app(PresentationForceSaveService::class)->markSucceeded((string) ($callback['userdata'] ?? ''), $presentation, $version);
                    }

                    if ($status === 2) {
                        app(PresentationDocumentKey::class)->invalidateEditorKey($presentation, $version);
                    }
                });
            } finally {
                @unlink($tempPath);
            }
        }

        return response()->json(['error' => 0]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function normalizeSignedCallbackPayload(Request $request, array $payload): array
    {
        $callback = isset($payload['payload']) && is_array($payload['payload'])
            ? $payload['payload']
            : $payload;

        foreach (['status', 'key', 'url'] as $field) {
            if (! $request->has($field)) {
                continue;
            }

            abort_unless(($callback[$field] ?? null) === $request->input($field), Response::HTTP_FORBIDDEN);
        }

        return $callback;
    }

    /**
     * @param  array<string, mixed>  $callback
     */
    protected function validateSignedCallbackPayload(Presentation $presentation, array $callback): void
    {
        abort_unless(isset($callback['status']) && is_numeric($callback['status']), Response::HTTP_FORBIDDEN);

        $key = (string) ($callback['key'] ?? '');
        abort_if($key === '', Response::HTTP_BAD_REQUEST, 'Key OnlyOffice wajib dikirim.');
        abort_unless(str_starts_with($key, 'presentation-'.$presentation->id.'-'), Response::HTTP_FORBIDDEN);

        if (in_array((int) $callback['status'], [2, 6], true)) {
            $url = (string) ($callback['url'] ?? '');
            abort_if($url === '', Response::HTTP_BAD_REQUEST, 'URL file OnlyOffice kosong.');
        }
    }

    /**
     * @param  array<string, mixed>  $callback
     */
    protected function callbackReplayCacheKey(array $callback): string
    {
        $jti = isset($callback['jti']) && is_string($callback['jti']) && $callback['jti'] !== ''
            ? $callback['jti']
            : null;

        if ($jti !== null) {
            return 'oo_presentation_jti:'.hash('sha256', $jti);
        }

        $key = (string) ($callback['key'] ?? '');
        $status = (int) ($callback['status'] ?? 0);
        $url = (string) ($callback['url'] ?? '');

        return 'oo_presentation_cb:'.hash('sha256', $key.':'.$status.':'.$url);
    }

    /**
     * @param  array<string, mixed>  $callback
     */
    protected function markCallbackProcessed(string $cacheKey, array $callback): void
    {
        $exp = isset($callback['exp']) && is_numeric($callback['exp'])
            ? (int) $callback['exp']
            : (time() + 300);

        $ttlSeconds = max(30, min(300, $exp - time()));
        Cache::put($cacheKey, true, $ttlSeconds);
    }

    /**
     * @param  array<string, mixed>  $callback
     */
    protected function resolveVersion(Request $request, Presentation $presentation, array $callback): ?PresentationVersion
    {
        $versionId = $request->query('version_id');
        $key = (string) ($callback['key'] ?? '');

        if ($versionId === null || $versionId === '') {
            $presentationId = preg_quote((string) $presentation->id, '/');

            if (! preg_match('/^presentation-'.$presentationId.'-v([1-9][0-9]*)-/', $key, $matches)) {
                return null;
            }

            $versionId = $matches[1];
        }

        abort_unless(is_numeric($versionId), Response::HTTP_FORBIDDEN);

        $version = PresentationVersion::where('presentation_id', $presentation->id)
            ->whereKey((int) $versionId)
            ->first();

        abort_unless($version, Response::HTTP_FORBIDDEN);

        abort_unless(str_starts_with($key, 'presentation-'.$presentation->id.'-v'.$version->id.'-'), Response::HTTP_FORBIDDEN);

        return $version;
    }

    /**
     * @param  array<string, mixed>  $callback
     */
    protected function validateFreshDocumentKey(Presentation $presentation, ?PresentationVersion $version, array $callback): void
    {
        $presentation->refresh();
        $version?->refresh();

        $key = (string) ($callback['key'] ?? '');
        $expectedKey = app(PresentationDocumentKey::class)->forEditor($presentation, $version);

        abort_unless(hash_equals($expectedKey, $key), Response::HTTP_CONFLICT, 'Sesi dokumen OnlyOffice sudah kedaluwarsa.');
    }

    protected function writeTemporaryPptx(string $body): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'oo-presentation-');
        abort_if($tempPath === false, Response::HTTP_INTERNAL_SERVER_ERROR, 'Gagal membuat file sementara OnlyOffice.');

        if (file_put_contents($tempPath, $body) === false) {
            @unlink($tempPath);
            abort(Response::HTTP_INTERNAL_SERVER_ERROR, 'Gagal menulis file sementara OnlyOffice.');
        }

        return $tempPath;
    }

    protected function isTrustedOnlyOfficeUrl(string $url): bool
    {
        $candidate = parse_url($url);

        if (! is_array($candidate)) {
            return false;
        }

        if (! in_array($candidate['scheme'] ?? '', ['http', 'https'], true)) {
            return false;
        }

        $path = $candidate['path'] ?? '';
        if (str_contains($path, '..') || str_contains($path, '//')) {
            return false;
        }

        foreach ($this->trustedOnlyOfficeUrls() as $trustedUrl) {
            $trusted = parse_url($trustedUrl);

            if (! is_array($trusted)) {
                continue;
            }

            $candidateScheme = strtolower((string) ($candidate['scheme'] ?? ''));
            $trustedScheme = strtolower((string) ($trusted['scheme'] ?? ''));

            if ($candidateScheme !== $trustedScheme) {
                continue;
            }

            if (($candidate['host'] ?? null) !== ($trusted['host'] ?? null)) {
                continue;
            }

            if ($this->urlPort($candidate) !== $this->urlPort($trusted)) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    protected function urlPort(array $parts): ?int
    {
        if (isset($parts['port'])) {
            return (int) $parts['port'];
        }

        return match (strtolower((string) ($parts['scheme'] ?? ''))) {
            'http' => 80,
            'https' => 443,
            default => null,
        };
    }

    /**
     * @return array<int, string>
     */
    protected function trustedOnlyOfficeUrls(): array
    {
        return array_values(array_filter(array_unique([
            (string) config('services.onlyoffice.internal_url'),
            (string) config('services.onlyoffice.public_url'),
        ])));
    }

    protected function extractToken(Request $request): ?string
    {
        $authorization = (string) $request->header('Authorization', '');

        if (str_starts_with($authorization, 'Bearer ')) {
            return substr($authorization, 7);
        }

        $token = $request->input('token');

        return is_string($token) && $token !== '' ? $token : null;
    }
}
