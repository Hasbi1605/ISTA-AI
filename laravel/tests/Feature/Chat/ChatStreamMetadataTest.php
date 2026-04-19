<?php

namespace Tests\Feature\Chat;

use App\Livewire\Chat\ChatIndex;
use ReflectionMethod;
use Tests\TestCase;

class ChatStreamMetadataTest extends TestCase
{
    public function test_extract_stream_metadata_buffers_split_sources_marker(): void
    {
        $component = new ChatIndex();
        $method = new ReflectionMethod(ChatIndex::class, 'extractStreamMetadata');
        $method->setAccessible(true);

        $firstPass = $method->invoke($component, 'Jawaban awal [SOURCES:[{"url":"https://example.com"', '');

        $this->assertSame('Jawaban awal ', $firstPass[0]);
        $this->assertSame('[SOURCES:[{"url":"https://example.com"', $firstPass[1]);
        $this->assertNull($firstPass[3]);

        $secondPass = $method->invoke($component, ',"title":"Contoh"}]]', $firstPass[1]);

        $this->assertSame('', $secondPass[0]);
        $this->assertSame('', $secondPass[1]);
        $this->assertSame([
            ['url' => 'https://example.com', 'title' => 'Contoh'],
        ], $secondPass[3]);
    }
}
