<?php

namespace App\Services\OnlyOffice;

use App\Models\Presentation;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;

/**
 * Signed internal URL + conversion key untuk file PPTX presentasi (epic #218,
 * child #224). OnlyOffice headless converter mengambil PPTX lewat URL ini.
 *
 * Versi ringkas dari MemoDocumentKey: menandatangani origin internal Laravel
 * (HMAC) dengan TTL. Hardening editor (oo_token single-use, versioning)
 * menyusul di child #226.
 */
class PresentationDocumentKey
{
    public function signedFileUrl(Presentation $presentation, int $ttlMinutes): string
    {
        return $this->temporarySignedInternalRoute(
            'presentations.file.signed',
            now()->addMinutes($ttlMinutes),
            ['presentation' => $presentation->id],
        );
    }

    public function forConversion(Presentation $presentation): string
    {
        return $this->baseKey($presentation).'-pdf';
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

    protected function baseKey(Presentation $presentation): string
    {
        $timestamp = $presentation->generated_at?->timestamp
            ?? $presentation->updated_at?->timestamp
            ?? now()->timestamp;
        $path = (string) ($presentation->pptx_path ?: '');
        $fileHash = $this->fileHash($path);
        $pathHash = substr(sha1($path.'|'.$fileHash), 0, 12);

        return 'presentation-'.$presentation->id.'-'.$timestamp.'-'.$pathHash;
    }

    protected function fileHash(string $path): string
    {
        if ($path === '' || ! Storage::disk('local')->exists($path)) {
            return '';
        }

        $absolutePath = Storage::disk('local')->path($path);

        return is_file($absolutePath) ? (hash_file('sha1', $absolutePath) ?: '') : '';
    }
}
