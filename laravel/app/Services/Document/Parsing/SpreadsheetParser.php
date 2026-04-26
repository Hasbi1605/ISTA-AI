<?php

namespace App\Services\Document\Parsing;

use PhpOffice\PhpSpreadsheet\IOFactory;

class SpreadsheetParser implements DocumentParserInterface
{
    protected array $pages = [];

    public function parse(string $filePath): array
    {
        $this->pages = [];
        
        try {
            $spreadsheet = IOFactory::load($filePath);
            
            $sheetCount = $spreadsheet->getSheetCount();
            
            for ($i = 0; $i < $sheetCount; $i++) {
                $sheet = $spreadsheet->getSheet($i);
                $sheetName = $sheet->getTitle() ?? "Sheet " . ($i + 1);
                
                $rows = $sheet->toArray(null, true, true, true);
                
                if (empty($rows)) {
                    continue;
                }
                
                $content = $this->formatAsText($rows);
                
                if (!empty(trim($content))) {
                    $this->pages[] = [
                        'page_content' => $content,
                        'page_number' => $i + 1,
                        'source' => 'spreadsheet',
                        'sheet_name' => $sheetName,
                    ];
                }
            }

            if (empty($this->pages)) {
                throw new \RuntimeException('Spreadsheet file is empty or contains no data');
            }
            
        } catch (\Exception $e) {
            throw $e;
        }
        
        return $this->pages;
    }

    public function supports(string $mimeType): bool
    {
        return in_array($mimeType, [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel.sheet.macroEnabled.12',
            'application/vnd.ms-excel',
            'text/csv',
            'application/csv',
            'text/comma-separated-values',
        ]);
    }

    protected function formatAsText(array $rows): string
    {
        $lines = [];
        
        foreach ($rows as $rowData) {
            $rowContent = [];
            foreach ($rowData as $cell) {
                $cellValue = $this->formatCellValue($cell);
                if ($cellValue !== '') {
                    $rowContent[] = $cellValue;
                }
            }
            
            if (!empty($rowContent)) {
                $lines[] = implode(' | ', $rowContent);
            }
        }
        
        return implode("\n", $lines);
    }

    protected function formatCellValue($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        
        if (is_numeric($value) && !is_string($value)) {
            if ($value == floor($value)) {
                return (string) (int) $value;
            }
            return (string) round($value, 2);
        }
        
        return (string) $value;
    }
}