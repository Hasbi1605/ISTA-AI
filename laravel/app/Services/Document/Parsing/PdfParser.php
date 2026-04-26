<?php

namespace App\Services\Document\Parsing;

use Smalot\PdfParser\Parser as PdfParserEngine;
use App\Services\Document\Ocr\OcrOrchestrator;
use Illuminate\Support\Facades\Log;

class PdfParser implements DocumentParserInterface
{
    protected array $pages = [];
    protected bool $enableOcrFallback;

    public function __construct()
    {
        $this->enableOcrFallback = config('ai.ocr.enabled', true);
    }

    public function parse(string $filePath): array
    {
        $this->pages = [];

        try {
            $parser = new PdfParserEngine();
            $pdf = $parser->parseFile($filePath);
            $pages = $pdf->getPages();

            foreach ($pages as $index => $page) {
                $text = $page->getText();
                $text = $this->cleanText($text);

                if (!empty(trim($text))) {
                    $this->pages[] = [
                        'page_content' => $text,
                        'page_number' => $index + 1,
                        'source' => 'pdf',
                    ];
                }
            }

            if (empty($this->pages)) {
                return $this->handleEmptyContent($filePath);
            }

        } catch (\Throwable $e) {
            if ($this->enableOcrFallback && $this->isPdfFile($filePath)) {
                return $this->handleEmptyContent($filePath);
            }
            throw $e;
        }

        return $this->pages;
    }

    protected function isPdfFile(string $filePath): bool
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        return $extension === 'pdf';
    }

    protected function handleEmptyContent(string $filePath): array
    {
        if (!$this->enableOcrFallback) {
            throw new \RuntimeException('PDF contains no extractable text (may be scanned/image-based) and OCR is disabled');
        }

        Log::info('PdfParser: No text extracted, attempting OCR fallback', [
            'file' => $filePath,
        ]);

        try {
            $orchestrator = new OcrOrchestrator();
            $pages = $orchestrator->processScannedPdf($filePath);

            Log::info('PdfParser: OCR fallback successful', [
                'file' => $filePath,
                'pages' => count($pages),
            ]);

            return $pages;
        } catch (\Throwable $e) {
            Log::error('PdfParser: OCR fallback failed', [
                'file' => $filePath,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('PDF contains no extractable text. OCR failed: ' . $e->getMessage());
        }
    }

    public function supports(string $mimeType): bool
    {
        return in_array($mimeType, [
            'application/pdf',
            'application/x-pdf',
        ]);
    }

    protected function cleanText(string $text): string
    {
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        return $text;
    }
}