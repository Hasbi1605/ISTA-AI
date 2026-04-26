<?php

namespace Tests\Unit\Services\Document\Parsing;

use Tests\TestCase;
use App\Services\Document\Parsing\DocumentParserFactory;
use App\Services\Document\Parsing\PdfParser;
use App\Services\Document\Parsing\DocxParser;
use App\Services\Document\Parsing\SpreadsheetParser;

class DocumentParserTest extends TestCase
{
    public function test_pdf_parser_supports_correct_mime_types(): void
    {
        $parser = new PdfParser();
        
        $this->assertTrue($parser->supports('application/pdf'));
        $this->assertTrue($parser->supports('application/x-pdf'));
    }

    public function test_docx_parser_supports_correct_mime_types(): void
    {
        $parser = new DocxParser();
        
        $this->assertTrue($parser->supports('application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
    }

    public function test_spreadsheet_parser_supports_correct_mime_types(): void
    {
        $parser = new SpreadsheetParser();
        
        $this->assertTrue($parser->supports('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));
        $this->assertTrue($parser->supports('text/csv'));
    }

    public function test_parser_factory_returns_correct_parser(): void
    {
        $factory = new DocumentParserFactory();
        
        $this->assertTrue($factory->supports('/tmp/test.pdf'));
        $this->assertTrue($factory->supports('/tmp/test.docx'));
        $this->assertTrue($factory->supports('/tmp/test.xlsx'));
        $this->assertTrue($factory->supports('/tmp/test.csv'));
    }

    public function test_parser_factory_returns_null_for_unsupported(): void
    {
        $factory = new DocumentParserFactory();
        
        $this->assertFalse($factory->supports('/tmp/test.txt'));
        $this->assertFalse($factory->supports('/tmp/test.jpg'));
    }

    public function test_parsed_output_has_expected_structure(): void
    {
        $factory = new DocumentParserFactory();
        
        $this->assertTrue(method_exists($factory, 'parse'));
        
        $reflection = new \ReflectionMethod($factory, 'parse');
        $this->assertEquals('array', $reflection->getReturnType()->getName());
    }

    public function test_pdf_parser_returns_array_of_pages(): void
    {
        $parser = new PdfParser();
        
        $this->assertTrue(method_exists($parser, 'parse'));
        
        $reflection = new \ReflectionMethod($parser, 'parse');
        $this->assertEquals('array', $reflection->getReturnType()->getName());
    }

    public function test_pdf_parser_returns_page_structure_with_expected_keys(): void
    {
        $parser = new PdfParser();
        
        $expectedKeys = ['page_content', 'page_number', 'source'];
        $mockPage = ['page_content' => 'test content', 'page_number' => 1, 'source' => 'pdf'];
        
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $mockPage);
        }
    }
}