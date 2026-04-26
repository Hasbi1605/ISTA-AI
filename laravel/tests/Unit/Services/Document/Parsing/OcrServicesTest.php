<?php

namespace Tests\Unit\Services\Document\Parsing;

use Tests\TestCase;
use App\Services\Document\Parsing\PdfScannerDetector;
use App\Services\Document\Parsing\PdfToImageRenderer;
use App\Services\Document\Ocr\OcrOrchestrator;
use App\Services\Document\Ocr\VisionOcrService;
use App\Services\Document\Ocr\TesseractOcrService;

class OcrServicesTest extends TestCase
{
    public function test_pdf_scanner_detector_can_be_instantiated(): void
    {
        $detector = new PdfScannerDetector();
        $this->assertInstanceOf(PdfScannerDetector::class, $detector);
    }

    public function test_pdf_to_image_renderer_can_be_instantiated(): void
    {
        $renderer = new PdfToImageRenderer();
        $this->assertInstanceOf(PdfToImageRenderer::class, $renderer);
    }

    public function test_ocr_orchestrator_can_be_instantiated(): void
    {
        config(['ai.ocr.enabled' => false]);
        
        $orchestrator = new OcrOrchestrator();
        $this->assertInstanceOf(OcrOrchestrator::class, $orchestrator);
    }

    public function test_ocr_orchestrator_throws_when_disabled(): void
    {
        config(['ai.ocr.enabled' => false]);
        
        $orchestrator = new OcrOrchestrator();
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OCR is not enabled');
        
        $orchestrator->processScannedPdf('/non/existent/file.pdf');
    }

    public function test_vision_ocr_service_can_be_instantiated(): void
    {
        $service = new VisionOcrService();
        $this->assertInstanceOf(VisionOcrService::class, $service);
    }

    public function test_vision_ocr_service_throws_when_disabled(): void
    {
        config(['ai.vision_cascade.enabled' => false]);
        
        $service = new VisionOcrService();
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Vision OCR is not enabled');
        
        $service->extractTextFromImages([['image_path' => '/non/existent.png', 'page_number' => 1]]);
    }

    public function test_vision_ocr_service_throws_when_no_nodes(): void
    {
        config(['ai.vision_cascade.enabled' => true]);
        config(['ai.vision_cascade.nodes' => []]);
        
        $service = new VisionOcrService();
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No vision nodes configured');
        
        $service->extractTextFromImages([['image_path' => '/non/existent.png', 'page_number' => 1]]);
    }

    public function test_tesseract_ocr_service_can_be_instantiated(): void
    {
        $service = new TesseractOcrService();
        $this->assertInstanceOf(TesseractOcrService::class, $service);
    }

    public function test_tesseract_ocr_service_gets_system_requirements(): void
    {
        $requirements = TesseractOcrService::getSystemRequirements();
        
        $this->assertIsArray($requirements);
        $this->assertArrayHasKey('ubuntu', $requirements);
        $this->assertArrayHasKey('macos', $requirements);
        $this->assertArrayHasKey('windows', $requirements);
    }

    public function test_pdf_scanner_detector_returns_true_for_missing_file(): void
    {
        $detector = new PdfScannerDetector();
        
        $result = $detector->isScanned('/non/existent/file.pdf');
        
        $this->assertTrue($result);
    }

    public function test_pdf_scanner_detector_has_extractable_text_returns_false_for_missing_file(): void
    {
        $detector = new PdfScannerDetector();
        
        $result = $detector->hasExtractableText('/non/existent/file.pdf');
        
        $this->assertFalse($result);
    }

    public function test_vision_ocr_service_falls_back_to_next_node_when_primary_fails(): void
    {
        config(['ai.vision_cascade.enabled' => true]);
        config(['ai.vision_cascade.nodes' => [
            [
                'label' => 'Primary',
                'provider' => 'openai',
                'model' => 'gpt-4.1',
                'api_key' => 'primary-key',
                'base_url' => 'https://primary.example/v1',
            ],
            [
                'label' => 'Backup',
                'provider' => 'openai',
                'model' => 'gpt-4o',
                'api_key' => 'backup-key',
                'base_url' => 'https://backup.example/v1',
            ],
        ]]);

        $tmpDir = sys_get_temp_dir() . '/devin-ocr-' . uniqid();
        mkdir($tmpDir, 0755, true);
        $imagePath = $tmpDir . '/page-1.png';
        file_put_contents($imagePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII='));

        \Illuminate\Support\Facades\Http::fake([
            'primary.example/*' => \Illuminate\Support\Facades\Http::response(['error' => 'rate limit'], 429),
            'backup.example/*' => \Illuminate\Support\Facades\Http::response([
                'choices' => [
                    ['message' => ['content' => "Halaman 1:\nIsi halaman pertama hasil OCR backup."]],
                ],
            ], 200),
        ]);

        $service = new VisionOcrService();

        $pages = $service->extractTextFromImages([
            ['image_path' => $imagePath, 'page_number' => 1],
        ]);

        unlink($imagePath);
        rmdir($tmpDir);

        $this->assertNotEmpty($pages);
        $this->assertEquals(1, $pages[0]['page_number']);
        $this->assertStringContainsString('OCR backup', $pages[0]['page_content']);
        $this->assertEquals('ocr_vision', $pages[0]['source']);
    }

    public function test_vision_ocr_service_throws_when_all_nodes_fail(): void
    {
        config(['ai.vision_cascade.enabled' => true]);
        config(['ai.vision_cascade.nodes' => [
            [
                'label' => 'Primary',
                'provider' => 'openai',
                'model' => 'gpt-4.1',
                'api_key' => 'primary-key',
                'base_url' => 'https://primary.example/v1',
            ],
            [
                'label' => 'Backup',
                'provider' => 'openai',
                'model' => 'gpt-4o',
                'api_key' => 'backup-key',
                'base_url' => 'https://backup.example/v1',
            ],
        ]]);

        $tmpDir = sys_get_temp_dir() . '/devin-ocr-' . uniqid();
        mkdir($tmpDir, 0755, true);
        $imagePath = $tmpDir . '/page-1.png';
        file_put_contents($imagePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII='));

        \Illuminate\Support\Facades\Http::fake([
            'primary.example/*' => \Illuminate\Support\Facades\Http::response(['error' => 'fail'], 500),
            'backup.example/*' => \Illuminate\Support\Facades\Http::response(['error' => 'fail'], 503),
        ]);

        $service = new VisionOcrService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Vision OCR cascade failed');

        try {
            $service->extractTextFromImages([
                ['image_path' => $imagePath, 'page_number' => 1],
            ]);
        } finally {
            unlink($imagePath);
            rmdir($tmpDir);
        }
    }

    public function test_vision_ocr_service_parses_multi_page_response(): void
    {
        config(['ai.vision_cascade.enabled' => true]);
        config(['ai.vision_cascade.nodes' => [[
            'label' => 'Primary',
            'provider' => 'openai',
            'model' => 'gpt-4.1',
            'api_key' => 'primary-key',
            'base_url' => 'https://primary.example/v1',
        ]]]);

        $tmpDir = sys_get_temp_dir() . '/devin-ocr-' . uniqid();
        mkdir($tmpDir, 0755, true);
        $img1 = $tmpDir . '/page-1.png';
        $img2 = $tmpDir . '/page-2.png';
        $pixel = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=');
        file_put_contents($img1, $pixel);
        file_put_contents($img2, $pixel);

        $multiPageContent = "Halaman 1:\nKonten halaman satu hasil OCR.\n\nHalaman 2:\nKonten halaman dua hasil OCR.";

        \Illuminate\Support\Facades\Http::fake([
            'primary.example/*' => \Illuminate\Support\Facades\Http::response([
                'choices' => [
                    ['message' => ['content' => $multiPageContent]],
                ],
            ], 200),
        ]);

        $service = new VisionOcrService();

        $pages = $service->extractTextFromImages([
            ['image_path' => $img1, 'page_number' => 1],
            ['image_path' => $img2, 'page_number' => 2],
        ]);

        unlink($img1);
        unlink($img2);
        rmdir($tmpDir);

        $this->assertCount(2, $pages);
        $this->assertEquals(1, $pages[0]['page_number']);
        $this->assertStringContainsString('halaman satu', $pages[0]['page_content']);
        $this->assertEquals(2, $pages[1]['page_number']);
        $this->assertStringContainsString('halaman dua', $pages[1]['page_content']);
        foreach ($pages as $page) {
            $this->assertEquals('ocr_vision', $page['source']);
        }
    }

    public function test_pdf_parser_handle_empty_content_throws_when_ocr_disabled(): void
    {
        config(['ai.ocr.enabled' => false]);

        $parser = new \App\Services\Document\Parsing\PdfParser();

        $reflection = new \ReflectionClass($parser);
        $method = $reflection->getMethod('handleEmptyContent');
        $method->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OCR is disabled');

        $method->invoke($parser, '/tmp/non-existent.pdf');
    }
}