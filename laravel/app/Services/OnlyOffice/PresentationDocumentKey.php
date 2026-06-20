<?php

namespace App\Services\OnlyOffice;

use App\Models\Presentation;
use App\Models\PresentationVersion;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Signed internal URL + document key untuk file PPTX presentasi (epic #218).
 *
 * Child #224 menambahkan signed URL + conversion key untuk PDF headless.
 * Child #226 menambahkan dukungan editor OnlyOffice Slides penuh: editor key
 * yang stabil per sesi (anti stale), single-use file token (oo_token), dan
 * versioning. Namespace tetap terpisah dari memo (prefix `presentation-`,
 * cache key/secret berbeda) agar key/token/callback tidak bertabrakan.
 */
class PresentationDocumentKey
{
    public function forEditor(Presentation $presentation, ?PresentationVersion $version = null): string
    {
        $cacheKey = $this->editorCacheKey($presentation, $version);

        // Cache key yang dihitung saat editor dibuka agar stabil selama sesi
        // (hingga 24 jam). Mencegah key berubah setelah callback save memperbarui
        // updated_at, yang akan membuat callback berikutnya ditolak sebagai stale.
        return Cache::remember($cacheKey, now()->addHours(24), function () use ($presentation, $version) {
            return $this->baseKey($presentation, $version, $this->editorSessionSeed($presentation, $version));
        });
    }

    public function invalidateEditorKey(Presentation $presentation, ?PresentationVersion $version = null): void
    {
        Cache::put($this->editorSessionSeedCacheKey($presentation, $version), Str::random(12), now()->addHours(25));
        Cache::forget($this->editorCacheKey($presentation, $version));
    }

    public function forConversion(Presentation $presentation, ?PresentationVersion $version = null): string
    {
        return $this->baseKey($presentation, $version).'-pdf';
    }

    public function signedFileUrl(Presentation $presentation, ?int $versionId, int $ttlMinutes): string
    {
        $ooToken = $this->generateFileToken($presentation, $versionId, $ttlMinutes);

        $parameters = array_filter([
            'presentation' => $presentation->id,
            'version_id' => $versionId,
            'oo_token' => $ooToken,
        ], fn ($value) => filled($value));

        return $this->temporarySignedInternalRoute(
            'presentations.file.signed',
            now()->addMinutes($ttlMinutes),
            $parameters,
        );
    }

    /**
     * Token presentasi short-lived untuk signed file URL. Single-use setelah
     * validasi pertama (jendela retry 60 detik) agar tidak bisa di-replay
     * sebagai bearer URL bila URL tertangkap pihak lain.
     */
    public function generateFileToken(Presentation $presentation, ?int $versionId, int $ttlMinutes): string
    {
        $token = Str::random(40);
        Cache::put(
            'oo_presentation_file_token:'.$token,
            [
                'presentation_id' => $presentation->id,
                'version_id' => $versionId,
                'user_id' => $presentation->user_id,
                'purpose' => 'onlyoffice_presentation_file',
                'used' => false,
            ],
            now()->addMinutes($ttlMinutes + 5)
        );

        return $token;
    }

    /**
     * Validasi oo_token dari signed file request presentasi.
     *
     * Penggunaan pertama mempersempit TTL ke jendela retry 60 detik. Mengembalikan
     * true hanya bila token valid, milik presentasi & versi yang sama, dan belum
     * kedaluwarsa.
     */
    public function validateFileToken(string $token, Presentation $presentation, ?int $versionId): bool
    {
        $cacheKey = 'oo_presentation_file_token:'.$token;
        $data = Cache::get($cacheKey);

        if ($data === null) {
            return false;
        }

        if ((int) ($data['presentation_id'] ?? 0) !== (int) $presentation->id) {
            return false;
        }

        if (isset($data['user_id']) && (int) $data['user_id'] !== (int) $presentation->user_id) {
            return false;
        }

        if (isset($data['purpose']) && $data['purpose'] !== 'onlyoffice_presentation_file') {
            return false;
        }

        if ($this->normalizeVersionId($data['version_id'] ?? null) !== $versionId) {
            return false;
        }

        if (! ($data['used'] ?? false)) {
            Cache::put($cacheKey, array_merge($data, ['used' => true]), now()->addSeconds(60));
        }

        return true;
    }

    private function normalizeVersionId(mixed $versionId): ?int
    {
        if ($versionId === null || $versionId === '') {
            return null;
        }

        return (int) $versionId;
    }

    public function hasValidSignedFileSignature(Request $request): bool
    {
        $signature = (string) $request->query('signature', '');
        $expires = $request->query('expires');

        if ($signature === '' || ! is_numeric($expires) || (int) $expires < now()->getTimestamp()) {
            return false;
        }

        $unsignedUrl = $request->fullUrlWithoutQuery('signature');
        $expected = hash_hmac('sha256', $unsignedUrl, $this->signedFileUrlKey());

        return hash_equals($expected, $signature);
    }

    /**
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
        $signature = hash_hmac('sha256', $unsignedUrl, $this->signedFileUrlKey());

        return $unsignedUrl.(str_contains($unsignedUrl, '?') ? '&' : '?').'signature='.$signature;
    }

    protected function signedFileUrlKey(): string
    {
        $secret = trim((string) config('services.onlyoffice.signed_url_secret', ''));

        if ($secret !== '') {
            return $secret;
        }

        return hash('sha256', (string) config('app.key').'|onlyoffice-signed-presentation-url');
    }

    protected function onlyOfficeLaravelInternalUrl(): string
    {
        return rtrim((string) config('services.onlyoffice.laravel_internal_url', config('app.url')), '/');
    }

    protected function baseKey(Presentation $presentation, ?PresentationVersion $version = null, string $sessionSeed = ''): string
    {
        $timestamp = $version?->updated_at?->timestamp
            ?? $presentation->generated_at?->timestamp
            ?? $presentation->updated_at?->timestamp
            ?? now()->timestamp;
        $path = $version?->pptx_path ?: (string) ($presentation->pptx_path ?: '');
        $fileHash = $this->fileHash($path);
        $pathHash = substr(sha1($path.'|'.$fileHash.'|'.$sessionSeed), 0, 12);
        $scope = $version ? 'v'.$version->id : 'current';

        return 'presentation-'.$presentation->id.'-'.$scope.'-'.$timestamp.'-'.$pathHash;
    }

    protected function fileHash(string $path): string
    {
        if ($path === '' || ! Storage::disk('local')->exists($path)) {
            return '';
        }

        $absolutePath = Storage::disk('local')->path($path);

        return is_file($absolutePath) ? (hash_file('sha1', $absolutePath) ?: '') : '';
    }

    protected function editorCacheKey(Presentation $presentation, ?PresentationVersion $version = null): string
    {
        return 'onlyoffice_presentation_doc_key:'.$presentation->id.':'.($version?->id ?? 'base');
    }

    protected function editorSessionSeed(Presentation $presentation, ?PresentationVersion $version = null): string
    {
        return (string) Cache::get($this->editorSessionSeedCacheKey($presentation, $version), '');
    }

    protected function editorSessionSeedCacheKey(Presentation $presentation, ?PresentationVersion $version = null): string
    {
        return 'onlyoffice_presentation_doc_key_seed:'.$presentation->id.':'.($version?->id ?? 'base');
    }
}
