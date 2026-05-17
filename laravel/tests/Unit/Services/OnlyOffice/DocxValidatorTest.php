<?php

namespace Tests\Unit\Services\OnlyOffice;

use App\Services\OnlyOffice\DocxValidator;
use RuntimeException;
use Tests\TestCase;

class DocxValidatorTest extends TestCase
{
    public function test_accepts_valid_docx_bytes(): void
    {
        app(DocxValidator::class)->assertValidBytes($this->validMemoDocxBytes(), 'Fixture DOCX');

        $this->assertTrue(true);
    }

    public function test_rejects_zip_prefixed_corrupt_bytes(): void
    {
        $this->expectException(RuntimeException::class);

        app(DocxValidator::class)->assertValidBytes("PK\x03\x04corrupt", 'Corrupt DOCX');
    }

    public function test_rejects_zip_without_docx_entries(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'not-docx-');
        $this->assertIsString($path);

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path, \ZipArchive::OVERWRITE));
        $zip->addFromString('plain.txt', 'not a docx');
        $zip->close();

        try {
            $this->expectException(RuntimeException::class);

            app(DocxValidator::class)->assertValidPath($path, 'Not DOCX');
        } finally {
            @unlink($path);
        }
    }
}
