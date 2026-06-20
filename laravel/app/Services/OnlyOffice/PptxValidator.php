<?php

namespace App\Services\OnlyOffice;

use RuntimeException;
use ZipArchive;

/**
 * Validasi file PPTX (OOXML presentation) dari OnlyOffice sebelum disimpan,
 * setara DocxValidator untuk memo. Memastikan body benar-benar arsip OOXML
 * presentasi dan bukan HTML/error yang dikira PPTX.
 */
class PptxValidator
{
    private const MIN_PPTX_BYTES = 4;

    private const MAX_PPTX_BYTES = 100 * 1024 * 1024;

    public function assertValidPath(string $path, string $label = 'PPTX'): void
    {
        if (! is_file($path)) {
            throw new RuntimeException($label.' tidak ditemukan.');
        }

        $size = filesize($path);

        if ($size === false || $size < self::MIN_PPTX_BYTES) {
            throw new RuntimeException($label.' terlalu kecil untuk PPTX valid.');
        }

        if ($size > self::MAX_PPTX_BYTES) {
            throw new RuntimeException($label.' melebihi batas ukuran maksimum.');
        }

        $zip = new ZipArchive;
        $opened = $zip->open($path);

        if ($opened !== true) {
            throw new RuntimeException($label.' bukan arsip ZIP/PPTX yang valid.');
        }

        try {
            foreach (['[Content_Types].xml', 'ppt/presentation.xml'] as $requiredEntry) {
                if ($zip->locateName($requiredEntry) === false) {
                    throw new RuntimeException($label.' tidak memiliki struktur PPTX wajib: '.$requiredEntry.'.');
                }
            }
        } finally {
            $zip->close();
        }
    }
}
