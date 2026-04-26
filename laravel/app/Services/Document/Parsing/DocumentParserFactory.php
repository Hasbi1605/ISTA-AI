<?php

namespace App\Services\Document\Parsing;

class DocumentParserFactory
{
    protected array $parsers = [];

    public function __construct()
    {
        $this->parsers = [
            new PdfParser(),
            new DocxParser(),
            new SpreadsheetParser(),
        ];
    }

    public function getParser(string $filePath): ?DocumentParserInterface
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimeType = $this->detectMimeType($filePath, $extension);

        foreach ($this->parsers as $parser) {
            if ($parser->supports($mimeType)) {
                return $parser;
            }
        }

        return null;
    }

    public function parse(string $filePath): array
    {
        $parser = $this->getParser($filePath);
        
        if ($parser === null) {
            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            throw new \RuntimeException("Unsupported file format: {$extension}");
        }
        
        return $parser->parse($filePath);
    }

    public function supports(string $filePath): bool
    {
        return $this->getParser($filePath) !== null;
    }

    protected function detectMimeType(string $filePath, string $extension): string
    {
        return match ($extension) {
            'pdf' => 'application/pdf',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'doc' => 'application/vnd.ms-word.document.macroEnabled.12',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xls' => 'application/vnd.ms-excel',
            'csv' => 'text/csv',
            default => 'application/octet-stream',
        };
    }
}