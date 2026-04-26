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
}