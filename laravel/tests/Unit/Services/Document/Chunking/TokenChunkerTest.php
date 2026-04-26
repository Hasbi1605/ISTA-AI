<?php

namespace Tests\Unit\Services\Document\Chunking;

use Tests\TestCase;
use App\Services\Document\Chunking\TokenCounter;
use App\Services\Document\Chunking\TextChunker;
use App\Services\Document\Chunking\PdrChunker;

class TokenChunkerTest extends TestCase
{
    public function test_token_counter_counts_correctly(): void
    {
        $counter = new TokenCounter();
        
        $this->assertEquals(1, $counter->count('halo'));
        $this->assertEquals(3, $counter->count('halo dunia'));
        $this->assertEquals(0, $counter->count(''));
        $this->assertEquals(0, $counter->count('   '));
    }

    public function test_token_counter_handles_longer_text(): void
    {
        $counter = new TokenCounter();
        
        $text = str_repeat('a', 400);
        $tokens = $counter->count($text);
        
        $this->assertEquals(100, $tokens);
    }

    public function test_text_chunker_splits_into_chunks(): void
    {
        $chunker = new TextChunker(100, 20);
        
        $pages = [
            ['page_content' => 'Ini adalah halaman satu dengan teks yang cukup panjang untuk dipecah menjadi beberapa chunk.', 'page_number' => 1],
            ['page_content' => 'Ini halaman dua dengan teks yang berbeda.', 'page_number' => 2],
        ];
        
        $chunks = $chunker->chunk($pages);
        
        $this->assertNotEmpty($chunks);
        $this->assertLessThanOrEqual(5, count($chunks));
    }

    public function test_text_chunker_respects_chunk_size(): void
    {
        $chunker = new TextChunker(50, 10);
        
        $pages = [
            ['page_content' => str_repeat('abc ', 30), 'page_number' => 1],
        ];
        
        $chunks = $chunker->chunk($pages);
        
        foreach ($chunks as $chunk) {
            $tokens = (new TokenCounter())->count($chunk);
            $this->assertLessThanOrEqual(60, $tokens);
        }
    }

    public function test_pdr_chunker_creates_parent_and_child_chunks(): void
    {
        $chunker = new PdrChunker(100, 20, 30, 5);
        
        $pages = [
            ['page_content' => 'Ini adalah teks Parent chunk yang cukup panjang untuk dibuat child chunks.', 'page_number' => 1],
        ];
        
        $chunks = $chunker->chunk($pages, 'test.pdf', 'user1');
        
        $parentChunks = array_filter($chunks, fn($c) => $c['chunk_type'] === 'parent');
        $childChunks = array_filter($chunks, fn($c) => $c['chunk_type'] === 'child');
        
        $this->assertNotEmpty($parentChunks);
        $this->assertNotEmpty($childChunks);
    }

    public function test_pdr_chunker_generates_unique_parent_ids(): void
    {
        $chunker = new PdrChunker();
        
        $pages = [
            ['page_content' => 'Teks untuk chunk 1', 'page_number' => 1],
            ['page_content' => 'Teks untuk chunk 2', 'page_number' => 2],
        ];
        
        $chunks = $chunker->chunk($pages, 'test.pdf', 'user1');
        
        $parentIds = array_unique(array_column(array_filter($chunks, fn($c) => $c['chunk_type'] === 'parent'), 'parent_id'));
        
        $this->assertEquals(count($parentIds), count(array_filter($chunks, fn($c) => $c['chunk_type'] === 'parent')));
    }

    public function test_pdr_chunker_preserves_metadata(): void
    {
        $chunker = new PdrChunker();
        
        $pages = [
            ['page_content' => 'Teks dokumen test', 'page_number' => 1],
        ];
        
        $chunks = $chunker->chunk($pages, 'dokumen.pdf', 'user123');
        
        foreach ($chunks as $chunk) {
            $this->assertEquals('dokumen.pdf', $chunk['metadata']['filename']);
            $this->assertEquals('user123', $chunk['metadata']['user_id']);
        }
    }

    public function test_text_chunker_overlap_is_word_safe(): void
    {
        $chunker = new TextChunker(15, 5);
        
        $pages = [
            ['page_content' => 'one two three four five six', 'page_number' => 1],
        ];
        
        $chunks = $chunker->chunk($pages);
        
        $this->assertNotEmpty($chunks);
        
        foreach ($chunks as $chunk) {
            $this->assertStringStartsWith('o', $chunk, 'Chunk should start at word boundary, not middle of word');
        }
    }

    public function test_pdr_chunker_preserves_separator(): void
    {
        $chunker = new PdrChunker(30, 10, 15, 5);
        
        $pages = [
            ['page_content' => 'word1 word2 word3 word4 word5', 'page_number' => 1],
        ];
        
        $chunks = $chunker->chunk($pages, 'test.pdf', 'user1');
        
        $this->assertNotEmpty($chunks);
        
        foreach ($chunks as $chunk) {
            $this->assertStringContainsString(' ', $chunk['text'], 'Chunk should preserve spaces between words');
        }
    }

    public function test_text_chunker_preserves_period_separator(): void
    {
        $chunker = new TextChunker(20, 5);
        
        $pages = [
            ['page_content' => 'First sentence. Second sentence. Third sentence.', 'page_number' => 1],
        ];
        
        $chunks = $chunker->chunk($pages);
        
        $this->assertNotEmpty($chunks);
        
        foreach ($chunks as $chunk) {
            $this->assertStringContainsString('. ', $chunk, 'Chunk should preserve period-space separator');
        }
    }
}