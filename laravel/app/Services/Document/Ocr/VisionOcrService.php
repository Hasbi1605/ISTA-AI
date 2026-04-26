<?php

namespace App\Services\Document\Ocr;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class VisionOcrService
{
    protected array $nodes;
    protected bool $enabled;
    protected int $maxPages;

    public function __construct()
    {
        $this->enabled = config('ai.vision_cascade.enabled', true);
        $this->nodes = config('ai.vision_cascade.nodes', []);
        $this->maxPages = config('ai.vision_cascade.max_pages', 20);
    }

    public function extractTextFromImages(array $images): array
    {
        if (!$this->enabled) {
            throw new RuntimeException('Vision OCR is not enabled');
        }

        if (empty($this->nodes)) {
            throw new RuntimeException('No vision nodes configured');
        }

        $errors = [];

        foreach ($this->nodes as $index => $node) {
            if (empty($node['api_key'])) {
                $errors[] = "Node {$index}: No API key configured";
                continue;
            }

            try {
                Log::info("VisionOcrService: Attempting node {$index}", [
                    'label' => $node['label'],
                    'model' => $node['model'],
                ]);

                $result = $this->processWithNode($node, $images);

                Log::info("VisionOcrService: Success using node {$index}", [
                    'label' => $node['label'],
                ]);

                return $result;
            } catch (\Throwable $e) {
                $errorMsg = "Node {$index} ({$node['label']}) failed: " . $e->getMessage();
                Log::warning("VisionOcrService: {$errorMsg}");
                $errors[] = $errorMsg;
            }
        }

        $allErrors = implode("; ", $errors);
        Log::error("VisionOcrService: All vision nodes failed. Errors: {$allErrors}");

        throw new RuntimeException("Vision OCR cascade failed for all nodes. Errors: {$allErrors}");
    }

    protected function processWithNode(array $node, array $images): array
    {
        $baseUrl = $node['base_url'] ?? 'https://api.openai.com/v1';
        $apiKey = $node['api_key'];
        $model = $node['model'];

        $messages = $this->buildVisionMessages($images);

        $response = Http::timeout(120)
            ->connectTimeout(30)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ])
            ->post("{$baseUrl}/chat/completions", [
                'model' => $model,
                'messages' => $messages,
                'temperature' => 0.1,
                'max_tokens' => 4096,
            ]);

        if ($response->failed()) {
            $status = $response->status();
            $body = $response->body();
            throw new RuntimeException("Vision API error (HTTP {$status}): {$body}");
        }

        $data = $response->json();

        $content = $data['choices'][0]['message']['content'] ?? '';

        return $this->parseOcrResponse($content, count($images));
    }

    protected function buildVisionMessages(array $images): array
    {
        $systemPrompt = <<<'PROMPT'
Anda adalah OCR (Optical Character Recognition) yang ahli. 
Tugas Anda adalah mengekstrak SEMUA teks yang terlihat dalam gambar dokumen PDF yang dipindai (scanned).

Petunjuk penting:
1. Ekstrak semua teks yang terlihat dengan akurat
2. Pertahankan struktur paragraf dan format dasar
3. Jika ada tabel, ekstrak dalam format yang jelas
4. Jika ada gambar/foto, описавыйте их jika mengandung teks yang relevan
5. Jangan menambahkan teks yang tidak ada dalam gambar
6. Jangan membuat kesalahan interpretasi karakter
7. Berikan hasil dalam format yang terstruktur

Format output yang diharapkan:
- Untuk setiap halaman, tampilkan nomor halaman
- Ekstrak semua teks dalam bahasa aslinya (biasanya Bahasa Indonesia)
- Jika teks tidak jelas, gunakan [tidak terbaca] sebagai gantinya

Mulai ekstraksi sekarang.
PROMPT;

        $userContent = [];

        foreach ($images as $imageData) {
            $imagePath = $imageData['image_path'];
            $pageNumber = $imageData['page_number'];

            if (!file_exists($imagePath)) {
                Log::warning("VisionOcrService: Image file not found", [
                    'path' => $imagePath,
                ]);
                continue;
            }

            $imageDataEncoded = base64_encode(file_get_contents($imagePath));
            $mimeType = 'image/png';

            $extension = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
            if ($extension === 'jpg' || $extension === 'jpeg') {
                $mimeType = 'image/jpeg';
            }

            $userContent[] = [
                'type' => 'text',
                'text' => "Halaman {$pageNumber}:",
            ];

            $userContent[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => "data:{$mimeType};base64,{$imageDataEncoded}",
                ],
            ];
        }

        return [
            [
                'role' => 'system',
                'content' => $systemPrompt,
            ],
            [
                'role' => 'user',
                'content' => $userContent,
            ],
        ];
    }

    protected function parseOcrResponse(string $content, int $pageCount): array
    {
        $pages = [];
        $currentPage = 1;
        $currentText = '';

        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            if (preg_match('/halaman\s+(\d+)/i', $line, $matches)) {
                if (!empty(trim($currentText))) {
                    $pages[] = [
                        'page_number' => $currentPage,
                        'page_content' => trim($currentText),
                        'source' => 'ocr_vision',
                    ];
                }

                $currentPage = (int) $matches[1];
                $currentText = '';
            } else {
                $currentText .= $line . "\n";
            }
        }

        if (!empty(trim($currentText))) {
            $pages[] = [
                'page_number' => $currentPage,
                'page_content' => trim($currentText),
                'source' => 'ocr_vision',
            ];
        }

        if (empty($pages) && !empty(trim($content))) {
            $pages[] = [
                'page_number' => 1,
                'page_content' => trim($content),
                'source' => 'ocr_vision',
            ];
        }

        return $pages;
    }
}
