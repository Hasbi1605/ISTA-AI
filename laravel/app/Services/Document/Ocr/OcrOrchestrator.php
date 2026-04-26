<?php

namespace App\Services\Document\Ocr;

use App\Services\Document\Parsing\PdfScannerDetector;
use App\Services\Document\Parsing\PdfToImageRenderer;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OcrOrchestrator
{
    protected PdfScannerDetector $detector;
    protected PdfToImageRenderer $renderer;
    protected VisionOcrService $visionOcr;
    protected TesseractOcrService $tesseractOcr;
    protected bool $enabled;

    public function __construct()
    {
        $this->detector = new PdfScannerDetector();
        $this->renderer = new PdfToImageRenderer();
        $this->visionOcr = new VisionOcrService();
        $this->tesseractOcr = new TesseractOcrService();
        $this->enabled = config('ai.ocr.enabled', true);
    }

    public function processScannedPdf(string $filePath): array
    {
        if (!$this->enabled) {
            throw new RuntimeException('OCR is not enabled');
        }

        Log::info('OcrOrchestrator: Starting OCR process', [
            'file' => $filePath,
        ]);

        $isScanned = $this->detector->isScanned($filePath);

        Log::info('OcrOrchestrator: PDF scan detection', [
            'file' => $filePath,
            'is_scanned' => $isScanned,
        ]);

        if (!$isScanned) {
            throw new RuntimeException('PDF is not scanned, normal text extraction should be used');
        }

        return $this->performOcr($filePath);
    }

    protected function performOcr(string $filePath): array
    {
        $images = [];

        try {
            $images = $this->renderer->renderToImages($filePath);

            Log::info('OcrOrchestrator: PDF rendered to images', [
                'file' => $filePath,
                'image_count' => count($images),
            ]);
        } catch (\Throwable $e) {
            Log::error('OcrOrchestrator: Failed to render PDF to images', [
                'file' => $filePath,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException("Failed to render PDF: " . $e->getMessage());
        }

        if (empty($images)) {
            throw new RuntimeException('No images were generated from PDF');
        }

        $pages = [];

        try {
            $pages = $this->visionOcr->extractTextFromImages($images);

            Log::info('OcrOrchestrator: Vision OCR successful', [
                'file' => $filePath,
                'pages' => count($pages),
            ]);
        } catch (\Throwable $e) {
            Log::warning('OcrOrchestrator: Vision OCR failed, trying Tesseract', [
                'file' => $filePath,
                'error' => $e->getMessage(),
            ]);

            if ($this->tesseractOcr->isAvailable()) {
                try {
                    $pages = $this->tesseractOcr->extractTextFromImages($images);

                    Log::info('OcrOrchestrator: Tesseract OCR successful', [
                        'file' => $filePath,
                        'pages' => count($pages),
                    ]);
                } catch (\Throwable $tesseractError) {
                    Log::error('OcrOrchestrator: Tesseract OCR also failed', [
                        'file' => $filePath,
                        'error' => $tesseractError->getMessage(),
                    ]);
                    $this->renderer->cleanup($images);
                    throw new RuntimeException("All OCR methods failed: Vision - {$e->getMessage()}, Tesseract - {$tesseractError->getMessage()}");
                }
            } else {
                $this->renderer->cleanup($images);
                throw new RuntimeException("Vision OCR failed and Tesseract is not available: " . $e->getMessage());
            }
        }

        $this->renderer->cleanup($images);

        if (empty($pages)) {
            throw new RuntimeException('OCR did not produce any text content');
        }

        return $pages;
    }

    public function isScanned(string $filePath): bool
    {
        return $this->detector->isScanned($filePath);
    }

    public function getPageCount(string $filePath): int
    {
        return $this->detector->getPageCount($filePath);
    }
}