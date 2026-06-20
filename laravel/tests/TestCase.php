<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function validMemoDocxBytes(): string
    {
        $content = file_get_contents(base_path('tests/Fixtures/edited-memo.docx'));

        if ($content === false) {
            throw new \RuntimeException('Fixture DOCX memo tidak dapat dibaca.');
        }

        return $content;
    }

    /**
     * Build a minimal but structurally valid PPTX (OOXML presentation) archive
     * in-memory for OnlyOffice Slides callback tests. Contains the entries
     * required by PptxValidator without depending on a binary fixture.
     */
    protected function validPresentationPptxBytes(): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'ista-test-pptx-');

        if ($tempPath === false) {
            throw new \RuntimeException('Gagal membuat file PPTX sementara untuk test.');
        }

        $zip = new \ZipArchive;
        if ($zip->open($tempPath, \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Gagal membuka arsip PPTX test.');
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>'
            .'</Types>');
        $zip->addFromString('ppt/presentation.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"/>');
        $zip->close();

        $content = file_get_contents($tempPath);
        @unlink($tempPath);

        if ($content === false) {
            throw new \RuntimeException('Fixture PPTX presentasi tidak dapat dibaca.');
        }

        return $content;
    }
}
