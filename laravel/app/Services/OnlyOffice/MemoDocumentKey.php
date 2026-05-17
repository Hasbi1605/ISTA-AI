<?php

namespace App\Services\OnlyOffice;

use App\Models\Memo;
use App\Models\MemoVersion;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class MemoDocumentKey
{
    public function forEditor(Memo $memo, ?MemoVersion $version = null): string
    {
        $cacheKey = $this->editorCacheKey($memo, $version);

        // Cache the key computed at editor-open time so it stays stable for
        // the duration of the editor session (up to 24 hours). This prevents
        // the key from changing after a save callback updates updated_at,
        // which would cause subsequent callbacks to be rejected as stale.
        return Cache::remember($cacheKey, now()->addHours(24), function () use ($memo, $version) {
            return $this->baseKey($memo, $version);
        });
    }

    public function invalidateEditorKey(Memo $memo, ?MemoVersion $version = null): void
    {
        Cache::forget($this->editorCacheKey($memo, $version));
    }

    public function forConversion(Memo $memo, ?MemoVersion $version = null): string
    {
        return $this->baseKey($memo, $version).'-pdf';
    }

    /**
     * Generate a short-lived, memo-bound token for the signed file URL.
     * The token becomes single-use after the first validation: it transitions
     * into a 60-second retry window so OnlyOffice can retry a failed initial
     * load, but cannot be replayed indefinitely as a bearer URL.
     */
    public function generateFileToken(Memo $memo, ?int $versionId, int $ttlMinutes): string
    {
        $token = Str::random(40);
        Cache::put(
            'oo_file_token:'.$token,
            ['memo_id' => $memo->id, 'version_id' => $versionId, 'used' => false],
            now()->addMinutes($ttlMinutes + 5)
        );

        return $token;
    }

    /**
     * Validate an oo_token from a signed file request.
     *
     * On first use: transitions the token to a 60-second retry window so
     * OnlyOffice can retry the initial file fetch, but the URL is no longer
     * replayable after that window expires.
     *
     * Returns true only if the token is valid, belongs to the given memo,
     * and has not yet expired.
     */
    public function validateFileToken(string $token, Memo $memo): bool
    {
        $cacheKey = 'oo_file_token:'.$token;
        $data = Cache::get($cacheKey);

        if ($data === null) {
            return false;
        }

        if ((int) ($data['memo_id'] ?? 0) !== (int) $memo->id) {
            return false;
        }

        // First use: shrink TTL to a 60-second retry window so the URL cannot
        // be replayed as a long-lived bearer token by anyone who captured it.
        if (! ($data['used'] ?? false)) {
            Cache::put($cacheKey, array_merge($data, ['used' => true]), now()->addSeconds(60));
        }

        // Token still in cache (either not yet used, or within retry window).
        return true;
    }

    protected function baseKey(Memo $memo, ?MemoVersion $version = null): string
    {
        $timestamp = $version?->updated_at?->timestamp
            ?? $memo->updated_at?->timestamp
            ?? now()->timestamp;
        $path = $version?->file_path ?: ($memo->file_path ?: '');
        $fileHash = $this->fileHash($path);
        $pathHash = substr(sha1($path.'|'.$fileHash), 0, 12);
        $scope = $version ? 'v'.$version->id : 'current';

        return 'memo-'.$memo->id.'-'.$scope.'-'.$timestamp.'-'.$pathHash;
    }

    protected function fileHash(string $path): string
    {
        if ($path === '' || ! Storage::disk('local')->exists($path)) {
            return '';
        }

        $absolutePath = Storage::disk('local')->path($path);

        return is_file($absolutePath) ? (hash_file('sha1', $absolutePath) ?: '') : '';
    }

    protected function editorCacheKey(Memo $memo, ?MemoVersion $version = null): string
    {
        return 'onlyoffice_doc_key:'.$memo->id.':'.($version?->id ?? 'base');
    }
}
