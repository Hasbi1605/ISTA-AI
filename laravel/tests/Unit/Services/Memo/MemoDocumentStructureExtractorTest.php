<?php

namespace Tests\Unit\Services\Memo;

use App\Services\Memo\MemoDocumentStructureExtractor;
use Tests\TestCase;

class MemoDocumentStructureExtractorTest extends TestCase
{
    public function test_extracts_body_without_split_metadata_and_signature_artifacts(): void
    {
        $text = implode("\n", [
            'KEMENTERIAN SEKRETARIAT NEGARA RI',
            'SEKRETARIAT PRESIDEN',
            'ISTANA KEPRESIDENAN YOGYAKARTA',
            'MEMORANDUM',
            'Nomor EVAL-08/IST/YK/05/2026',
            'Yth.',
            ':',
            'Koordinator TI',
            'Dari',
            ':',
            'Kepala Istana Kepresidenan Yogyakarta',
            'Hal',
            ':',
            'Penyampaian Kendala Akses Sistem Persuratan',
            'Tanggal',
            ':',
            '7 Mei 2026',
            'Waktu kejadian ditetapkan berdasarkan laporan unit terkait.',
            'Demikian disampaikan untuk menjadi perhatian.',
            'Ngetes Perubahan Manual doang...HEhe...',
            'QRTTE',
            'Deni Mulyana',
            'Tembusan:',
            'Kepala Bagian Keamanan',
        ]);

        $body = app(MemoDocumentStructureExtractor::class)->bodyFromSearchableText($text, [
            'number' => 'EVAL-08/IST/YK/05/2026',
            'recipient' => 'Koordinator TI',
            'sender' => 'Kepala Istana Kepresidenan Yogyakarta',
            'subject' => 'Penyampaian Kendala Akses Sistem Persuratan',
            'date' => '7 Mei 2026',
            'signatory' => 'Deni Mulyana',
        ]);

        $this->assertSame(
            "Waktu kejadian ditetapkan berdasarkan laporan unit terkait.\nNgetes Perubahan Manual doang...HEhe...",
            $body,
        );
    }

    public function test_preserves_body_lines_that_only_start_with_metadata_words(): void
    {
        $text = implode("\n", [
            'MEMORANDUM',
            'Yth.',
            ':',
            'Koordinator TI',
            'Hal',
            ':',
            'Evaluasi Layanan',
            'Hal ini perlu ditindaklanjuti oleh unit terkait.',
            'Dari hasil evaluasi, akses pengguna perlu diperbaiki.',
            'Tanggal pelaksanaan tindak lanjut ditetapkan setelah koordinasi.',
            'QRTTE',
        ]);

        $body = app(MemoDocumentStructureExtractor::class)->bodyFromSearchableText($text, [
            'recipient' => 'Koordinator TI',
            'subject' => 'Evaluasi Layanan',
        ]);

        $this->assertSame(
            implode("\n", [
                'Hal ini perlu ditindaklanjuti oleh unit terkait.',
                'Dari hasil evaluasi, akses pengguna perlu diperbaiki.',
                'Tanggal pelaksanaan tindak lanjut ditetapkan setelah koordinasi.',
            ]),
            $body,
        );
    }
}
