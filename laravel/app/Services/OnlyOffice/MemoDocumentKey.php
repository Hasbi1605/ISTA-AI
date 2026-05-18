<?php

namespace App\Services\OnlyOffice;

use App\Models\Memo;
use App\Models\MemoVersion;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use InvalidArgumentException;

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
            return $this->baseKey($memo, $version, $this->editorSessionSeed($memo, $version));
        });
    }

    public function invalidateEditorKey(Memo $memo, ?MemoVersion $version = null): void
    {
        Cache::put($this->editorSessionSeedCacheKey($memo, $version), Str::random(12), now()->addHours(25));
        Cache::forget($this->editorCacheKey($memo, $version));
    }

    public function forConversion(Memo $memo, ?MemoVersion $version = null): string
    {
        return $this->baseKey($memo, $version).'-pdf';
    }

    public function signedFileUrl(Memo $memo, ?int $versionId, int $ttlMinutes): string
    {
        $ooToken = $this->generateFileToken($memo, $versionId, $ttlMinutes);

        $parameters = array_filter([
            'memo' => $memo,
            'version_id' => $versionId,
            'oo_token' => $ooToken,
        ], fn ($value) => filled($value));

        return $this->temporarySignedInternalRoute(
            'memos.file.signed',
            now()->addMinutes($ttlMinutes),
            $parameters,
        );
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
            [
                'memo_id' => $memo->id,
                'version_id' => $versionId,
                'user_id' => $memo->user_id,
                'purpose' => 'onlyoffice_file',
                'used' => false,
            ],
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
     * Returns true only if the token is valid, belongs to the given memo and
     * version, and has not yet expired.
     */
    public function validateFileToken(string $token, Memo $memo, ?int $versionId): bool
    {
        $cacheKey = 'oo_file_token:'.$token;
        $data = Cache::get($cacheKey);

        if ($data === null) {
            return false;
        }

        if ((int) ($data['memo_id'] ?? 0) !== (int) $memo->id) {
            return false;
        }

        if (isset($data['user_id']) && (int) $data['user_id'] !== (int) $memo->user_id) {
            return false;
        }

        if (isset($data['purpose']) && $data['purpose'] !== 'onlyoffice_file') {
            return false;
        }

        if ($this->normalizeVersionId($data['version_id'] ?? null) !== $versionId) {
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

    private function normalizeVersionId(mixed $versionId): ?int
    {
        if ($versionId === null || $versionId === '') {
            return null;
        }

        return (int) $versionId;
    }

    /**
     * Generate an absolute signed URL for the Docker/internal Laravel origin.
     *
     * Laravel's relative signatures allow host rewriting, which is useful for
     * proxies but too permissive for bearer file URLs. OnlyOffice should fetch
     * these files through ONLYOFFICE_LARAVEL_INTERNAL_URL, so we sign that exact
     * origin and let the controller reject public-host anonymous replays.
     *
     * @param  array<string, mixed>  $parameters
     */
    protected function temporarySignedInternalRoute(string $routeName, DateTimeInterface $expiration, array $parameters): string
    {
        if (array_key_exists('signature', $parameters) || array_key_exists('expires', $parameters)) {
            throw new InvalidArgumentException('Signature and expires are reserved signed route parameters.');
        }

        $parameters['expires'] = $expiration->getTimestamp();
        ksort($parameters);

        $unsignedUrl = $this->onlyOfficeLaravelInternalUrl().URL::route($routeName, $parameters, false);
        $signature = hash_hmac('sha256', $unsignedUrl, (string) config('app.key'));

        return $unsignedUrl.(str_contains($unsignedUrl, '?') ? '&' : '?').'signature='.$signature;
    }

    protected function onlyOfficeLaravelInternalUrl(): string
    {
        return rtrim((string) config('services.onlyoffice.laravel_internal_url', config('app.url')), '/');
    }

    protected function baseKey(Memo $memo, ?MemoVersion $version = null, string $sessionSeed = ''): string
    {
        $timestamp = $version?->updated_at?->timestamp
            ?? $memo->updated_at?->timestamp
            ?? now()->timestamp;
        $path = $version?->file_path ?: ($memo->file_path ?: '');
        $fileHash = $this->fileHash($path);
        $pathHash = substr(sha1($path.'|'.$fileHash.'|'.$sessionSeed), 0, 12);
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

    protected function editorSessionSeed(Memo $memo, ?MemoVersion $version = null): string
    {
        return (string) Cache::get($this->editorSessionSeedCacheKey($memo, $version), '');
    }

    protected function editorSessionSeedCacheKey(Memo $memo, ?MemoVersion $version = null): string
    {
        return 'onlyoffice_doc_key_seed:'.$memo->id.':'.($version?->id ?? 'base');
    }
}
