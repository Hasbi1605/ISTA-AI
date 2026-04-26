<?php

namespace App\Services\Document\Parsing;

use PhpOffice\PhpWord\IOFactory;

class DocxParser implements DocumentParserInterface
{
    protected array $pages = [];

    public function parse(string $filePath): array
    {
        $this->pages = [];
        
        try {
            $phpWord = IOFactory::load($filePath, 'Word2007');
            
            $sections = $phpWord->getSections();
            $pageNumber = 1;
            
            foreach ($sections as $section) {
                $elements = $section->getElements();
                
                $content = [];
                foreach ($elements as $element) {
                    $text = $this->extractTextFromElement($element);
                    if (!empty(trim($text))) {
                        $content[] = $text;
                    }
                }
                
                if (!empty($content)) {
                    $this->pages[] = [
                        'page_content' => implode("\n\n", $content),
                        'page_number' => $pageNumber++,
                        'source' => 'docx',
                    ];
                }
            }

            if (empty($this->pages)) {
                throw new \RuntimeException('DOCX file is empty or contains no text');
            }
            
        } catch (\Exception $e) {
            throw $e;
        }
        
        return $this->pages;
    }

    public function supports(string $mimeType): bool
    {
        return in_array($mimeType, [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-word.document.macroEnabled.12',
            'word/documentml',
        ]);
    }

    protected function extractTextFromElement($element): string
    {
        $className = (new \ReflectionClass($element))->getShortName();
        
        switch ($className) {
            case 'Text':
                return $element->getText() ?? '';
                
            case 'TextRun':
                $texts = [];
                foreach ($element->getElements() as $child) {
                    $texts[] = $this->extractTextFromElement($child);
                }
                return implode('', $texts);
                
            case 'Paragraph':
                $texts = [];
                foreach ($element->getElements() as $child) {
                    $texts[] = $this->extractTextFromElement($child);
                }
                return implode('', $texts);
                
            case 'Table':
                $rows = [];
                foreach ($element->getRows() as $row) {
                    $cellTexts = [];
                    foreach ($row->getCells() as $cell) {
                        $cellContent = [];
                        foreach ($cell->getElements() as $cellElement) {
                            $cellContent[] = $this->extractTextFromElement($cellElement);
                        }
                        $cellTexts[] = implode(' | ', array_filter($cellContent));
                    }
                    $rows[] = implode(' | ', array_filter($cellTexts));
                }
                return implode("\n", array_filter($rows));
                
            default:
                return '';
        }
    }
}