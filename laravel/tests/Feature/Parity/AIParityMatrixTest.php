<?php

namespace Tests\Feature\Parity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
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

    #[Test]
    #[Group('parity')]
    #[Group('chat')]
    public function it_supports_multi_model_cascade_and_fallback()
    {
        $this->markTestIncomplete('Gap: Laravel belum memiliki runtime cascade/fallback (GPT-4.1 -> 4o -> Groq -> Gemini).');
    }

    #[Test]
    #[Group('parity')]
    #[Group('chat')]
    public function it_handles_rate_limit_and_context_window_errors()
    {
        $this->markTestIncomplete('Gap: Laravel belum memetakan error 413/429 ke fallback policy.');
    }

    #[Test]
    #[Group('parity')]
    #[Group('chat')]
    public function it_injects_model_marker_in_stream()
    {
        $this->markTestIncomplete('Gap: Laravel stream belum menampilkan marker [MODEL:...] secara konsisten.');
    }

    #[Test]
    #[Group('parity')]
    #[Group('web')]
    public function it_supports_langsearch_web_search()
    {
        $this->markTestIncomplete('Gap: Laravel belum memanggil LangSearch secara langsung untuk web realtime.');
    }

    #[Test]
    #[Group('parity')]
    #[Group('web')]
    public function it_supports_langsearch_semantic_rerank()
    {
        $this->markTestIncomplete('Gap: Laravel belum memanggil LangSearch Reranker untuk hasil web/dokumen.');
    }

    #[Test]
    #[Group('parity')]
    #[Group('rag')]
    public function it_has_laravel_managed_vector_store_alternative()
    {
        $this->markTestIncomplete('Gap: Laravel belum memiliki vector store pengganti Chroma atau custom provider setara.');
    }

    #[Test]
    #[Group('parity')]
    #[Group('rag')]
    public function it_supports_embedding_fallback()
    {
        $this->markTestIncomplete('Gap: Laravel belum memiliki fallback untuk text-embedding-3 (primary -> backup -> small).');
    }

    #[Test]
    #[Group('parity')]
    #[Group('rag')]
    public function it_supports_hybrid_search_with_rrf()
    {
        $this->markTestIncomplete('Gap: Laravel belum mengimplementasikan Hybrid Search (Vector + BM25) dan RRF.');
    }

    #[Test]
    #[Group('parity')]
    #[Group('rag')]
    public function it_supports_hyde_for_conceptual_queries()
    {
        $this->markTestIncomplete('Gap: Laravel belum memiliki pre-query expansion (HyDE).');
    }

    #[Test]
    #[Group('parity')]
    #[Group('rag')]
    public function it_supports_parent_document_retrieval()
    {
        $this->markTestIncomplete('Gap: Laravel belum mendukung arsitektur Parent-Child chunks untuk dokumen panjang.');
    }

    #[Test]
    #[Group('parity')]
    #[Group('ingest')]
    public function it_implements_token_aware_chunking()
    {
        $this->markTestIncomplete('Gap: Laravel belum memecah dokumen berdasarkan batas token spesifik seperti Python.');
    }

    #[Test]
    #[Group('parity')]
    #[Group('ingest')]
    public function it_implements_batch_ingest_throttling()
    {
        $this->markTestIncomplete('Gap: Laravel belum memiliki delay antar batch saat menelan dokumen besar.');
    }

    #[Test]
    #[Group('parity')]
    #[Group('ocr')]
    public function it_supports_ocr_for_scanned_pdf()
    {
        $this->markTestIncomplete('Gap: Laravel belum mendukung parsing PDF hasil scan (Target Utama: GitHub Models Vision; Fallback: Gemini/Tesseract).');
    }

    #[Test]
    #[Group('parity')]
    #[Group('summarization')]
    public function it_supports_chunk_based_summarization()
    {
        $this->markTestIncomplete('Gap: Laravel belum mengimplementasikan map-reduce/chunk summarization untuk dokumen besar.');
    }

    #[Test]
    #[Group('parity')]
    #[Group('chat')]
    public function it_injects_source_metadata_in_stream()
    {
        $this->markTestIncomplete('Gap: Laravel belum merender [SOURCES:...] metadata seperti pada sistem Python.');
    }

    #[Test]
    #[Group('parity')]
    #[Group('policy')]
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

    #[Test]
    #[Group('parity')]
    #[Group('lifecycle')]
    public function it_enforces_delete_cleanup_per_user()
    {
        Storage::fake('local');
        
        // Create two users with the same filename
        $userA = \App\Models\User::factory()->create();
        $userB = \App\Models\User::factory()->create();
        $sameFilename = 'shared_document.pdf';
        
        // User B's document
        $docB = \App\Models\Document::create([
            'user_id' => $userB->id,
            'filename' => $sameFilename . '_b',
            'original_name' => $sameFilename,
            'file_path' => 'documents/' . $userB->id . '/' . $sameFilename,
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 100,
            'status' => 'ready',
        ]);
        Storage::disk('local')->put($docB->file_path, 'content B');
        
        // User A's document
        $docA = \App\Models\Document::create([
            'user_id' => $userA->id,
            'filename' => $sameFilename . '_a',
            'original_name' => $sameFilename,
            'file_path' => 'documents/' . $userA->id . '/' . $sameFilename,
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 100,
            'status' => 'ready',
        ]);
        Storage::disk('local')->put($docA->file_path, 'content A');
        
        $service = app(\App\Services\DocumentLifecycleService::class);
        
        // User A deletes THEIR document
        $service->deleteDocument($docA);
        
        // Assert User A's doc is deleted from DB and storage
        $this->assertSoftDeleted($docA);
        Storage::disk('local')->assertMissing($docA->file_path);
        
        // Assert User B's doc REMAINS in DB and storage (Isolation check)
        $this->assertNotSoftDeleted($docB);
        Storage::disk('local')->assertExists($docB->file_path);
    }
}
