<?php

namespace App\Services\Memo;

class MemoDocumentStructureExtractor
{
    /**
     * @param  array<string, mixed>  $configuration
     */
    public function bodyFromSearchableText(string $searchableText, array $configuration = []): string
    {
        $lines = preg_split('/\R+/', $searchableText) ?: [];
        $bodyLines = [];
        $skipMetadataValue = false;

        foreach ($lines as $line) {
            $line = trim((string) $line);

            if ($line === '') {
                continue;
            }

            if ($skipMetadataValue) {
                if ($this->isMetadataSeparator($line)) {
                    continue;
                }

                $skipMetadataValue = false;

                continue;
            }

            if ($this->isOfficialMemoStructureLine($line, $configuration, $skipMetadataValue)) {
                continue;
            }

            if (preg_match('/^Tembusan\s*:/iu', $line)) {
                break;
            }

            if ($this->isSignatureArtifactLine($line, $configuration)) {
                break;
            }

            if ($this->isClosingLine($line, $configuration)) {
                continue;
            }

            $bodyLines[] = $line;
        }

        return trim(implode("\n", $bodyLines));
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    protected function isOfficialMemoStructureLine(string $line, array $configuration, bool &$skipMetadataValue): bool
    {
        $normalized = mb_strtolower(trim($line));

        $exactLines = [
            'kementerian sekretariat negara ri',
            'sekretariat presiden',
            'istana kepresidenan yogyakarta',
            'memorandum',
            'dokumen ini telah ditandatangani secara elektronik menggunakan sertifikat elektronik',
            'yang diterbitkan oleh balai sertifikasi elektronik (bsre).',
        ];

        if (in_array($normalized, $exactLines, true)) {
            return true;
        }

        if ($this->isMetadataSeparator($line) || preg_match('/^[\s\-_—=]{3,}$/u', $line) === 1) {
            return true;
        }

        if (preg_match('/^nomor\s+(.+)$/iu', $line, $matches) === 1) {
            $number = trim((string) ($configuration['number'] ?? ''));
            $value = trim((string) $matches[1]);

            return $number !== ''
                ? $this->normalizeComparisonText($value) === $this->normalizeComparisonText($number)
                : preg_match('/[\/-]/u', $value) === 1;
        }

        if (preg_match('/^(yth\.?|dari|hal|tanggal)(?:\s*:?\s*$|\s*:\s*(.*)$)/iu', $line, $matches) === 1) {
            $value = trim((string) ($matches[2] ?? ''));
            $skipMetadataValue = $value === '';

            return true;
        }

        return $this->isKnownMetadataValue($line, $configuration);
    }

    protected function isMetadataSeparator(string $line): bool
    {
        return trim($line) === ':';
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    protected function isKnownMetadataValue(string $line, array $configuration): bool
    {
        $metadataValues = [
            $configuration['number'] ?? null,
            $configuration['recipient'] ?? null,
            $configuration['sender'] ?? null,
            $configuration['subject'] ?? null,
            $configuration['date'] ?? null,
        ];

        $normalizedLine = $this->normalizeComparisonText($line);

        foreach ($metadataValues as $value) {
            if ($value === null || trim((string) $value) === '') {
                continue;
            }

            if ($normalizedLine === $this->normalizeComparisonText((string) $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    protected function isSignatureArtifactLine(string $line, array $configuration): bool
    {
        $normalized = $this->normalizeComparisonText($line);

        if (in_array($normalized, ['qr', 'tte', 'qrtte'], true)) {
            return true;
        }

        $signatory = trim((string) ($configuration['signatory'] ?? ''));

        return $signatory !== ''
            && $normalized === $this->normalizeComparisonText($signatory);
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    protected function isClosingLine(string $line, array $configuration): bool
    {
        $closing = trim((string) ($configuration['closing'] ?? ''));

        if ($closing !== '' && $this->normalizeComparisonText($line) === $this->normalizeComparisonText($closing)) {
            return true;
        }

        return preg_match('/^(?:demikian|atas perhatian|atas kerja sama)\b/iu', $line) === 1;
    }

    protected function normalizeComparisonText(string $text): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text));
    }
}
