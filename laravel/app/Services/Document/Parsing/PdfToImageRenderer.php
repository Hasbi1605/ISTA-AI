<?php

namespace App\Services\Document\Parsing;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class PdfToImageRenderer
{
    protected int $dpi;
    protected string $format;
    protected int $maxPages;

    public function __construct()
    {
        $this->dpi = config('ai.vision_cascade.image_dpi', 150);
        $this->format = config('ai.vision_cascade.image_format', 'png');
        $this->maxPages = config('ai.vision_cascade.max_pages', 20);
    }

    public function renderToImages(string $pdfPath): array
    {
        $outputDir = $this->prepareOutputDirectory();

        if ($this->isImagickAvailable()) {
            try {
                return $this->renderWithImagick($pdfPath, $outputDir);
            } catch (\Throwable $e) {
                Log::warning('PdfToImageRenderer: Imagick failed, falling back to pdftoppm', [
                    'file' => $pdfPath,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->renderWithPdftoppm($pdfPath, $outputDir);
    }

    protected function isImagickAvailable(): bool
    {
        return extension_loaded('imagick') && class_exists('Imagick');
    }

    protected function prepareOutputDirectory(): string
    {
        $tempDir = storage_path('app/temp/ocr/' . uniqid('pdf_', true));
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        return $tempDir;
    }

    protected function renderWithImagick(string $pdfPath, string $outputDir): array
    {
        $images = [];
        $pdf = new \Imagick();

        try {
            $pdf->setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, 256);
            $pdf->setResourceLimit(\Imagick::RESOURCETYPE_MAP, 128);

            $pdf->readImage($pdfPath);

            $pageCount = $pdf->getNumberImages();
            $pagesToProcess = min($pageCount, $this->maxPages);

            Log::info('PdfToImageRenderer: Rendering with Imagick', [
                'file' => $pdfPath,
                'total_pages' => $pageCount,
                'pages_to_process' => $pagesToProcess,
            ]);

            for ($i = 0; $i < $pagesToProcess; $i++) {
                $page = new \Imagick();
                $page->setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, 128);
                $page->setResourceLimit(\Imagick::RESOURCETYPE_MAP, 64);

                $page->readImage($pdfPath . '[' . $i . ']');
                $page->setImageFormat($this->format);
                $page->setImageCompressionQuality(85);
                $page->setImageResolution($this->dpi, $this->dpi);
                $page->stripImage();

                $outputPath = $outputDir . '/page_' . str_pad($i + 1, 4, '0', STR_PAD_LEFT) . '.' . $this->format;
                $page->writeImage($outputPath);

                $images[] = [
                    'page_number' => $i + 1,
                    'image_path' => $outputPath,
                ];

                $page->clear();
                $page->destroy();
            }

            $pdf->clear();
            $pdf->destroy();

        } catch (\Throwable $e) {
            Log::error('PdfToImageRenderer: Imagick rendering failed', [
                'file' => $pdfPath,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException("Failed to render PDF with Imagick: " . $e->getMessage());
        }

        return $images;
    }

    protected function renderWithPdftoppm(string $pdfPath, string $outputDir): array
    {
        $this->checkPdftoppmAvailable();

        $pageCount = $this->getPageCountWithPdftoppm($pdfPath);
        $pagesToProcess = min($pageCount, $this->maxPages);

        Log::info('PdfToImageRenderer: Rendering with pdftoppm', [
            'file' => $pdfPath,
            'total_pages' => $pageCount,
            'pages_to_process' => $pagesToProcess,
        ]);

        $baseName = $outputDir . '/page';
        $formatArg = $this->format === 'png' ? 'png' : 'jpeg';

        $dpiArg = "-r{$this->dpi}";
        $command = sprintf(
            'pdftoppm %s -%s -f 1 -l %d %s %s 2>&1',
            escapeshellarg($dpiArg),
            escapeshellarg($formatArg),
            $pagesToProcess,
            escapeshellarg($pdfPath),
            escapeshellarg($baseName)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            $errorMsg = implode("\n", $output);
            Log::error('PdfToImageRenderer: pdftoppm failed', [
                'file' => $pdfPath,
                'error' => $errorMsg,
                'return_code' => $returnCode,
            ]);

            throw new RuntimeException("pdftoppm failed: " . $errorMsg);
        }

        $images = [];
        $files = glob($outputDir . '/page-*.' . $this->format);

        usort($files, function ($a, $b) {
            return preg_replace('/.*page-(\d+)\.\w+/', '$1', $a) - preg_replace('/.*page-(\d+)\.\w+/', '$1', $b);
        });

        foreach ($files as $index => $filePath) {
            $images[] = [
                'page_number' => $index + 1,
                'image_path' => $filePath,
            ];
        }

        return $images;
    }

    protected function checkPdftoppmAvailable(): void
    {
        exec('which pdftoppm', $output, $returnCode);

        if ($returnCode !== 0) {
            throw new RuntimeException(
                "pdftoppm is not available. Please install poppler-utils or enable the Imagick PHP extension."
            );
        }
    }

    protected function getPageCountWithPdftoppm(string $pdfPath): int
    {
        exec('pdftoppm -f 1 -l 1 ' . escapeshellarg($pdfPath) . ' /dev/null 2>&1 | head -1', $output, $returnCode);

        if ($returnCode !== 0) {
            return 1;
        }

        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($pdfPath);
            return count($pdf->getPages());
        } catch (\Throwable $e) {
            return 1;
        }
    }

    public function cleanup(array $images): void
    {
        foreach ($images as $image) {
            if (file_exists($image['image_path'])) {
                @unlink($image['image_path']);
            }
        }

        $dir = dirname($images[0]['image_path'] ?? '');
        if (!empty($dir) && is_dir($dir)) {
            @rmdir($dir);
        }
    }
}