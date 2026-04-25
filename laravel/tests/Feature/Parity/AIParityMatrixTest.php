<?php

namespace Tests\Feature\Parity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AI Parity Matrix Test
 * 
 * Fixture ini berfungsi sebagai checklist non-gating (acceptance matrix) antara
 * kapabilitas Python AI yang sudah ada dengan target implementasi Laravel-only.
 * Test yang ditandai incomplete (markTestIncomplete) merepresentasikan gap
 * yang harus diselesaikan pada child issue berikutnya. Sebelum cutover final,
 * seluruh test di dalam file ini wajib berstatus passed.
 */
class AIParityMatrixTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     * @group parity
     * @group chat
     */
    public function it_supports_multi_model_cascade_and_fallback()
    {
        $this->markTestIncomplete('Gap: Laravel belum memiliki runtime cascade/fallback (GPT-4.1 -> 4o -> Groq -> Gemini).');
    }

    /**
     * @test
     * @group parity
     * @group chat
     */
    public function it_handles_rate_limit_and_context_window_errors()
    {
        $this->markTestIncomplete('Gap: Laravel belum memetakan error 413/429 ke fallback policy.');
    }

    /**
     * @test
     * @group parity
     * @group chat
     */
    public function it_injects_model_marker_in_stream()
    {
        $this->markTestIncomplete('Gap: Laravel stream belum menampilkan marker [MODEL:...] secara konsisten.');
    }

    /**
     * @test
     * @group parity
     * @group web
     */
    public function it_supports_langsearch_web_search()
    {
        $this->markTestIncomplete('Gap: Laravel belum memanggil LangSearch secara langsung untuk web realtime.');
    }

    /**
     * @test
     * @group parity
     * @group web
     */
    public function it_supports_langsearch_semantic_rerank()
    {
        $this->markTestIncomplete('Gap: Laravel belum memanggil LangSearch Reranker untuk hasil web/dokumen.');
    }

    /**
     * @test
     * @group parity
     * @group rag
     */
    public function it_has_laravel_managed_vector_store_alternative()
    {
        $this->markTestIncomplete('Gap: Laravel belum memiliki vector store pengganti Chroma atau custom provider setara.');
    }

    /**
     * @test
     * @group parity
     * @group rag
     */
    public function it_supports_embedding_fallback()
    {
        $this->markTestIncomplete('Gap: Laravel belum memiliki fallback untuk text-embedding-3 (primary -> backup -> small).');
    }

    /**
     * @test
     * @group parity
     * @group rag
     */
    public function it_supports_hybrid_search_with_rrf()
    {
        $this->markTestIncomplete('Gap: Laravel belum mengimplementasikan Hybrid Search (Vector + BM25) dan RRF.');
    }

    /**
     * @test
     * @group parity
     * @group rag
     */
    public function it_supports_hyde_for_conceptual_queries()
    {
        $this->markTestIncomplete('Gap: Laravel belum memiliki pre-query expansion (HyDE).');
    }

    /**
     * @test
     * @group parity
     * @group rag
     */
    public function it_supports_parent_document_retrieval()
    {
        $this->markTestIncomplete('Gap: Laravel belum mendukung arsitektur Parent-Child chunks untuk dokumen panjang.');
    }

    /**
     * @test
     * @group parity
     * @group ingest
     */
    public function it_implements_token_aware_chunking()
    {
        $this->markTestIncomplete('Gap: Laravel belum memecah dokumen berdasarkan batas token spesifik seperti Python.');
    }

    /**
     * @test
     * @group parity
     * @group ingest
     */
    public function it_implements_batch_ingest_throttling()
    {
        $this->markTestIncomplete('Gap: Laravel belum memiliki delay antar batch saat menelan dokumen besar.');
    }

    /**
     * @test
     * @group parity
     * @group ocr
     */
    public function it_supports_ocr_for_scanned_pdf()
    {
        $this->markTestIncomplete('Gap: Laravel belum mendukung parsing PDF hasil scan (via Gemini OCR/Tesseract).');
    }

    /**
     * @test
     * @group parity
     * @group summarization
     */
    public function it_supports_chunk_based_summarization()
    {
        $this->markTestIncomplete('Gap: Laravel belum mengimplementasikan map-reduce/chunk summarization untuk dokumen besar.');
    }

    /**
     * @test
     * @group parity
     * @group chat
     */
    public function it_injects_source_metadata_in_stream()
    {
        $this->markTestIncomplete('Gap: Laravel belum merender [SOURCES:...] metadata seperti pada sistem Python.');
    }

    /**
     * @test
     * @group parity
     * @group policy
     */
    public function it_enforces_document_vs_web_policy()
    {
        $service = app(\App\Services\Document\DocumentPolicyService::class);
        $result = $service->shouldUseWebSearch(
            query: 'kurs dollar sekarang',
            forceWebSearch: false,
            explicitWebRequest: false,
            allowAutoRealtimeWeb: true,
            documentsActive: true
        );
        
        $this->assertFalse($result['should_search']);
        $this->assertEquals('DOC_NO_WEB', $result['reason_code']);
    }

    /**
     * @test
     * @group parity
     * @group lifecycle
     */
    public function it_enforces_delete_cleanup_per_user()
    {
        Storage::fake('local');
        $user = \App\Models\User::factory()->create();
        
        $doc = \App\Models\Document::create([
            'user_id' => $user->id,
            'filename' => 'delete_matrix.pdf',
            'original_name' => 'delete_matrix.pdf',
            'file_path' => 'documents/' . $user->id . '/delete_matrix.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 123,
            'status' => 'ready',
        ]);
        
        Storage::disk('local')->put($doc->file_path, 'dummy');

        $service = app(\App\Services\DocumentLifecycleService::class);
        $service->deleteDocument($doc);
        
        $this->assertSoftDeleted($doc);
        Storage::disk('local')->assertMissing($doc->file_path);
    }
}
