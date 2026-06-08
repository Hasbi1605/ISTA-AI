<?php

namespace App\Http\Controllers;

use App\Models\Memo;
use App\Models\MemoVersion;
use App\Services\OnlyOffice\DocxTextExtractor;
use App\Services\OnlyOffice\DocxValidator;
use App\Services\OnlyOffice\JwtSigner;
use App\Services\OnlyOffice\MemoDocumentKey;
use App\Services\OnlyOffice\MemoForceSaveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class OnlyOfficeCallbackController extends Controller
{
    public function __invoke(Request $request, Memo $memo): JsonResponse
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
        $this->validateSignedCallbackPayload($memo, $callback);
        $version = $this->resolveMemoVersion($request, $memo, $callback);
        $this->validateFreshDocumentKey($memo, $version, $callback);
        $status = (int) $callback['status'];

        // ── Status 1: document is being edited ──────────────────────────────
        // OnlyOffice sends this while the editing session is active. No file
        // is ready yet — acknowledge receipt silently.
        if ($status === 1) {
            return response()->json(['error' => 0]);
        }

        // ── Status 4: no users currently editing ────────────────────────────
        // Close/no-change ends this editor session. Rotate the next editor key
        // so a refreshed page does not reopen a stale Document Server cache.
        if ($status === 4) {
            app(MemoDocumentKey::class)->invalidateEditorKey($memo, $version);

            return response()->json(['error' => 0]);
        }

        // ── Status 3: error during saving ───────────────────────────────────
        // OnlyOffice encountered an error trying to save the document. Log a
        // structured warning so the incident is traceable, but still return
        // {"error":0} to acknowledge receipt per the OnlyOffice callback
        // contract.
        if ($status === 3) {
            Log::warning('OnlyOffice save error (status 3)', [
                'memo_id' => $memo->id,
                'key' => $callback['key'] ?? '',
                'status' => 3,
                'description' => 'Document saving error reported by OnlyOffice. Manual recovery may be required.',
            ]);

            return response()->json(['error' => 0]);
        }

        // ── Status 7: error during force-save ───────────────────────────────
        // OnlyOffice failed to force-save (e.g., conflict or server error).
        // Log a structured error and acknowledge receipt.
        if ($status === 7) {
            Log::error('OnlyOffice force-save error (status 7)', [
                'memo_id' => $memo->id,
                'key' => $callback['key'] ?? '',
                'status' => 7,
                'description' => 'Force-save error reported by OnlyOffice. Document may not have been saved.',
            ]);

            app(MemoForceSaveService::class)->markFailed((string) ($callback['userdata'] ?? ''), $memo, $version);

            return response()->json(['error' => 0]);
        }

        // ── Status 2 / 6: document ready for saving ─────────────────────────
        if (in_array($status, [2, 6], true)) {
            $url = (string) $callback['url'];
            abort_if($url === '', Response::HTTP_BAD_REQUEST, 'URL file OnlyOffice kosong.');
            abort_unless($this->isTrustedOnlyOfficeUrl($url), Response::HTTP_FORBIDDEN, 'URL file OnlyOffice tidak dipercaya.');

            // Fast-path replay guard: reject immediately if an identical callback
            // has already been successfully processed (cache key present).
            // The mark is only set AFTER a successful save (inside the lock
            // below), so retries triggered by network errors or transient
            // failures before the write are never blocked.
            $replayCacheKey = $this->callbackReplayCacheKey($callback);
            if (Cache::has($replayCacheKey)) {
                abort(Response::HTTP_CONFLICT, 'Callback OnlyOffice sudah diproses (anti-replay).');
            }

            $response = Http::timeout(60)->get($url);
            abort_unless($response->successful(), Response::HTTP_BAD_GATEWAY, 'Gagal mengunduh file dari OnlyOffice.');

            $tempPath = $this->writeTemporaryDocx($response->body());

            try {
                try {
                    app(DocxValidator::class)->assertValidPath($tempPath, 'File dari OnlyOffice');
                } catch (RuntimeException $e) {
                    abort(Response::HTTP_BAD_GATEWAY, $e->getMessage());
                }

                $freshText = app(DocxTextExtractor::class)->extract($tempPath);
                $docxBytes = file_get_contents($tempPath);
                abort_if($docxBytes === false, Response::HTTP_BAD_GATEWAY, 'Gagal membaca file sementara dari OnlyOffice.');

                $path = $version?->file_path ?: ($memo->file_path ?: 'memos/'.$memo->user_id.'/'.$memo->id.'.docx');

                // Acquire a per-memo lock to prevent concurrent callbacks from
                // overwriting the same file in an uncontrolled way.
                $lockKey = 'oo_save_lock:'.$memo->id.':'.($version?->id ?? 'base');
                $lock = Cache::lock($lockKey, 30);

                $lock->block(10, function () use ($memo, $version, $path, $docxBytes, $freshText, $callback, $replayCacheKey, $status) {
                    // Re-check replay inside the lock to guard against a concurrent
                    // thread that passed the fast-path check above.
                    if (Cache::has($replayCacheKey)) {
                        abort(Response::HTTP_CONFLICT, 'Callback OnlyOffice sudah diproses (anti-replay).');
                    }

                    abort_unless(
                        Storage::disk('local')->put($path, $docxBytes),
                        Response::HTTP_INTERNAL_SERVER_ERROR,
                        'Gagal menyimpan file memo dari OnlyOffice.'
                    );

                    if ($version) {
                        $newSearchableText = $freshText !== ''
                            ? $freshText
                            : ($version->searchable_text ?: $memo->searchable_text ?: $memo->title);

                        $version->forceFill([
                            'file_path' => $path,
                            'status' => Memo::STATUS_EDITED,
                            'searchable_text' => $newSearchableText,
                        ])->save();

                        if ((int) $memo->current_version_id === (int) $version->id || $memo->current_version_id === null) {
                            $memo->forceFill([
                                'file_path' => $path,
                                'status' => Memo::STATUS_EDITED,
                                'searchable_text' => $newSearchableText,
                            ])->save();
                        }

                    } else {
                        $newSearchableText = $freshText !== ''
                            ? $freshText
                            : ($memo->searchable_text ?: $memo->title);

                        $memo->forceFill([
                            'file_path' => $path,
                            'status' => Memo::STATUS_EDITED,
                            'searchable_text' => $newSearchableText,
                        ])->save();

                        $currentVersion = $memo->currentVersion()->first();

                        if ($currentVersion) {
                            $currentVersion->forceFill([
                                'file_path' => $path,
                                'status' => Memo::STATUS_EDITED,
                                'searchable_text' => $newSearchableText,
                            ])->save();
                        }
                    }

                    // Mark the callback as successfully processed only after the
                    // file has been written and all DB updates committed.
                    // This ensures that retries triggered by transient failures
                    // before this point (network errors, DOCX validation, lock
                    // contention) are never incorrectly blocked as replays.
                    $this->markCallbackProcessed($replayCacheKey, $callback);

                    if ($status === 6) {
                        app(MemoForceSaveService::class)->markSucceeded((string) ($callback['userdata'] ?? ''), $memo, $version);
                    }

                    if ($status === 2) {
                        app(MemoDocumentKey::class)->invalidateEditorKey($memo, $version);
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
    protected function validateSignedCallbackPayload(Memo $memo, array $callback): void
    {
        abort_unless(isset($callback['status']) && is_numeric($callback['status']), Response::HTTP_FORBIDDEN);

        $key = (string) ($callback['key'] ?? '');
        abort_if($key === '', Response::HTTP_BAD_REQUEST, 'Key OnlyOffice wajib dikirim.');
        abort_unless(str_starts_with($key, 'memo-'.$memo->id.'-'), Response::HTTP_FORBIDDEN);

        if (in_array((int) $callback['status'], [2, 6], true)) {
            $url = (string) ($callback['url'] ?? '');
            abort_if($url === '', Response::HTTP_BAD_REQUEST, 'URL file OnlyOffice kosong.');
        }
    }

    /**
     * Build the cache key used for replay detection on a successfully-saved
     * status-2/6 callback.
     *
     * Uses `jti` when present (globally unique per OnlyOffice JWT), otherwise
     * falls back to a fingerprint of `key` + `status` + `url`. Including the
     * URL means two consecutive saves in the same editor session (same key +
     * status) each produce distinct fingerprints and are therefore not blocked
     * as replays — OnlyOffice generates a new file URL for every save.
     *
     * @param  array<string, mixed>  $callback
     */
    protected function callbackReplayCacheKey(array $callback): string
    {
        $jti = isset($callback['jti']) && is_string($callback['jti']) && $callback['jti'] !== ''
            ? $callback['jti']
            : null;

        if ($jti !== null) {
            return 'oo_jti:'.hash('sha256', $jti);
        }

        $key = (string) ($callback['key'] ?? '');
        $status = (int) ($callback['status'] ?? 0);
        $url = (string) ($callback['url'] ?? '');

        return 'oo_cb:'.hash('sha256', $key.':'.$status.':'.$url);
    }

    /**
     * Persist the replay-guard marker for a cache key after a callback has
     * been successfully processed.
     *
     * The TTL is capped at 5 minutes so very long-lived tokens are still
     * rejected within a bounded replay window, while allowing the natural
     * expiry to clean up the cache automatically.
     *
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
    protected function resolveMemoVersion(Request $request, Memo $memo, array $callback): ?MemoVersion
    {
        $versionId = $request->query('version_id');
        $key = (string) ($callback['key'] ?? '');

        if ($versionId === null || $versionId === '') {
            $memoId = preg_quote((string) $memo->id, '/');

            if (! preg_match('/^memo-'.$memoId.'-v([1-9][0-9]*)-/', $key, $matches)) {
                return null;
            }

            $versionId = $matches[1];
        }

        abort_unless(is_numeric($versionId), Response::HTTP_FORBIDDEN);

        $version = MemoVersion::where('memo_id', $memo->id)
            ->whereKey((int) $versionId)
            ->first();

        abort_unless($version, Response::HTTP_FORBIDDEN);

        abort_unless(str_starts_with($key, 'memo-'.$memo->id.'-v'.$version->id.'-'), Response::HTTP_FORBIDDEN);

        return $version;
    }

    /**
     * @param  array<string, mixed>  $callback
     */
    protected function validateFreshDocumentKey(Memo $memo, ?MemoVersion $version, array $callback): void
    {
        $memo->refresh();
        $version?->refresh();

        $key = (string) ($callback['key'] ?? '');
        $expectedKey = app(MemoDocumentKey::class)->forEditor($memo, $version);

        abort_unless(hash_equals($expectedKey, $key), Response::HTTP_CONFLICT, 'Sesi dokumen OnlyOffice sudah kedaluwarsa.');
    }

    protected function writeTemporaryDocx(string $body): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'oo-memo-');
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

        // Reject URLs with path traversal patterns before host matching.
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
