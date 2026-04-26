<?php

namespace App\Services\Document\Parsing;

interface DocumentParserInterface
{
    public function parse(string $filePath): array;
    public function supports(string $mimeType): bool;
}