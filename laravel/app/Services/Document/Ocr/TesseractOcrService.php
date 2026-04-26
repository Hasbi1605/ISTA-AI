<?php

namespace App\Services\Document\Ocr;

use Illuminate\Support\Facades\Log;
use RuntimeException;

class TesseractOcrService
{
    protected string $tesseractPath;
    protected bool $enabled;

    public function __construct()
    {
        $this->tesseractPath = config('ai.ocr.tesseract_path', 'tesseract');
        $this->enabled = config('ai.ocr.fallback_to_tesseract', true);
    }

    public function isAvailable(): bool
    {
        if (!$this->enabled) {
            return false;
        }

        exec($this->tesseractPath . ' --version 2>&1', $output, $returnCode);

        return $returnCode === 0;
    }

    public function extractTextFromImages(array $images): array
    {
        if (!$this->isAvailable()) {
            throw new RuntimeException('Tesseract OCR is not available. Please install it on your system or enable the Imagick extension.');
        }

        $pages = [];

        foreach ($images as $imageData) {
            $imagePath = $imageData['image_path'];
            $pageNumber = $imageData['page_number'];

            if (!file_exists($imagePath)) {
                Log::warning("TesseractOcrService: Image file not found", [
                    'path' => $imagePath,
                ]);
                continue;
            }

            try {
                $text = $this->extractTextFromImage($imagePath);
                $text = $this->cleanText($text);

                if (!empty(trim($text))) {
                    $pages[] = [
                        'page_number' => $pageNumber,
                        'page_content' => $text,
                        'source' => 'ocr_tesseract',
                    ];
                }

                Log::debug("TesseractOcrService: Extracted text from page {$pageNumber}", [
                    'text_length' => strlen($text),
                ]);
            } catch (\Throwable $e) {
                Log::warning("TesseractOcrService: Failed to extract from page {$pageNumber}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (empty($pages)) {
            throw new RuntimeException('Tesseract OCR failed to extract text from all pages');
        }

        return $pages;
    }

    protected function extractTextFromImage(string $imagePath): string
    {
        $tempOutput = sys_get_temp_dir() . '/tesseract_' . uniqid() . '.txt';

        $command = sprintf(
            '%s %s stdout -l ind+eng 2>&1',
            escapeshellarg($this->tesseractPath),
            escapeshellarg($imagePath)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            $errorMsg = implode("\n", $output);
            throw new RuntimeException("Tesseract failed: {$errorMsg}");
        }

        return implode("\n", $output);
    }

    protected function cleanText(string $text): string
    {
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        return $text;
    }

    public static function getSystemRequirements(): array
    {
        return [
            'ubuntu' => 'sudo apt-get install tesseract-ocr tesseract-ocr-ind',
            'centos' => 'sudo yum install tesseract tesseract-langpack-ind',
            'macos' => 'brew install tesseract tesseract-langdata',
            'windows' => 'Download Tesseract from https://github.com/UB-Mannheim/tesseract/wiki',
        ];
    }
}