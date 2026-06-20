<?php

namespace App\Services\OnlyOffice;

use App\Models\Presentation;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Konversi PPTX presentasi -> PDF via OnlyOffice headless converter (epic #218,
 * child #224). Mengikuti pola DocumentConverter (memo DOCX->PDF).
 */
class PresentationConverter
{
    public function presentationToPdf(Presentation $presentation): string
    {
        $filePath = $presentation->pptx_path;

        if (! $filePath) {
            throw new RuntimeException('File presentasi belum tersedia.');
        }

        $key = app(PresentationDocumentKey::class)->forConversion($presentation);
        $payload = [
            'async' => false,
            'filetype' => 'pptx',
            'key' => $key,
            'outputtype' => 'pdf',
            'title' => $this->fileName($presentation, 'pptx'),
            'url' => $this->presentationDocumentUrl($presentation),
            'exp' => time() + 300,
        ];

        $response = Http::acceptJson()
            ->asJson()
            ->timeout($this->timeout())
            ->post($this->conversionUrl($key), [
                'token' => app(JwtSigner::class)->sign($payload),
            ]);

        if (! $response->successful()) {
            throw new RuntimeException($response->body() ?: 'Gagal mengonversi presentasi ke PDF.');
        }

        $result = $response->json() ?: [];

        if (($result['error'] ?? null) !== null) {
            throw new RuntimeException('OnlyOffice gagal mengonversi presentasi. Kode error: '.$result['error']);
        }

        if (($result['endConvert'] ?? false) !== true || empty($result['fileUrl'])) {
            throw new RuntimeException('Konversi PDF presentasi belum selesai.');
        }

        $fileUrl = (string) $result['fileUrl'];

        if (! $this->isTrustedOnlyOfficeUrl($fileUrl)) {
            throw new RuntimeException('URL hasil konversi OnlyOffice tidak dipercaya.');
        }

        $download = Http::accept('*/*')
            ->timeout($this->timeout())
            ->get($fileUrl);

        if (! $download->successful()) {
            throw new RuntimeException('Gagal mengunduh hasil PDF dari OnlyOffice.');
        }

        return (string) $download->body();
    }

    public function fileName(Presentation $presentation, string $extension): string
    {
        $base = preg_replace('/[^A-Za-z0-9_.-]+/', '-', trim((string) $presentation->title)) ?: 'presentasi';
        $base = trim($base, '-_.') ?: 'presentasi';

        return $base.'.'.strtolower($extension);
    }

    protected function presentationDocumentUrl(Presentation $presentation): string
    {
        $ttlMinutes = max(1, (int) config('services.onlyoffice.signed_url_ttl_minutes', 30));

        return app(PresentationDocumentKey::class)->signedFileUrl($presentation, $ttlMinutes);
    }

    protected function conversionUrl(string $key): string
    {
        $internalUrl = rtrim((string) config('services.onlyoffice.internal_url', 'http://onlyoffice'), '/');

        return $internalUrl.'/converter?shardkey='.rawurlencode($key);
    }

    protected function timeout(): int
    {
        return max(1, (int) config('services.onlyoffice.conversion_timeout', 120));
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

        foreach ($this->trustedOnlyOfficeUrls() as $trustedUrl) {
            $trusted = parse_url($trustedUrl);

            if (! is_array($trusted)) {
                continue;
            }

            if (($candidate['host'] ?? null) !== ($trusted['host'] ?? null)) {
                continue;
            }

            $trustedPort = $trusted['port'] ?? null;

            if ($trustedPort !== null && ($candidate['port'] ?? null) !== $trustedPort) {
                continue;
            }

            return true;
        }

        return false;
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
}
