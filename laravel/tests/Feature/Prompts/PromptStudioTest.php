<?php

namespace Tests\Feature\Prompts;

use App\Livewire\Prompts\PrompyStudio;
use App\Models\AIUsageEvent;
use App\Models\GeneratedPrompt;
use App\Models\User;
use App\Services\Prompts\PromptStudioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class PromptStudioTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        RateLimiter::clearResolvedInstances();
        parent::tearDown();
    }

    private function fakePackageResponse(array $overrides = []): array
    {
        return array_merge([
            'platform' => 'gpt_image_2',
            'platform_label' => 'GPT Image 2',
            'prompt_type' => 'poster_infographic',
            'prompt_type_label' => 'Poster / Infografis',
            'main_prompt' => 'A formal poster of the presidential palace, golden hour lighting.',
            'variants' => ['A cinematic wide shot', 'A flat-design minimalist poster'],
            'negative_prompt' => 'blurry, watermark',
            'recommended_settings' => ['aspect_ratio' => '16:9'],
            'notes_id' => 'Tempel prompt utama ke platform pilihan Anda.',
            'model_label' => 'GPT-4.1',
            'reference_image_analyzed' => false,
        ], $overrides);
    }

    public function test_service_generates_and_persists_owned_prompt(): void
    {
        Http::fake([
            '*/api/prompts/generate' => Http::response($this->fakePackageResponse(), 200),
        ]);
        $user = User::factory()->create();

        $prompt = app(PromptStudioService::class)->generate($user, [
            'idea' => 'Buat poster acara kenegaraan bertema persatuan',
            'platform' => 'gpt_image_2',
            'prompt_type' => 'poster_infographic',
        ]);

        $this->assertSame((int) $user->id, (int) $prompt->user_id);
        $this->assertSame('gpt_image_2', $prompt->platform);
        $this->assertSame('poster_infographic', $prompt->prompt_type);
        $this->assertStringContainsString('presidential palace', $prompt->normalizedPackage()['main_prompt']);
        $this->assertFalse($prompt->contains_internal_context);
    }

    public function test_source_documents_are_ignored_for_prompy_generation(): void
    {
        Http::fake([
            '*/api/prompts/generate' => Http::response($this->fakePackageResponse(), 200),
        ]);
        $user = User::factory()->create();

        $prompt = app(PromptStudioService::class)->generate($user, [
            'idea' => 'Buat prompt visual tanpa dokumen sumber',
            'platform' => 'generic',
            'prompt_type' => 'image',
            'source_document_ids' => [123, 456],
        ]);

        $this->assertSame([], $prompt->source_document_ids);
        $this->assertSame('Universal', $prompt->platform_label);
        $this->assertFalse($prompt->contains_internal_context);
        Http::assertSent(function ($request) {
            $data = $request->data();

            return ! array_key_exists('source_context', $data)
                && ! array_key_exists('has_reference_image', $data);
        });
    }

    public function test_reference_image_is_validated_and_stored_private(): void
    {
        Storage::fake('local');
        Http::fake([
            '*/api/prompts/generate' => Http::response($this->fakePackageResponse([
                'reference_image_analyzed' => true,
            ]), 200),
        ]);
        $user = User::factory()->create();

        $prompt = app(PromptStudioService::class)->generate($user, [
            'idea' => 'Buat gambar dari referensi',
            'platform' => 'gemini_nano_banana',
            'prompt_type' => 'image',
            'reference_image' => UploadedFile::fake()->image('ref.png', 200, 200),
        ]);

        $this->assertNotNull($prompt->reference_image_path);
        $this->assertStringContainsString('prompt-references/'.$user->id, $prompt->reference_image_path);
        Storage::disk('local')->assertExists($prompt->reference_image_path);
        $this->assertTrue($prompt->contains_internal_context);
        $event = AIUsageEvent::where('feature', AIUsageEvent::FEATURE_PROMPT_GENERATION)
            ->where('subject_id', $prompt->id)
            ->first();
        $this->assertNotNull($event);
        $this->assertSame((int) $prompt->id, $event->metadata['generated_prompt_id'] ?? null);
        $this->assertSame('gemini_nano_banana', $event->metadata['platform'] ?? null);
        $this->assertSame('image', $event->metadata['prompt_type'] ?? null);
        $this->assertTrue($event->metadata['has_reference_image'] ?? false);
        $this->assertTrue($event->metadata['reference_image_analyzed'] ?? false);
        $this->assertTrue($event->metadata['contains_internal_context'] ?? false);
        Http::assertSent(function ($request) {
            $data = $request->data();
            $referenceImage = $data['reference_image'] ?? null;

            return is_array($referenceImage)
                && $referenceImage['mime_type'] === 'image/png'
                && is_string($referenceImage['data_base64'])
                && $referenceImage['data_base64'] !== ''
                && ! array_key_exists('has_reference_image', $data)
                && ! array_key_exists('source_context', $data);
        });
    }

    public function test_invalid_reference_image_type_is_rejected(): void
    {
        Storage::fake('local');
        Http::fake([
            '*/api/prompts/generate' => Http::response($this->fakePackageResponse(), 200),
        ]);
        $user = User::factory()->create();

        $this->expectException(\RuntimeException::class);

        app(PromptStudioService::class)->generate($user, [
            'idea' => 'Ide',
            'platform' => 'generic',
            'prompt_type' => 'image',
            'reference_image' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
        ]);
    }

    public function test_webp_reference_image_is_rejected(): void
    {
        Storage::fake('local');
        Http::fake([
            '*/api/prompts/generate' => Http::response($this->fakePackageResponse(), 200),
        ]);
        $user = User::factory()->create();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Gunakan JPG atau PNG.');

        app(PromptStudioService::class)->generate($user, [
            'idea' => 'Ide',
            'platform' => 'generic',
            'prompt_type' => 'image',
            'reference_image' => UploadedFile::fake()->create('ref.webp', 10, 'image/webp'),
        ]);
    }

    public function test_livewire_generate_creates_prompt(): void
    {
        Http::fake([
            '*/api/prompts/generate' => Http::response($this->fakePackageResponse(), 200),
        ]);
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(PrompyStudio::class)
            ->set('idea', 'Buat Poster 1 Muharram 1448 H dengan nuansa warna biru, dengan elemen islami yang tidak terlalu ramai')
            ->call('selectPlatform', 'gpt_image_2')
            ->call('selectPromptType', 'poster_infographic')
            ->call('generate')
            ->assertSee('berhasil dibuat');

        $this->assertDatabaseHas('generated_prompts', [
            'user_id' => $user->id,
            'platform' => 'gpt_image_2',
            'prompt_type' => 'poster_infographic',
            'title' => 'Poster 1 Muharram 1448 H',
        ]);
    }

    public function test_livewire_requires_idea(): void
    {
        Http::fake();
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(PrompyStudio::class)
            ->set('idea', '')
            ->call('generate')
            ->assertHasErrors(['idea' => 'required']);

        Http::assertNothingSent();
    }

    public function test_history_is_user_scoped(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        GeneratedPrompt::create([
            'user_id' => $user->id,
            'platform' => 'generic',
            'platform_label' => 'Universal',
            'prompt_type' => 'image',
            'prompt_type_label' => 'Gambar',
            'title' => 'Prompt Saya',
            'idea' => 'ide',
            'package' => ['main_prompt' => 'mine'],
        ]);
        GeneratedPrompt::create([
            'user_id' => $other->id,
            'platform' => 'generic',
            'platform_label' => 'Universal',
            'prompt_type' => 'image',
            'prompt_type_label' => 'Gambar',
            'title' => 'Prompt Orang Lain',
            'idea' => 'ide',
            'package' => ['main_prompt' => 'theirs'],
        ]);

        Livewire::actingAs($user)
            ->test(PrompyStudio::class)
            ->assertSee('Prompt Saya')
            ->assertDontSee('Prompt Orang Lain');
    }

    public function test_result_panel_shows_active_prompt_package(): void
    {
        $user = User::factory()->create();

        GeneratedPrompt::create([
            'user_id' => $user->id,
            'platform' => 'gpt_image_2',
            'platform_label' => 'GPT Image 2',
            'prompt_type' => 'poster_infographic',
            'prompt_type_label' => 'Poster / Infografis',
            'title' => 'Buat Poster 1 Muharram 1448 H dengan nuansa warna biru, dengan elemen islami yang tidak terlalu ramai',
            'idea' => 'poster',
            'package' => $this->fakePackageResponse([
                'main_prompt' => 'A polished state ceremony poster with official red and gold accents.',
            ]),
            'reference_image_path' => 'prompt-references/'.$user->id.'/ref.jpg',
            'reference_image_mime' => 'image/jpeg',
            'reference_image_size_bytes' => 253051,
        ]);

        Livewire::actingAs($user)
            ->test(PrompyStudio::class)
            ->assertSee('Buat Prompt')
            ->assertSee('Prompt utama')
            ->assertSee('A polished state ceremony poster')
            ->assertSee('Salin semua')
            ->assertSee('Prompt Baru')
            ->assertSee('Cari prompt...')
            ->assertSee('GPT Image 2')
            ->assertSee('Gemini / Nano Banana')
            ->assertSee('Canva AI')
            ->assertSee('Universal')
            ->assertDontSee('Google Flow')
            ->assertDontSee('Gambar dianalisis')
            ->assertSee('Pilih atau seret gambar')
            ->assertSee('Opsional. JPG/PNG, maks 5 MB. Dianalisis privat saat prompt dibuat.')
            ->assertSee('ISTA AI dapat keliru. Mohon verifikasi kembali informasi yang penting.')
            ->assertSee('dark:text-gray-100', false)
            ->assertSee('rounded-2xl border border-stone-200/80 bg-white/90', false)
            ->assertSee('data-prompy-history-id=', false)
            ->assertSee('h-3.5 w-3.5 rounded-full border border-current border-t-transparent text-ista-primary animate-spin dark:text-amber-200', false)
            ->assertSee('h-4 w-4 rounded-full border-2 border-current/50 border-t-transparent animate-spin', false)
            ->assertSee('bg-cyan-50 text-[#00C4CC]', false)
            ->assertSee('bg-sky-50 text-sky-600', false)
            ->assertDontSee('WebP')
            ->assertDontSee('Konteks privat')
            ->assertDontSee('Mengunggah gambar')
            ->assertDontSee('ISTA AI tidak memanggil platform gambar/video eksternal.')
            ->assertDontSee('border-l-2')
            ->assertSee('Poster 1 Muharram 1448 H')
            ->assertDontSee('dengan nuansa warna biru')
            ->assertSeeInOrder(['Riwayat Prompt', 'Prompt utama']);
    }

    public function test_select_prompt_changes_active_result_panel(): void
    {
        $user = User::factory()->create();

        $first = GeneratedPrompt::create([
            'user_id' => $user->id,
            'platform' => 'generic',
            'platform_label' => 'Universal',
            'prompt_type' => 'image',
            'prompt_type_label' => 'Gambar',
            'title' => 'Prompt Pertama',
            'idea' => 'pertama',
            'package' => $this->fakePackageResponse(['main_prompt' => 'First prompt output']),
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        GeneratedPrompt::create([
            'user_id' => $user->id,
            'platform' => 'generic',
            'platform_label' => 'Universal',
            'prompt_type' => 'image',
            'prompt_type_label' => 'Gambar',
            'title' => 'Prompt Kedua',
            'idea' => 'kedua',
            'package' => $this->fakePackageResponse(['main_prompt' => 'Second prompt output']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test(PrompyStudio::class)
            ->assertSee('Second prompt output')
            ->call('selectPrompt', $first->id)
            ->assertSet('isComposingNewPrompt', false)
            ->assertSee('First prompt output');
    }

    public function test_start_new_prompt_shows_empty_result_panel_even_with_history(): void
    {
        $user = User::factory()->create();

        GeneratedPrompt::create([
            'user_id' => $user->id,
            'platform' => 'generic',
            'platform_label' => 'Universal',
            'prompt_type' => 'image',
            'prompt_type_label' => 'Gambar',
            'title' => 'Prompt Lama',
            'idea' => 'lama',
            'package' => $this->fakePackageResponse(['main_prompt' => 'Existing prompt output']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test(PrompyStudio::class)
            ->assertSee('Existing prompt output')
            ->call('startNewPrompt')
            ->assertSet('activePromptId', null)
            ->assertSet('isComposingNewPrompt', true)
            ->assertSee('Belum ada paket prompt')
            ->assertDontSee('Existing prompt output');
    }

    public function test_user_cannot_delete_another_users_prompt(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $other = User::factory()->create();

        $foreign = GeneratedPrompt::create([
            'user_id' => $other->id,
            'platform' => 'generic',
            'platform_label' => 'Universal',
            'prompt_type' => 'image',
            'prompt_type_label' => 'Gambar',
            'title' => 'Milik Orang Lain',
            'idea' => 'ide',
            'package' => ['main_prompt' => 'x'],
        ]);

        Livewire::actingAs($user)
            ->test(PrompyStudio::class)
            ->call('deletePrompt', $foreign->id);

        $this->assertDatabaseHas('generated_prompts', ['id' => $foreign->id, 'deleted_at' => null]);
    }

    public function test_failed_generation_does_not_persist_prompt(): void
    {
        Http::fake([
            '*/api/prompts/generate' => Http::response('error', 500),
        ]);
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(PrompyStudio::class)
            ->set('idea', 'Ide gagal')
            ->call('generate');

        $this->assertDatabaseCount('generated_prompts', 0);
    }
}
