<?php

namespace Tests\Unit\Services\Document;

use App\Services\Document\HydeQueryExpansionService;
use App\Services\Document\DocumentPolicyService;
use ReflectionClass;
use PHPUnit\Framework\TestCase;

class HydeQueryExpansionServiceTest extends TestCase
{
    private HydeQueryExpansionService $hydeService;

    private function getPrivateProperty(object $object, string $property): mixed
    {
        $reflection = new ReflectionClass($object);
        $property = $reflection->getProperty($property);
        $property->setAccessible(true);
        return $property->getValue($object);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->hydeService = new HydeQueryExpansionService([
            'enabled' => true,
            'mode' => 'smart',
            'timeout' => 5,
            'max_tokens' => 100,
            'cascade_nodes' => [],
        ]);
    }

    public function test_hyde_disabled_returns_false(): void
    {
        $service = new HydeQueryExpansionService([
            'enabled' => false,
            'mode' => 'smart',
            'timeout' => 5,
            'max_tokens' => 100,
            'cascade_nodes' => [],
        ]);

        list($shouldUse, $reason) = $service->shouldUseHyde('mengapa inflasi terjadi di indonesia');
        $this->assertFalse($shouldUse);
        $this->assertEquals('hyde_disabled', $reason);
    }

    public function test_short_query_returns_false(): void
    {
        list($shouldUse, $reason) = $this->hydeService->shouldUseHyde('apa itu ai');
        $this->assertFalse($shouldUse);
        $this->assertStringContainsString('terlalu pendek', $reason);
    }

    public function test_skip_patterns_for_summarization(): void
    {
        $skipQueries = [
            'rangkum dokumen ini',
            'buat ringkasan dari laporan',
            'ringkaskan isi file',
            'baca dokumen pdf',
            'apa isi dari 文件 ini',
            'tampilkan isi lengkap',
            'sebutkan poin-poin penting',
            'halo apakah ada info',
            'hai ada yang bisa bantu',
        ];

        foreach ($skipQueries as $query) {
            list($shouldUse, $reason) = $this->hydeService->shouldUseHyde($query);
            $this->assertFalse(
                $shouldUse,
                "Query '{$query}' harusnya di-skip, tapi returned: {$reason}"
            );
        }
    }

    public function test_concept_patterns_trigger_hyde(): void
    {
        $conceptQueries = [
            'mengapa inflation terjadi di indonesia saat ini',
            'kenapa teknologi penting untuk masa depan bangsa',
            'bagaimana cara membuat resume yang baik untuk fresh graduate',
            'apa hubungan antara education dan economy dalam pembangunan',
            'apa perbedaan democracy dan republic dalam sistem pemerintahan',
            'apa yang dimaksud dengan machine learning dalam teknologi',
            'jelaskan konsep photosynthesis pada tanaman hijau',
            'jelaskan teori relativity sederhana dari einstein',
            'analisis dampak perubahan iklim global terhadap laut',
        ];

        foreach ($conceptQueries as $query) {
            list($shouldUse, $reason) = $this->hydeService->shouldUseHyde($query);
            $this->assertTrue(
                $shouldUse,
                "Query konseptual '{$query}' harusnya trigger HyDE, tapi: {$reason}"
            );
        }
    }

    public function test_long_query_with_question_mark_triggers_hyde(): void
    {
        $query = 'bagaimana dampak positif dan negatif dari penggunaan energi fossil terhadap lingkungan hidup dan ekonomi masyarakat indonesia dalam jangka panjang';
        
        list($shouldUse, $reason) = $this->hydeService->shouldUseHyde($query);
        $this->assertTrue($shouldUse);
    }

    public function test_stable_concepts_no_hyde(): void
    {
        $stableQueries = [
            'apa definisi democracy',
            'siapa penemu mesin uap',
            'kapan perang dunia kedua dimulai',
            'dimana lokasi kota rome',
        ];

        foreach ($stableQueries as $query) {
            list($shouldUse, $reason) = $this->hydeService->shouldUseHyde($query);
            $this->assertFalse(
                $shouldUse,
                "Query stabil '{$query}' seharusnya tidak trigger HyDE"
            );
        }
    }

    public function test_is_enabled_returns_correct_value(): void
    {
        $enabledService = new HydeQueryExpansionService([
            'enabled' => true,
            'mode' => 'smart',
            'timeout' => 5,
            'max_tokens' => 100,
            'cascade_nodes' => [],
        ]);
        
        $disabledService = new HydeQueryExpansionService([
            'enabled' => false,
            'mode' => 'smart',
            'timeout' => 5,
            'max_tokens' => 100,
            'cascade_nodes' => [],
        ]);

        $this->assertTrue($enabledService->isEnabled());
        $this->assertFalse($disabledService->isEnabled());
    }

    public function test_get_mode_returns_correct_value(): void
    {
        $smartService = new HydeQueryExpansionService([
            'enabled' => true,
            'mode' => 'smart',
            'timeout' => 5,
            'max_tokens' => 100,
            'cascade_nodes' => [],
        ]);

        $alwaysService = new HydeQueryExpansionService([
            'enabled' => true,
            'mode' => 'always',
            'timeout' => 5,
            'max_tokens' => 100,
            'cascade_nodes' => [],
        ]);

        $this->assertEquals('smart', $smartService->getMode());
        $this->assertEquals('always', $alwaysService->getMode());
    }

    public function test_empty_cascade_nodes_returns_original_query(): void
    {
        $service = new HydeQueryExpansionService([
            'enabled' => true,
            'mode' => 'smart',
            'timeout' => 5,
            'max_tokens' => 100,
            'cascade_nodes' => [],
        ]);
        
        $result = $service->generateEnhancedQuery('mengapa inflation terjadi');
        
        $this->assertEquals('mengapa inflation terjadi', $result);
    }

    public function test_short_query_returns_original(): void
    {
        $result = $this->hydeService->generateEnhancedQuery('apa itu');
        
        $this->assertEquals('apa itu', $result);
    }

    public function test_returns_full_query_on_hyde_success_with_long_input(): void
    {
        $longQuery = 'mengapa inflasi terjadi di indonesia saat ini sangat mempengaruhi ekonomi masyarakat kecil '
            . 'dan bagaimana dampak perubahan harga barang kebutuhan pokok terhadap daya beli rakyat '
            . 'serta apa langkah pemerintah yang seharusnya diambil untuk mengatasi kenaikan biaya hidup '
            . 'yang terus meningkat setiap tahunnya akibat dari policy monetary yang diterapkan '
            . 'oleh bank central dalam mengatur jumlah uang yang beredar di pasar domestik dan bagaimana '
            . 'pengaruh tingkat suku bunga terhadap investasi asing yang masuk ke dalam negeri serta '
            . 'apa yang bisa dilakukan untuk menstabilkan nilai tukar rupiah terhadap mata uang asing '
            . 'terutama dolar amerika Serikat yang menjadi patokan utama dalam perdagangan internasional '
            . 'dan bagaimana hal ini berdampak pada ekspor produk lokal Indonesia ke pasar global saat ini.';

        $this->assertGreaterThan(500, strlen($longQuery));

        $service = new class([
            'enabled' => true,
            'mode' => 'smart',
            'timeout' => 5,
            'max_tokens' => 100,
            'cascade_nodes' => [
                ['model' => 'test-model', 'provider' => 'openai', 'api_key' => 'test-key']
            ],
        ]) extends HydeQueryExpansionService
        {
            protected function generateWithNode(array $node, string $query, string $originalQuery): string
            {
                return $originalQuery . ' Hipotesis jawaban faktual yang menunjukkan hubungan causality.';
            }
        };

        $result = $service->generateEnhancedQuery($longQuery);

        $this->assertStringContainsString($longQuery, $result);
        $this->assertGreaterThan(500, strlen($result));
        $this->assertStringContainsString('Hipotesis jawaban faktual', $result);
        $this->assertNotEquals($longQuery, $result, 'Result harus berbeda dari originalQuery karena success-path harus dijalankan');
    }
}