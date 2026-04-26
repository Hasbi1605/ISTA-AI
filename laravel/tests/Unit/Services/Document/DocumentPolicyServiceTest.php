<?php

namespace Tests\Unit\Services\Document;

use App\Services\Document\DocumentPolicyService;
use PHPUnit\Framework\TestCase;

class DocumentPolicyServiceTest extends TestCase
{
    private DocumentPolicyService $policyService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policyService = new DocumentPolicyService();
    }

    public function test_explicit_web_request_detected(): void
    {
        $queries = [
            'cari di web tentang resep masakan',
            'pakai internet untuk mencari info ini',
            'web search berita hari ini',
            'browse web untuk jadwal',
            'search online harga tiket',
        ];

        foreach ($queries as $query) {
            $this->assertTrue(
                $this->policyService->detectExplicitWebRequest($query),
                "Query '{$query}' harus terdeteksi sebagai explicit web request"
            );
        }
    }

    public function test_non_explicit_web_request_not_detected(): void
    {
        $queries = [
            'apa itu machine learning',
            'jelaskan cuaca hari ini',
            'kurs dollar',
        ];

        foreach ($queries as $query) {
            $this->assertFalse(
                $this->policyService->detectExplicitWebRequest($query),
                "Query '{$query}' seharusnya bukan explicit web request"
            );
        }
    }

    public function test_documents_active_blocks_web(): void
    {
        $queries = [
            'kurs dollar sekarang',
            'cuaca hari ini',
            'berita terbaru indonesia',
        ];

        foreach ($queries as $query) {
            $result = $this->policyService->shouldUseWebSearch(
                query: $query,
                forceWebSearch: false,
                explicitWebRequest: false,
                allowAutoRealtimeWeb: true,
                documentsActive: true
            );

            $this->assertFalse(
                $result['should_search'],
                "Query '{$query}' dengan docs aktif harus MATI web, tapi result: " . json_encode($result)
            );
            $this->assertEquals(
                'DOC_NO_WEB',
                $result['reason_code'],
                "Reason code harus DOC_NO_WEB untuk query '{$query}'"
            );
        }
    }

    public function test_force_web_always_enabled(): void
    {
        $result = $this->policyService->shouldUseWebSearch(
            query: 'apa itu python',
            forceWebSearch: true,
            explicitWebRequest: false,
            allowAutoRealtimeWeb: true,
            documentsActive: false
        );

        $this->assertTrue($result['should_search']);
        $this->assertStringContainsString('TOGGLE', $result['reason_code']);
    }

    public function test_explicit_web_request_triggers_search(): void
    {
        $result = $this->policyService->shouldUseWebSearch(
            query: 'cari di web tentang inflasi',
            forceWebSearch: false,
            explicitWebRequest: false,
            allowAutoRealtimeWeb: true,
            documentsActive: false
        );

        $this->assertTrue($result['should_search']);
        $this->assertEquals('EXPLICIT_WEB', $result['reason_code']);
    }

    public function test_high_realtime_intent_triggers_web(): void
    {
        $queries = [
            'kurs dollar sekarang',
            'cuaca hari ini jakarta',
            'berita terbaru indonesia',
            'harga saham hari ini',
        ];

        foreach ($queries as $query) {
            $result = $this->policyService->shouldUseWebSearch(
                query: $query,
                forceWebSearch: false,
                explicitWebRequest: false,
                allowAutoRealtimeWeb: true,
                documentsActive: false
            );

            $this->assertTrue(
                $result['should_search'],
                "Query '{$query}' harus trigger web search dengan high intent"
            );
            $this->assertStringContainsString('REALTIME', $result['reason_code']);
        }
    }

    public function test_low_intent_no_web(): void
    {
        $queries = [
            'apa itu fotosintesis',
            'jelaskan teori relativitas',
            'siapa albert einstein',
        ];

        foreach ($queries as $query) {
            $result = $this->policyService->shouldUseWebSearch(
                query: $query,
                forceWebSearch: false,
                explicitWebRequest: false,
                allowAutoRealtimeWeb: true,
                documentsActive: false
            );

            $this->assertFalse($result['should_search']);
            $this->assertEquals('NO_WEB', $result['reason_code']);
        }
    }

    public function test_realtime_intent_level_returns_high_for_realtime_queries(): void
    {
        $highQueries = [
            'kurs dollar sekarang',
            'cuaca hari ini jakarta',
            'berita terbaru indonesia',
            'berita terkini',
            'harga saham hari ini',
        ];

        foreach ($highQueries as $query) {
            $intent = $this->policyService->detectRealtimeIntentLevel($query);
            $this->assertEquals(
                'high',
                $intent,
                "Query '{$query}' harus memiliki intent level 'high', got '{$intent}'"
            );
        }
    }

    public function test_realtime_intent_level_returns_low_for_stable_queries(): void
    {
        $lowQueries = [
            'apa itu fotosintesis',
            'jelaskan teori relativitas',
            'siapa albert einstein',
            'cara membuat kue',
            'pengertian demokrasi',
        ];

        foreach ($lowQueries as $query) {
            $intent = $this->policyService->detectRealtimeIntentLevel($query);
            $this->assertEquals(
                'low',
                $intent,
                "Query '{$query}' harus memiliki intent level 'low', got '{$intent}'"
            );
        }
    }

    public function test_no_answer_prompt_is_user_facing(): void
    {
        $prompt = $this->policyService->getNoAnswerPrompt();

        $this->assertNotEmpty($prompt);
        $this->assertStringContainsString('belum menemukan', $prompt);
    }

    public function test_document_error_prompt_is_user_facing(): void
    {
        $prompt = $this->policyService->getDocumentErrorPrompt();

        $this->assertNotEmpty($prompt);
        $this->assertStringContainsString('belum bisa membaca', $prompt);
    }

    public function test_explicit_web_works_when_documents_active(): void
    {
        $queries = [
            'cari di web tentang cuaca jogja',
            'pakai internet untuk cek berita terbaru',
            'web search info ini',
        ];

        foreach ($queries as $query) {
            $result = $this->policyService->shouldUseWebSearch(
                query: $query,
                forceWebSearch: false,
                explicitWebRequest: false,
                allowAutoRealtimeWeb: true,
                documentsActive: true
            );

            $this->assertTrue(
                $result['should_search'],
                "Query '{$query}' dengan docs aktif + explicit web HARUS trigger web"
            );
            $this->assertEquals(
                'DOC_WEB_EXPLICIT',
                $result['reason_code'],
                "Reason code harus DOC_WEB_EXPLICIT untuk query '{$query}'"
            );
        }
    }

    public function test_realtime_auto_not_triggered_when_documents_active(): void
    {
        $queries = [
            'cuaca hari ini jogja',
            'berita terbaru indonesia',
            'harga saham sekarang',
        ];

        foreach ($queries as $query) {
            $result = $this->policyService->shouldUseWebSearch(
                query: $query,
                forceWebSearch: false,
                explicitWebRequest: false,
                allowAutoRealtimeWeb: true,
                documentsActive: true
            );

            $this->assertFalse(
                $result['should_search'],
                "Query realtime '{$query}' dengan docs aktif TIDAK boleh trigger web"
            );
            $this->assertEquals(
                'DOC_NO_WEB',
                $result['reason_code'],
                "Reason code harus DOC_NO_WEB untuk '{$query}'"
            );
        }
    }

    public function test_realtime_auto_triggered_without_documents(): void
    {
        $queries = [
            'cuaca hari ini jogja',
            'berita terbaru indonesia',
            'harga saham sekarang',
        ];

        foreach ($queries as $query) {
            $result = $this->policyService->shouldUseWebSearch(
                query: $query,
                forceWebSearch: false,
                explicitWebRequest: false,
                allowAutoRealtimeWeb: true,
                documentsActive: false
            );

            $this->assertTrue(
                $result['should_search'],
                "Query realtime '{$query}' tanpa docs HARUS trigger web"
            );
        }
    }

    public function test_no_auto_realtime_when_disabled(): void
    {
        $result = $this->policyService->shouldUseWebSearch(
            query: 'cuaca hari ini jogja',
            forceWebSearch: false,
            explicitWebRequest: false,
            allowAutoRealtimeWeb: false,
            documentsActive: false
        );

        $this->assertFalse($result['should_search']);
        $this->assertEquals('NO_WEB', $result['reason_code']);
    }

    public function test_force_web_works_even_with_documents(): void
    {
        $result = $this->policyService->shouldUseWebSearch(
            query: 'apa itu fotosintesis',
            forceWebSearch: true,
            explicitWebRequest: false,
            allowAutoRealtimeWeb: true,
            documentsActive: true
        );

        $this->assertTrue($result['should_search']);
        $this->assertEquals('DOC_WEB_TOGGLE', $result['reason_code']);
    }

    public function test_no_answer_prompt_prevents_hallucination(): void
    {
        $prompt = $this->policyService->getNoAnswerPrompt();

        $this->assertStringNotContainsString('berdasarkan pengetahuan', $prompt);
        $this->assertStringNotContainsString('saya rasa', $prompt);
        $this->assertStringContainsString('belum menemukan', $prompt);
    }

    public function test_medium_realtime_intent_triggers_without_documents(): void
    {
        $queries = [
            'update terbaru tentang politik',
            'berita terkini cuaca jakarta',
        ];

        foreach ($queries as $query) {
            $result = $this->policyService->shouldUseWebSearch(
                query: $query,
                forceWebSearch: false,
                explicitWebRequest: false,
                allowAutoRealtimeWeb: true,
                documentsActive: false
            );

            $this->assertTrue(
                $result['should_search'],
                "Query medium realtime '{$query}' harus trigger web"
            );
            $this->assertStringContainsString('REALTIME', $result['reason_code']);
        }
    }
}