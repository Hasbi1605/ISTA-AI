<?php

namespace App\Services\Document\Parsing;

use Smalot\PdfParser\Parser as PdfParserEngine;
use Illuminate\Support\Facades\Log;

class PdfScannerDetector
{
    protected const MIN_TEXT_LENGTH = 50;
    protected const SAMPLE_PAGES = 3;

    public function isScanned(string $filePath): bool
    {
        try {
            $parser = new PdfParserEngine();
            $pdf = $parser->parseFile($filePath);
            $pages = $pdf->getPages();

            if (empty($pages)) {
                return true;
            }

            $pagesToCheck = min(count($pages), self::SAMPLE_PAGES);
            $totalTextLength = 0;

            for ($i = 0; $i < $pagesToCheck; $i++) {
                $page = $pages[$i];
                $text = $page->getText();
                $text = $this->cleanText($text);
                $totalTextLength += strlen(trim($text));
            }

            $averageTextLength = $totalTextLength / $pagesToCheck;

            Log::debug('PdfScannerDetector: Text analysis', [
                'file' => $filePath,
                'pages_checked' => $pagesToCheck,
                'total_text_length' => $totalTextLength,
                'average_text_length' => $averageTextLength,
            ]);

            return $averageTextLength < self::MIN_TEXT_LENGTH;
        } catch (\Throwable $e) {
            Log::warning('PdfScannerDetector: Failed to analyze PDF, assuming scanned', [
                'file' => $filePath,
                'error' => $e->getMessage(),
            ]);
            return true;
        }
    }

    public function hasExtractableText(string $filePath): bool
    {
        return !$this->isScanned($filePath);
    }

    protected function cleanText(string $text): string
    {
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    public function getPageCount(string $filePath): int
    {
        try {
            $parser = new PdfParserEngine();
            $pdf = $parser->parseFile($filePath);
            return count($pdf->getPages());
        } catch (\Throwable $e) {
            Log::warning('PdfScannerDetector: Failed to get page count', [
                'file' => $filePath,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }
}
