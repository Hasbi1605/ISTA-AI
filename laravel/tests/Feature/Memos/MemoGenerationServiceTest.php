<?php

namespace Tests\Feature\Memos;

use App\Models\Memo;
use App\Models\MemoVersion;
use App\Models\User;
use App\Services\Memo\MemoGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class MemoGenerationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_initial_generate_rejects_corrupt_docx_without_persisting_memo(): void
    {
        Http::fake([
            '*/api/memos/generate-body' => Http::response("PK\x03\x04corrupt", 200, [
                'X-Memo-Searchable-Text-B64' => base64_encode('Memo korup'),
            ]),
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);

        try {
            app(MemoGenerationService::class)->generate(
                $user,
                'memo_internal',
                'Memo Korup',
                'Buat memo yang respons DOCX-nya korup.',
                [],
                $this->configuration(),
            );

            $this->fail('Generate memo seharusnya menolak DOCX korup.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Draft memo', $e->getMessage());
        }

        $this->assertSame(0, Memo::count());
        $this->assertSame(0, MemoVersion::count());
    }

    public function test_initial_generate_rolls_back_when_storage_put_fails(): void
    {
        Http::fake([
            '*/api/memos/generate-body' => Http::response($this->validMemoDocxBytes(), 200, [
                'X-Memo-Searchable-Text-B64' => base64_encode('Memo valid'),
                'X-Memo-Page-Size' => 'letter',
            ]),
        ]);

        Storage::shouldReceive('disk')
            ->once()
            ->with('local')
            ->andReturn(new class
            {
                public function put(string $path, string $content): bool
                {
                    return false;
                }
            });

        $user = User::factory()->create(['email_verified_at' => now()]);

        try {
            app(MemoGenerationService::class)->generate(
                $user,
                'memo_internal',
                'Memo Storage Gagal',
                'Buat memo dengan simulasi storage gagal.',
                [],
                $this->configuration(),
            );

            $this->fail('Generate memo seharusnya gagal saat storage gagal.');
        } catch (RuntimeException $e) {
            $this->assertSame('Gagal menyimpan file DOCX memo.', $e->getMessage());
        }

        $this->assertSame(0, Memo::count());
        $this->assertSame(0, MemoVersion::count());
    }

    /**
     * @return array<string, string>
     */
    private function configuration(): array
    {
        return [
            'number' => 'EVAL-08/IST/YK/05/2026',
            'recipient' => 'Kepala Unit Layanan',
            'sender' => 'Kepala Istana Kepresidenan Yogyakarta',
            'subject' => 'Memo Test',
            'date' => '7 Mei 2026',
            'content' => 'Isi memo test.',
            'signatory' => 'Deni Mulyana',
            'page_size' => 'letter',
        ];
    }
}
