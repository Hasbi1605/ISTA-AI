<?php

namespace App\Services\OnlyOffice;

use RuntimeException;
use ZipArchive;

class DocxValidator
{
    private const MIN_DOCX_BYTES = 4;

    private const MAX_DOCX_BYTES = 50 * 1024 * 1024;

    public function assertValidBytes(string $body, string $label = 'DOCX'): void
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'ista-docx-');

        if ($tempPath === false) {
            throw new RuntimeException('Gagal membuat file sementara untuk validasi '.$label.'.');
        }

        try {
            if (file_put_contents($tempPath, $body) === false) {
                throw new RuntimeException('Gagal menulis file sementara untuk validasi '.$label.'.');
            }

            $this->assertValidPath($tempPath, $label);
        } finally {
            @unlink($tempPath);
        }
    }

    public function assertValidPath(string $path, string $label = 'DOCX'): void
    {
        if (! is_file($path)) {
            throw new RuntimeException($label.' tidak ditemukan.');
        }

        $size = filesize($path);

        if ($size === false || $size < self::MIN_DOCX_BYTES) {
            throw new RuntimeException($label.' terlalu kecil untuk DOCX valid.');
        }

        if ($size > self::MAX_DOCX_BYTES) {
            throw new RuntimeException($label.' melebihi batas ukuran maksimum.');
        }

        $zip = new ZipArchive;
        $opened = $zip->open($path);

        if ($opened !== true) {
            throw new RuntimeException($label.' bukan arsip ZIP/DOCX yang valid.');
        }

        try {
            foreach (['[Content_Types].xml', 'word/document.xml'] as $requiredEntry) {
                if ($zip->locateName($requiredEntry) === false) {
                    throw new RuntimeException($label.' tidak memiliki struktur DOCX wajib: '.$requiredEntry.'.');
                }
            }
        } finally {
            $zip->close();
        }
    }
}
