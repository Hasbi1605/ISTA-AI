<?php

namespace App\Services\Document;

use App\Services\AI\EmbeddingCascadeService;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\AiManager;
use Laravel\Ai\AnonymousAgent;

class HydeQueryExpansionService
{
    private const HYDE_SKIP_PATTERNS = [
        '^(rangkum|buat ringkasan|ringkaskan)',
        '^(baca|baca isi|baca dan)',
        '^(apa isi|apa saja isi)',
        '^(jelaskan isi|jelaskan dokumen)',
        '^(tampilkan|tunjukkan|sebutkan)',
        '^(bandingkan isi|buat perbandingan)',
        '^(rangkumkan|buat tabel)',
        '^halo|^hi |^hai ',
    ];

    private const HYDE_USE_PATTERNS = [
        '\bmengapa\b', '\bkenapa\b',
        '\bbagaimana (cara|bisa|pengaruh|hubungan|dampak|peran)\b',
        '\bapa (hubungan|perbedaan|persamaan|keterkaitan|pengaruh|dampak|peran)\b',
        '\bapa yang dimaksud\b', '\bjelaskan konsep\b', '\bjelaskan teori\b',
        '\bbukti\s*kan\b', '\bargumentasikan\b', '\bankur\b', '\bimplikasi\b',
        '\bkritik\b', '\bevaluasi\b', '\banalisis\b', '\binterpretasi\b',
    ];

    protected ?EmbeddingCascadeService $embeddingCascade = null;
    protected ?AiManager $aiManager = null;
    protected array $cascadeNodes;
    protected bool $enabled;
    protected string $mode;
    protected int $timeout;
    protected int $maxTokens;

    public function __construct(?array $config = null)
    {
        $config = $config ?? $this->getDefaultConfig();
        
        $this->enabled = $config['enabled'] ?? true;
        $this->mode = $config['mode'] ?? 'smart';
        $this->timeout = (int) ($config['timeout'] ?? 5);
        $this->maxTokens = (int) ($config['max_tokens'] ?? 100);
        $this->cascadeNodes = $config['cascade_nodes'] ?? [];
    }

    protected function getDefaultConfig(): array
    {
        if (!app()->bound('config')) {
            return [
                'enabled' => true,
                'mode' => 'smart',
                'timeout' => 5,
                'max_tokens' => 100,
                'cascade_nodes' => [],
            ];
        }

        return [
            'enabled' => config('ai.rag.hyde.enabled', true),
            'mode' => config('ai.rag.hyde.mode', 'smart'),
            'timeout' => (int) config('ai.rag.hyde.timeout', 5),
            'max_tokens' => (int) config('ai.rag.hyde.max_tokens', 100),
            'cascade_nodes' => config('ai.cascade.nodes', []),
        ];
    }

    public function shouldUseHyde(string $query): array
    {
        if (!$this->enabled) {
            return [false, 'hyde_disabled'];
        }

        $q = trim($query);
        $words = preg_split('/\s+/', strtolower($q));

        if (count($words) < 5) {
            return [false, "query terlalu pendek (" . count($words) . " kata)"];
        }

        foreach (self::HYDE_SKIP_PATTERNS as $pattern) {
            if (preg_match("/{$pattern}/i", $q)) {
                return [false, "pattern skip: '{$pattern}'"];
            }
        }

        foreach (self::HYDE_USE_PATTERNS as $pattern) {
            if (preg_match("/{$pattern}/i", $q)) {
                return [true, "pola konseptual: '{$pattern}'"];
            }
        }

        if (count($words) >= 8 && str_contains($query, '?')) {
            return [true, "query panjang (" . count($words) . " kata) dengan tanda tanya"];
        }

        return [false, 'tidak ada pola konseptual terdeteksi'];
    }

    public function generateEnhancedQuery(string $originalQuery): string
    {
        if (strlen(trim($originalQuery)) < 10) {
            return $originalQuery;
        }

        if (empty($this->cascadeNodes)) {
            return $originalQuery;
        }

        $queryForHyde = strlen($originalQuery) > 500 ? substr($originalQuery, 0, 500) : $originalQuery;

        $attemptedModels = [];
        foreach ($this->cascadeNodes as $node) {
            if (count($attemptedModels) >= 2) {
                break;
            }

            $modelName = $node['model'] ?? '';
            $provider = $node['provider'] ?? '';
            $apiKey = $node['api_key'] ?? '';

            if (empty($apiKey)) {
                continue;
            }

            if ($provider === 'gemini') {
                continue;
            }

            $attemptedModels[] = $modelName;

            try {
                $enhanced = $this->generateWithNode($node, $queryForHyde, $originalQuery);
                if ($enhanced !== $originalQuery) {
                    return $enhanced;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return $originalQuery;
    }

    protected function generateWithNode(array $node, string $query, string $originalQuery): string
    {
        if (!app()->bound('config') || !app()->bound(AiManager::class)) {
            return $originalQuery;
        }

        try {
            $configKey = 'ai.providers.hyde_temp';
            config([$configKey => [
                'driver' => $node['provider'],
                'key' => $node['api_key'],
                'url' => $node['base_url'] ?? null,
                'models' => [
                    'text' => [
                        'default' => $node['model'],
                    ],
                ],
            ]]);

            $ai = app(AiManager::class);
            $provider = $ai->textProvider('hyde_temp');

            $systemPrompt = 'Buat jawaban hipotetis singkat 2-3 kalimat untuk pertanyaan berikut. Padat, faktual, gunakan kosakata yang relevan dengan topik.';

            $fullPrompt = $systemPrompt . "\n\nPertanyaan: " . $query;

            $response = $provider->complete($fullPrompt, $node['model'], [
                'max_tokens' => $this->maxTokens,
                'timeout' => $this->timeout,
            ]);

            $hypo = trim($response->text ?? '');

            if (!empty($hypo)) {
                return $originalQuery . "\n" . $hypo;
            }
        } catch (\Throwable $e) {
        }

        return $originalQuery;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getMode(): string
    {
        return $this->mode;
    }
}