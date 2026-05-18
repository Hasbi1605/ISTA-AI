<?php

namespace App\Services\OnlyOffice;

use App\Models\Memo;
use App\Models\MemoVersion;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class DocumentConverter
{
    public function memoToPdf(Memo $memo, ?MemoVersion $version = null): string
    {
        $filePath = $version?->file_path ?: $memo->file_path;

        if (! $filePath) {
            throw new RuntimeException('File memo tidak ditemukan.');
        }

        $key = $this->conversionKey($memo, $version);
        $payload = [
            'async' => false,
            'filetype' => 'docx',
            'key' => $key,
            'outputtype' => 'pdf',
            'title' => $this->fileName($memo, 'docx'),
            'url' => $this->memoDocumentUrl($memo, $version),
            'exp' => time() + 300,
        ];

        $conversionUrl = $this->conversionUrl($key);
        $response = Http::acceptJson()
            ->asJson()
            ->timeout($this->timeout())
            ->post($conversionUrl, [
                'token' => app(JwtSigner::class)->sign($payload),
            ]);

        if (! $response->successful()) {
            throw new RuntimeException($response->body() ?: 'Gagal mengonversi memo ke PDF.');
        }

        $result = $response->json() ?: [];

        if (($result['error'] ?? null) !== null) {
            throw new RuntimeException('OnlyOffice gagal mengonversi memo. Kode error: '.$result['error']);
        }

        if (($result['endConvert'] ?? false) !== true || empty($result['fileUrl'])) {
            throw new RuntimeException('Konversi PDF belum selesai.');
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

    public function fileName(Memo $memo, string $extension): string
    {
        $base = preg_replace('/[^A-Za-z0-9_.-]+/', '-', trim($memo->title)) ?: 'memo';
        $base = trim($base, '-_.') ?: 'memo';

        return $base.'.'.strtolower($extension);
    }

    protected function memoDocumentUrl(Memo $memo, ?MemoVersion $version = null): string
    {
        $ttlMinutes = max(1, (int) config('services.onlyoffice.signed_url_ttl_minutes', 30));

        return app(MemoDocumentKey::class)->signedFileUrl($memo, $version?->id, $ttlMinutes);
    }

    protected function conversionKey(Memo $memo, ?MemoVersion $version = null): string
    {
        return app(MemoDocumentKey::class)->forConversion($memo, $version);
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
