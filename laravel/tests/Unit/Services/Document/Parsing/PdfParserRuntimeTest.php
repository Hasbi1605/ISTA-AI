<?php

namespace Tests\Unit\Services\Document\Parsing;

use App\Services\Document\Parsing\PdfParser;
use Tests\TestCase;

class PdfParserRuntimeTest extends TestCase
{
    private string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixturePath = sys_get_temp_dir() . '/ista-pdf-parser-runtime-' . uniqid() . '.pdf';
        $this->writeMinimalPdf($this->fixturePath, 'ISTA AI runtime PDF parser smoke test marker XYZ-9999.');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->fixturePath)) {
            @unlink($this->fixturePath);
        }

        parent::tearDown();
    }

    public function test_pdf_parser_extracts_text_from_real_pdf_at_runtime(): void
    {
        $parser = new PdfParser();
        $pages = $parser->parse($this->fixturePath);

        $this->assertNotEmpty($pages, 'Parser must produce at least one page');

        $combined = '';
        foreach ($pages as $page) {
            $this->assertArrayHasKey('page_content', $page);
            $this->assertArrayHasKey('page_number', $page);
            $combined .= ' ' . $page['page_content'];
        }

        $this->assertStringContainsString('XYZ-9999', $combined, 'Parser should expose unique marker text');
    }

    /**
     * Writes a minimal single-page PDF that smalot/pdfparser can decode.
     * Hand-rolled to avoid pulling in another PDF generation dependency.
     */
    private function writeMinimalPdf(string $path, string $text): void
    {
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        $stream = "BT\n/F1 12 Tf\n72 720 Td\n({$escaped}) Tj\nET\n";

        $objects = [];
        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objects[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
        $objects[3] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>";
        $objects[4] = "<< /Length " . strlen($stream) . " >>\nstream\n{$stream}endstream";
        $objects[5] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";

        $offsets = [];
        $output = "%PDF-1.4\n";

        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($output);
            $output .= "{$id} 0 obj\n{$body}\nendobj\n";
        }

        $xrefOffset = strlen($output);
        $output .= "xref\n0 " . (count($objects) + 1) . "\n";
        $output .= "0000000000 65535 f \n";
        foreach ($objects as $id => $_body) {
            $output .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }
        $output .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF\n";

        file_put_contents($path, $output);
    }
}
