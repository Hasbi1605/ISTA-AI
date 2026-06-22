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

    private function fakePromptChatResponse(array $overrides = []): array
    {
        return array_merge([
            'intent' => 'answer',
            'assistant_message' => 'Saya bantu bahas prompt ini tanpa mengubah panel hasil dulu.',
            'revision_instruction' => '',
            'model_label' => 'GPT-4.1',
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
        $this->assertNotNull($prompt->current_version_id);
        $this->assertSame(1, $prompt->versions()->count());
        $this->assertSame(1, $prompt->currentVersion?->version_number);
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

    public function test_reference_images_are_validated_and_stored_private(): void
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
            'reference_images' => [
                UploadedFile::fake()->image('ref-1.png', 200, 200),
                UploadedFile::fake()->image('ref-2.jpg', 300, 300),
            ],
        ]);

        $this->assertNotNull($prompt->reference_image_path);
        $this->assertStringContainsString('prompt-references/'.$user->id, $prompt->reference_image_path);
        Storage::disk('local')->assertExists($prompt->reference_image_path);
        $version = $prompt->currentVersion;
        $this->assertNotNull($version);
        $this->assertCount(2, $version->reference_images);
        foreach ($version->reference_images as $image) {
            Storage::disk('local')->assertExists($image['path']);
        }
        $this->assertTrue($prompt->contains_internal_context);
        $event = AIUsageEvent::where('feature', AIUsageEvent::FEATURE_PROMPT_GENERATION)
            ->where('subject_id', $prompt->id)
            ->first();
        $this->assertNotNull($event);
        $this->assertSame((int) $prompt->id, $event->metadata['generated_prompt_id'] ?? null);
        $this->assertSame('gemini_nano_banana', $event->metadata['platform'] ?? null);
        $this->assertSame('image', $event->metadata['prompt_type'] ?? null);
        $this->assertTrue($event->metadata['has_reference_image'] ?? false);
        $this->assertSame(2, $event->metadata['reference_image_count'] ?? null);
        $this->assertTrue($event->metadata['reference_image_analyzed'] ?? false);
        $this->assertTrue($event->metadata['contains_internal_context'] ?? false);
        Http::assertSent(function ($request) {
            $data = $request->data();
            $referenceImages = $data['reference_images'] ?? null;

            return is_array($referenceImages)
                && count($referenceImages) === 2
                && $referenceImages[0]['label'] === 'Gambar 1'
                && $referenceImages[0]['mime_type'] === 'image/png'
                && is_string($referenceImages[0]['data_base64'])
                && $referenceImages[0]['data_base64'] !== ''
                && $referenceImages[1]['label'] === 'Gambar 2'
                && $referenceImages[1]['mime_type'] === 'image/jpeg'
                && ! array_key_exists('reference_image', $data)
                && ! array_key_exists('has_reference_image', $data)
                && ! array_key_exists('source_context', $data);
        });
    }

    public function test_reference_images_are_limited_to_five_files(): void
    {
        Storage::fake('local');
        Http::fake([
            '*/api/prompts/generate' => Http::response($this->fakePackageResponse(), 200),
        ]);
        $user = User::factory()->create();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('maksimal 5 file');

        app(PromptStudioService::class)->generate($user, [
            'idea' => 'Ide',
            'platform' => 'generic',
            'prompt_type' => 'image',
            'reference_images' => collect(range(1, 6))
                ->map(fn ($i) => UploadedFile::fake()->image("ref-{$i}.png", 100, 100))
                ->all(),
        ]);
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
            ->assertSee('berhasil dibuat')
            ->assertSee('Prompt aktif')
            ->assertSee('Konfigurasi prompt:')
            ->assertSee('Tulis pesan untuk membahas prompt ini...')
            ->assertDontSee('Catatan konteks tambahan');

        $this->assertDatabaseHas('generated_prompts', [
            'user_id' => $user->id,
            'platform' => 'gpt_image_2',
            'prompt_type' => 'poster_infographic',
            'title' => 'Poster 1 Muharram 1448 H',
        ]);
    }

    public function test_new_prompt_starts_without_default_platform_or_prompt_type(): void
    {
        Http::fake();
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(PrompyStudio::class)
            ->assertSet('platform', null)
            ->assertSet('promptType', null)
            ->set('idea', 'Buat prompt visual')
            ->call('generate')
            ->assertHasErrors([
                'platform' => 'required',
                'promptType' => 'required',
            ]);

        Http::assertNothingSent();
    }

    public function test_livewire_requires_idea(): void
    {
        Http::fake();
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(PrompyStudio::class)
            ->call('selectPlatform', 'gpt_image_2')
            ->call('selectPromptType', 'poster_infographic')
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
            ->assertSee('Prompt utama')
            ->assertSee('A polished state ceremony poster')
            ->assertSee('Salin semua')
            ->assertSee('Prompt Baru')
            ->assertSee('Cari prompt...')
            ->assertDontSee('Google Flow')
            ->assertDontSee('Gambar dianalisis')
            ->assertSee('Prompt aktif')
            ->assertSee('Konfigurasi prompt:')
            ->assertSee('Tulis pesan untuk membahas prompt ini...')
            ->assertSee('prompyRevisionText', false)
            ->assertSee('submitPrompyRevision($wire, $refs.prompyInput)', false)
            ->assertSee('sendPromptChat(message)', false)
            ->assertSee('Edit konfigurasi')
            ->assertDontSee('Catatan konteks tambahan')
            ->assertSee('ISTA AI dapat keliru. Mohon verifikasi kembali informasi yang penting.')
            ->assertSee('dark:text-gray-100', false)
            ->assertSee('rounded-2xl border border-stone-200/75 bg-stone-50/90', false)
            ->assertSee('inline-flex w-fit max-w-full flex-col gap-1 rounded-2xl', false)
            ->assertSee('aspect_ratio: 16:9')
            ->assertDontSee('rounded-2xl border border-stone-200/80 bg-white/90', false)
            ->assertSee('data-prompy-history-id=', false)
            ->assertSee('h-3.5 w-3.5 rounded-full border border-current border-t-transparent text-ista-primary animate-spin dark:text-amber-200', false)
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

    public function test_initial_active_prompt_follows_recently_updated_history(): void
    {
        $user = User::factory()->create();

        $recentlyCreated = GeneratedPrompt::create([
            'user_id' => $user->id,
            'platform' => 'generic',
            'platform_label' => 'Universal',
            'prompt_type' => 'image',
            'prompt_type_label' => 'Gambar',
            'title' => 'Prompt Baru Dibuat',
            'idea' => 'baru dibuat',
            'package' => $this->fakePackageResponse(['main_prompt' => 'Recently created output']),
            'created_at' => now(),
            'updated_at' => now()->subDays(2),
        ]);
        $recentlyUpdated = GeneratedPrompt::create([
            'user_id' => $user->id,
            'platform' => 'generic',
            'platform_label' => 'Universal',
            'prompt_type' => 'image',
            'prompt_type_label' => 'Gambar',
            'title' => 'Prompt Baru Diubah',
            'idea' => 'baru diubah',
            'package' => $this->fakePackageResponse(['main_prompt' => 'Recently updated output']),
            'created_at' => now()->subDays(7),
            'updated_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test(PrompyStudio::class)
            ->assertSet('activePromptId', $recentlyUpdated->id)
            ->assertSee('Recently updated output')
            ->assertDontSee('Recently created output')
            ->assertSee('data-prompy-history-id="'.$recentlyUpdated->id.'"', false)
            ->assertSee('data-prompy-history-id="'.$recentlyCreated->id.'"', false);
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
            ->assertSet('platform', null)
            ->assertSet('promptType', null)
            ->assertSee('Pilih atau seret gambar')
            ->assertSee('Opsional. JPG/PNG, maksimal 5 gambar, masing-masing 5 MB.')
            ->assertDontSee('Catatan konteks tambahan')
            ->assertSee('h-4 w-4 rounded-full border-2 border-current/50 border-t-transparent animate-spin', false)
            ->assertSee('GPT Image 2')
            ->assertSee('Gemini / Nano Banana')
            ->assertSee('Canva AI')
            ->assertSee('Universal')
            ->assertSee('prompy-gemini-gradient', false)
            ->assertSee('prompy-canva-clip', false)
            ->assertSee('bg-sky-50 text-sky-600', false)
            ->assertDontSee('Google Flow')
            ->assertSee('Belum ada paket prompt')
            ->assertDontSee('Existing prompt output');
    }

    public function test_revision_creates_new_prompt_version(): void
    {
        Storage::fake('local');
        $calls = [];
        Http::fake([
            '*/api/prompts/chat' => function ($request) {
                $data = $request->data();
                if (str_contains((string) ($data['message'] ?? ''), 'Ganti nomor')) {
                    return Http::response($this->fakePromptChatResponse([
                        'intent' => 'revise',
                        'assistant_message' => 'Saya ubah nomor WhatsApp pada versi baru.',
                        'revision_instruction' => 'Ganti nomor WhatsApp menjadi 083826039171',
                    ]), 200);
                }

                return Http::response($this->fakePromptChatResponse([
                    'intent' => 'answer',
                    'assistant_message' => 'Baik, saya pertahankan Versi 2 sebagai prompt aktif.',
                ]), 200);
            },
            '*/api/prompts/generate' => function ($request) use (&$calls) {
                $data = $request->data();
                $calls[] = $data;

                if (! empty($data['revision_instruction'])) {
                    return Http::response($this->fakePackageResponse([
                        'main_prompt' => 'A revised formal passport photo prompt.',
                        'reference_image_analyzed' => true,
                    ]), 200);
                }

                return Http::response($this->fakePackageResponse([
                    'main_prompt' => 'A first draft visual prompt.',
                    'reference_image_analyzed' => true,
                ]), 200);
            },
        ]);
        $user = User::factory()->create();

        $prompt = app(PromptStudioService::class)->generate($user, [
            'idea' => 'Buat prompt pas foto dari referensi',
            'platform' => 'gpt_image_2',
            'prompt_type' => 'image',
            'reference_images' => [
                UploadedFile::fake()->image('subject.png', 200, 200),
                UploadedFile::fake()->image('style.png', 200, 200),
            ],
        ]);

        $updated = app(PromptStudioService::class)->revise(
            $user,
            $prompt,
            'Gunakan Gambar 1 sebagai subjek dan tiru gaya Gambar 2.',
            $prompt->currentVersion,
        );

        $this->assertSame(2, $updated->versions()->count());
        $this->assertSame(2, $updated->currentVersion?->version_number);
        $this->assertStringContainsString('revised formal passport', $updated->normalizedPackage()['main_prompt']);
        $this->assertSame('Gunakan Gambar 1 sebagai subjek dan tiru gaya Gambar 2.', $updated->currentVersion?->revision_instruction);
        $this->assertSame('A first draft visual prompt.', $updated->versions()->where('version_number', 1)->first()?->normalizedPackage()['main_prompt']);
        $this->assertSame('Gunakan Gambar 1 sebagai subjek dan tiru gaya Gambar 2.', $calls[1]['revision_instruction'] ?? null);
        $this->assertSame('A first draft visual prompt.', $calls[1]['current_package']['main_prompt'] ?? null);
        $this->assertCount(2, $calls[1]['reference_images'] ?? []);
    }

    public function test_livewire_prompt_chat_message_revises_only_when_instruction_changes_prompt(): void
    {
        $calls = [];
        Http::fake([
            '*/api/prompts/chat' => function ($request) {
                $message = (string) ($request->data()['message'] ?? '');

                if (str_contains($message, 'Ganti nomor')) {
                    return Http::response($this->fakePromptChatResponse([
                        'intent' => 'revise',
                        'assistant_message' => 'Saya ubah nomor WhatsApp pada versi baru.',
                        'revision_instruction' => 'Ganti nomor WhatsApp menjadi 083826039171',
                    ]), 200);
                }

                return Http::response($this->fakePromptChatResponse([
                    'intent' => 'answer',
                    'assistant_message' => 'Baik, saya pertahankan Versi 2 sebagai prompt aktif.',
                ]), 200);
            },
            '*/api/prompts/generate' => function ($request) use (&$calls) {
                $data = $request->data();
                $calls[] = $data;

                if (! empty($data['revision_instruction'])) {
                    return Http::response($this->fakePackageResponse([
                        'main_prompt' => 'A revised prompt with WhatsApp 083826039171.',
                    ]), 200);
                }

                return Http::response($this->fakePackageResponse([
                    'main_prompt' => 'A first draft prompt.',
                ]), 200);
            },
        ]);
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)
            ->test(PrompyStudio::class)
            ->set('idea', 'Buat prompt desain laundry')
            ->call('selectPlatform', 'gpt_image_2')
            ->call('selectPromptType', 'image')
            ->call('generate')
            ->call('sendPromptChat', 'Ganti nomor WhatsApp menjadi 083826039171')
            ->assertSet('revisionInstruction', '')
            ->assertSet('showPromptConfiguration', false)
            ->assertSee('Ganti nomor WhatsApp menjadi 083826039171')
            ->assertSee('Sudah saya terapkan ke Versi 2')
            ->assertSee('A revised prompt with WhatsApp 083826039171.')
            ->call('sendPromptChat', 'Oke sudah bagus')
            ->assertSee('Oke sudah bagus')
            ->assertSee('saya pertahankan Versi 2 sebagai prompt aktif');

        $prompt = GeneratedPrompt::with(['currentVersion', 'versions'])->findOrFail($component->get('activePromptId'));
        $this->assertSame(2, $prompt->versions()->count());
        $this->assertSame(2, $prompt->currentVersion?->version_number);
        $this->assertSame('Ganti nomor WhatsApp menjadi 083826039171', $prompt->currentVersion?->revision_instruction);
        $this->assertSame('A revised prompt with WhatsApp 083826039171.', $prompt->normalizedPackage()['main_prompt']);
        $this->assertCount(2, $calls);
        $this->assertSame('Ganti nomor WhatsApp menjadi 083826039171', $calls[1]['revision_instruction'] ?? null);
        $this->assertSame('A first draft prompt.', $calls[1]['current_package']['main_prompt'] ?? null);
        $this->assertCount(2, $prompt->chat_messages);
        $this->assertSame('user', $prompt->chat_messages[0]['role'] ?? null);
        $this->assertSame('Oke sudah bagus', $prompt->chat_messages[0]['content'] ?? null);
        $this->assertSame('assistant', $prompt->chat_messages[1]['role'] ?? null);
    }

    public function test_livewire_prompt_chat_preserves_repeated_assistant_replies(): void
    {
        Http::fake([
            '*/api/prompts/chat' => function ($request) {
                $message = (string) ($request->data()['message'] ?? '');

                return Http::response($this->fakePromptChatResponse([
                    'intent' => 'answer',
                    'assistant_message' => 'Saya menangkap pesan "'.$message.'". Mau saya cek bagian prompt yang mana?',
                ]), 200);
            },
            '*/api/prompts/generate' => Http::response($this->fakePackageResponse([
                'main_prompt' => 'A first draft prompt.',
            ]), 200),
        ]);
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)
            ->test(PrompyStudio::class)
            ->set('idea', 'Buat prompt logo komunitas')
            ->call('selectPlatform', 'gpt_image_2')
            ->call('selectPromptType', 'image')
            ->call('generate')
            ->call('sendPromptChat', 'uiii pop')
            ->call('sendPromptChat', 'halo')
            ->call('sendPromptChat', 'hei jawblah');

        $prompt = GeneratedPrompt::findOrFail($component->get('activePromptId'));
        $this->assertCount(6, $prompt->chat_messages);
        $this->assertSame('uiii pop', $prompt->chat_messages[0]['content'] ?? null);
        $this->assertSame('assistant', $prompt->chat_messages[1]['role'] ?? null);
        $this->assertSame('halo', $prompt->chat_messages[2]['content'] ?? null);
        $this->assertSame('assistant', $prompt->chat_messages[3]['role'] ?? null);
        $this->assertSame('hei jawblah', $prompt->chat_messages[4]['content'] ?? null);
        $this->assertSame('assistant', $prompt->chat_messages[5]['role'] ?? null);

        $assistantReplies = collect($component->get('promptChatMessages'))
            ->where('role', 'assistant')
            ->filter(fn (array $message) => str_contains((string) $message['content'], 'Mau saya cek bagian prompt yang mana?'))
            ->count();

        $this->assertSame(3, $assistantReplies);
    }

    public function test_livewire_prompt_chat_menurutmu_question_does_not_create_version(): void
    {
        $calls = [];
        Http::fake([
            '*/api/prompts/chat' => Http::response($this->fakePromptChatResponse([
                'intent' => 'answer',
                'assistant_message' => 'Menurut saya prompt ini sudah kuat; yang bisa ditingkatkan adalah instruksi tipografi dan ruang kosong.',
                'revision_instruction' => '',
            ]), 200),
            '*/api/prompts/generate' => function ($request) use (&$calls) {
                $calls[] = $request->data();

                return Http::response($this->fakePackageResponse([
                    'main_prompt' => 'A first draft prompt.',
                ]), 200);
            },
        ]);
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)
            ->test(PrompyStudio::class)
            ->set('idea', 'Buat prompt logo komunitas')
            ->call('selectPlatform', 'gpt_image_2')
            ->call('selectPromptType', 'image')
            ->call('generate')
            ->call('sendPromptChat', 'menurutmu untuk apa yg perlu ditingkatkan dari prompt tersebut')
            ->assertSee('yang bisa ditingkatkan adalah instruksi tipografi');

        $prompt = GeneratedPrompt::with('versions')->findOrFail($component->get('activePromptId'));
        $this->assertSame(1, $prompt->versions()->count());
        $this->assertCount(1, $calls);
        $this->assertCount(2, $prompt->chat_messages);
        $this->assertSame('user', $prompt->chat_messages[0]['role'] ?? null);
        $this->assertSame('assistant', $prompt->chat_messages[1]['role'] ?? null);
    }

    public function test_livewire_prompt_chat_test_message_stays_conversational_without_new_version(): void
    {
        $calls = [];
        Http::fake([
            '*/api/prompts/chat' => function ($request) {
                $message = (string) ($request->data()['message'] ?? '');
                $reply = str_contains($message, 'kurang')
                    ? 'Prompt ini sudah cukup jelas; yang bisa diperkuat adalah arahan hierarchy teks dan white space.'
                    : 'Tes diterima. Mau saya cek atau revisi bagian tertentu dari prompt ini?';

                return Http::response($this->fakePromptChatResponse([
                    'intent' => 'answer',
                    'assistant_message' => $reply,
                ]), 200);
            },
            '*/api/prompts/generate' => function ($request) use (&$calls) {
                $calls[] = $request->data();

                return Http::response($this->fakePackageResponse([
                    'main_prompt' => 'A first draft prompt.',
                ]), 200);
            },
        ]);
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)
            ->test(PrompyStudio::class)
            ->set('idea', 'Buat Poster 1 Muharram 1448 H')
            ->call('selectPlatform', 'gpt_image_2')
            ->call('selectPromptType', 'poster_infographic')
            ->call('generate')
            ->call('sendPromptChat', 'apa yg kurang?')
            ->assertSee('hierarchy teks dan white space')
            ->call('sendPromptChat', 'Tesin')
            ->assertSee('Tes diterima');

        $prompt = GeneratedPrompt::with('versions')->findOrFail($component->get('activePromptId'));
        $this->assertSame(1, $prompt->versions()->count());
        $this->assertCount(1, $calls);
        $this->assertCount(4, $prompt->chat_messages);

        $chatEvents = AIUsageEvent::where('feature', AIUsageEvent::FEATURE_PROMPT_GENERATION)
            ->where('subject_id', $prompt->id)
            ->get()
            ->filter(fn (AIUsageEvent $event) => ($event->metadata['channel'] ?? null) === 'prompt_chat')
            ->values();

        $this->assertCount(2, $chatEvents);
        $this->assertSame('answer', $chatEvents[0]->metadata['outcome'] ?? null);
        $this->assertSame('answer', $chatEvents[1]->metadata['outcome'] ?? null);
        $this->assertSame(3, $chatEvents[0]->metadata['history_message_count'] ?? null);
        $this->assertSame(5, $chatEvents[1]->metadata['history_message_count'] ?? null);
        $this->assertArrayNotHasKey('message_content', $chatEvents[0]->metadata ?? []);
    }

    public function test_livewire_active_prompt_configuration_regenerates_same_history_item(): void
    {
        $calls = [];
        Http::fake([
            '*/api/prompts/generate' => function ($request) use (&$calls) {
                $data = $request->data();
                $calls[] = $data;

                if (! empty($data['revision_instruction'])) {
                    return Http::response($this->fakePackageResponse([
                        'platform' => 'canva_ai',
                        'platform_label' => 'Canva AI',
                        'prompt_type' => 'image',
                        'prompt_type_label' => 'Gambar',
                        'main_prompt' => 'A regenerated Canva-ready image prompt.',
                    ]), 200);
                }

                return Http::response($this->fakePackageResponse([
                    'main_prompt' => 'A first configured prompt.',
                ]), 200);
            },
        ]);
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)
            ->test(PrompyStudio::class)
            ->set('idea', 'Buat prompt poster awal')
            ->call('selectPlatform', 'gpt_image_2')
            ->call('selectPromptType', 'poster_infographic')
            ->call('generate')
            ->set('showPromptConfiguration', true)
            ->set('idea', 'Buat prompt gambar Canva')
            ->call('selectPlatform', 'canva_ai')
            ->call('selectPromptType', 'image')
            ->call('generate')
            ->assertSet('showPromptConfiguration', false)
            ->assertSee('Versi prompt')
            ->assertSee('Versi 2')
            ->assertSee('Buat prompt gambar Canva')
            ->assertSee('Tulis pesan untuk membahas prompt ini...');

        $promptId = $component->get('activePromptId');
        $this->assertDatabaseCount('generated_prompts', 1);
        $prompt = GeneratedPrompt::with('versions')->findOrFail($promptId);
        $this->assertSame(2, $prompt->versions()->count());
        $this->assertSame('canva_ai', $prompt->platform);
        $this->assertSame('image', $prompt->prompt_type);
        $this->assertStringContainsString('konfigurasi terbaru', $prompt->currentVersion?->revision_instruction);
        $this->assertSame('canva_ai', $calls[1]['platform'] ?? null);
        $this->assertSame('image', $calls[1]['prompt_type'] ?? null);
        $this->assertSame('A first configured prompt.', $calls[1]['current_package']['main_prompt'] ?? null);
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
            ->call('selectPlatform', 'gpt_image_2')
            ->call('selectPromptType', 'poster_infographic')
            ->call('generate');

        $this->assertDatabaseCount('generated_prompts', 0);
    }
}
