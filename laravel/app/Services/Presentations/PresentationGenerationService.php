<?php

namespace App\Services\Presentations;

use App\Jobs\GeneratePresentation;
use App\Models\Presentation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Pipeline generate presentasi (epic #218, child #222).
 *
 * Membuat record presentasi (status pending), membangun outline deterministik
 * dari konfigurasi user, memanggil renderer Python (#221) untuk menghasilkan
 * PPTX, lalu menyimpannya ke private disk. Job `GeneratePresentation` yang
 * mengorkestrasi transisi status dengan guard anti stale-job.
 */
class PresentationGenerationService
{
    /** Template visual yang valid (selaras dengan renderer Python #221). */
    public const VISUAL_TEMPLATES = [
        'resmi_klasik' => 'Resmi Klasik',
        'modern_minimal' => 'Modern Minimal',
        'executive_brief' => 'Executive Brief',
        'data_tabel' => 'Data & Tabel',
        'kegiatan_dokumentasi' => 'Kegiatan & Dokumentasi',
    ];

    public const SLIDE_COUNT_MIN = 3;

    public const SLIDE_COUNT_MAX = 20;

    public const DEFAULT_SLIDE_COUNT = 8;

    /**
     * Mode aset visual (#227). Default `local_assets_only` (no-internet).
     * `licensed_web_assets` bersifat opsional & eksplisit; pengayaan aset web
     * berlisensi dilakukan di renderer Python dengan validasi lisensi + cache +
     * audit, dan selalu fallback ke aset lokal bila gagal.
     */
    public const ASSET_MODE_LOCAL = 'local_assets_only';

    public const ASSET_MODE_LICENSED = 'licensed_web_assets';

    public const ASSET_MODES = [
        self::ASSET_MODE_LOCAL,
        self::ASSET_MODE_LICENSED,
    ];

    public const DEFAULT_ASSET_MODE = self::ASSET_MODE_LOCAL;

    protected string $baseUrl;

    protected ?string $token;

    protected int $connectTimeout;

    protected int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.ai_document_service.url', 'http://127.0.0.1:8001'), '/');
        $this->token = config('services.ai_document_service.token');
        $this->connectTimeout = max(1, (int) config('services.ai_document_service.connect_timeout', 10));
        $this->timeout = max(1, (int) config('services.ai_document_service.timeout', 120));
    }

    /**
     * Buat presentasi baru (status pending) dari konfigurasi user dan dispatch
     * job generate. Source document ids difilter ke milik user + ready.
     *
     * @param  array<string, mixed>  $input
     */
    public function createAndDispatch(User $user, array $input): Presentation
    {
        $configuration = $this->normalizeConfiguration($input);
        $sourceDocumentIds = Presentation::sanitizeSourceDocumentIds(
            (int) $user->id,
            $input['source_document_ids'] ?? []
        );

        $presentation = Presentation::create([
            'user_id' => $user->id,
            'title' => $configuration['title'],
            'status' => Presentation::STATUS_PENDING,
            'visual_template' => $configuration['visual_template'],
            'configuration' => $configuration,
            'outline' => $this->buildOutline($configuration),
            'source_document_ids' => $sourceDocumentIds,
        ]);

        GeneratePresentation::dispatch($presentation);

        return $presentation;
    }

    /**
     * Dispatch ulang generate untuk presentasi yang sudah ada (retry).
     */
    public function dispatchExisting(Presentation $presentation): void
    {
        GeneratePresentation::dispatch($presentation);
    }

    /**
     * Render PPTX via Python lalu simpan ke private disk. Dipanggil oleh job.
     *
     * @return array{path: string, template: string, slide_count: int}
     */
    public function renderAndStore(Presentation $presentation): array
    {
        $rendered = $this->requestPptx($presentation);
        $path = $this->storePptx($presentation, $rendered['content']);

        return [
            'path' => $path,
            'template' => $rendered['template'],
            'slide_count' => $rendered['slide_count'],
        ];
    }

    /**
     * @return array{content: string, template: string, slide_count: int}
     */
    protected function requestPptx(Presentation $presentation): array
    {
        $configuration = is_array($presentation->configuration) ? $presentation->configuration : [];
        $outline = is_array($presentation->outline) ? $presentation->outline : [];

        $payload = [
            'title' => (string) ($configuration['title'] ?? $presentation->title),
            'visual_template' => (string) ($configuration['visual_template'] ?? $presentation->visual_template),
            'subtitle' => $configuration['subtitle'] ?? null,
            'audience' => $configuration['audience'] ?? null,
            'header' => $configuration['header'] ?? null,
            'footer' => $configuration['footer'] ?? null,
            'presenter' => $configuration['presenter'] ?? null,
            'unit' => $configuration['unit'] ?? null,
            'slide_count' => $configuration['slide_count'] ?? null,
            'asset_mode' => $this->normalizeAssetMode($configuration['asset_mode'] ?? null),
            'outline' => $outline,
        ];

        $response = Http::withToken($this->token ?: '')
            ->accept('application/vnd.openxmlformats-officedocument.presentationml.presentation')
            ->connectTimeout($this->connectTimeout)
            ->timeout($this->timeout)
            ->asJson()
            ->post($this->baseUrl.'/api/presentations/generate', $payload);

        if (! $response->successful()) {
            throw new RuntimeException($response->body() ?: 'Gagal membuat presentasi.');
        }

        $content = $response->body();

        if (! is_string($content) || strlen($content) < 100 || ! str_starts_with($content, 'PK')) {
            // PPTX adalah arsip ZIP (magic "PK"). Guard agar tidak menyimpan body
            // error/HTML sebagai .pptx.
            throw new RuntimeException('Hasil render presentasi tidak valid.');
        }

        return [
            'content' => $content,
            'template' => (string) ($response->header('X-Presentation-Template') ?: ($presentation->visual_template ?? '')),
            'slide_count' => (int) ($response->header('X-Presentation-Slide-Count') ?: 0),
        ];
    }

    protected function storePptx(Presentation $presentation, string $content): string
    {
        $path = 'presentations/'.$presentation->user_id.'/'.$presentation->id.'-'.Str::uuid().'.pptx';

        if (! Storage::disk('local')->put($path, $content)) {
            throw new RuntimeException('Gagal menyimpan file PPTX presentasi.');
        }

        return $path;
    }

    /**
     * Normalisasi konfigurasi hybrid dari input UI.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function normalizeConfiguration(array $input): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            $title = 'Presentasi ISTA AI';
        }

        $template = strtolower(trim((string) ($input['visual_template'] ?? '')));
        $template = str_replace([' ', '-'], '_', $template);
        if (! array_key_exists($template, self::VISUAL_TEMPLATES)) {
            $template = array_key_first(self::VISUAL_TEMPLATES);
        }

        $slideCount = (int) ($input['slide_count'] ?? self::DEFAULT_SLIDE_COUNT);
        $slideCount = max(self::SLIDE_COUNT_MIN, min(self::SLIDE_COUNT_MAX, $slideCount));

        return [
            'title' => Str::limit($title, 160, ''),
            'visual_template' => $template,
            'subtitle' => $this->cleanShort($input['subtitle'] ?? ''),
            'audience' => $this->cleanShort($input['audience'] ?? ''),
            'header' => $this->cleanShort($input['header'] ?? ''),
            'footer' => $this->cleanShort($input['footer'] ?? ''),
            'presenter' => $this->cleanShort($input['presenter'] ?? ''),
            'unit' => $this->cleanShort($input['unit'] ?? ''),
            'slide_count' => $slideCount,
            'asset_mode' => $this->normalizeAssetMode($input['asset_mode'] ?? null),
            'additional_instruction' => Str::limit(trim((string) ($input['additional_instruction'] ?? '')), 2000, ''),
        ];
    }

    /**
     * Normalisasi mode aset; nilai tak dikenal -> default lokal (no-internet).
     */
    public function normalizeAssetMode(mixed $value): string
    {
        $mode = strtolower(trim((string) $value));
        $mode = str_replace([' ', '-'], '_', $mode);

        return in_array($mode, self::ASSET_MODES, true) ? $mode : self::DEFAULT_ASSET_MODE;
    }

    /**
     * Bangun outline (slide konten) deterministik dari konfigurasi.
     *
     * MVP: skeleton terkontrol agar AI tidak mengarang. Outline berbasis arahan
     * tambahan user; bila kosong dipakai kerangka standar paparan resmi.
     * (Pengayaan outline berbasis AI/dokumen menyusul.)
     *
     * @param  array<string, mixed>  $configuration
     * @return list<array{title: string, bullets: list<string>}>
     */
    public function buildOutline(array $configuration): array
    {
        $audience = trim((string) ($configuration['audience'] ?? ''));
        $instruction = trim((string) ($configuration['additional_instruction'] ?? ''));

        $points = $this->splitInstructionPoints($instruction);

        $outline = [];

        $agendaBullets = ['Latar belakang dan tujuan paparan'];
        if ($audience !== '') {
            $agendaBullets[] = 'Audiens: '.$audience;
        }
        $agendaBullets[] = 'Pembahasan utama';
        $agendaBullets[] = 'Kesimpulan dan tindak lanjut';
        $outline[] = ['title' => 'Agenda', 'bullets' => $agendaBullets];

        if ($points !== []) {
            foreach (array_chunk($points, 5) as $index => $chunk) {
                $outline[] = [
                    'title' => count($points) > 5 ? 'Pembahasan '.($index + 1) : 'Pembahasan Utama',
                    'bullets' => $chunk,
                ];
            }
        } else {
            $outline[] = [
                'title' => 'Pembahasan Utama',
                'bullets' => [
                    'Ringkasan materi berdasarkan dokumen dan arahan.',
                    'Poin-poin penting yang perlu ditekankan.',
                    'Data atau temuan pendukung.',
                ],
            ];
        }

        $outline[] = [
            'title' => 'Kesimpulan & Tindak Lanjut',
            'bullets' => [
                'Ringkasan kesimpulan utama.',
                'Rekomendasi dan langkah tindak lanjut.',
            ],
        ];

        return $outline;
    }

    /**
     * @return list<string>
     */
    protected function splitInstructionPoints(string $instruction): array
    {
        if ($instruction === '') {
            return [];
        }

        $lines = preg_split('/[\r\n]+|(?<=[.;])\s+/', $instruction) ?: [];

        $points = [];
        foreach ($lines as $line) {
            $clean = trim(preg_replace('/^\s*(?:\d+[.)]|[-*•])\s*/', '', (string) $line) ?? '');
            if ($clean !== '') {
                $points[] = Str::limit($clean, 220, '');
            }
            if (count($points) >= 12) {
                break;
            }
        }

        return $points;
    }

    protected function cleanShort(mixed $value): string
    {
        return Str::limit(trim((string) $value), 200, '');
    }
}
