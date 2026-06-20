<?php

namespace Tests\Feature\Presentations;

use App\Livewire\Presentations\PresentationWorkspace;
use App\Models\Presentation;
use App\Models\PresentationVersion;
use App\Models\User;
use App\Services\OnlyOffice\JwtSigner;
use App\Services\OnlyOffice\PresentationDocumentKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class PresentationOnlyOfficeTest extends TestCase
{
    use RefreshDatabase;

    private function readyPresentation(User $user): Presentation
    {
        $path = 'presentations/'.$user->id.'/deck.pptx';
        Storage::disk('local')->put($path, $this->validPresentationPptxBytes());

        $presentation = Presentation::create([
            'user_id' => $user->id,
            'title' => 'Paparan Resmi',
            'status' => Presentation::STATUS_READY,
            'visual_template' => 'resmi_klasik',
            'pptx_path' => $path,
            'generated_at' => now(),
        ]);

        $version = $presentation->versions()->create([
            'version_number' => 1,
            'label' => 'Versi 1',
            'pptx_path' => $path,
            'status' => Presentation::STATUS_READY,
        ]);

        $presentation->forceFill(['current_version_id' => $version->id])->save();

        return $presentation->refresh();
    }

    private function callbackKey(Presentation $presentation, ?PresentationVersion $version = null): string
    {
        return app(PresentationDocumentKey::class)->forEditor(
            $presentation->refresh(),
            $version?->refresh()
        );
    }

    private function pathWithQuery(string $absoluteUrl): string
    {
        $parts = parse_url($absoluteUrl);
        $path = $parts['path'] ?? '/';

        return $path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    // ── Editor config ───────────────────────────────────────────────────────

    public function test_edit_presentasi_action_builds_slides_editor_config(): void
    {
        Storage::fake('local');
        config([
            'services.onlyoffice.jwt_secret' => 'editor-secret',
            'services.onlyoffice.public_url' => 'https://ista-ai.app',
            'services.onlyoffice.laravel_internal_url' => 'http://laravel:8000',
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $presentation = $this->readyPresentation($user);

        $component = Livewire::actingAs($user)->test(PresentationWorkspace::class)
            ->call('editPresentation', $presentation->id);

        $config = $component->instance()->editorConfig();

        $this->assertNotNull($config);
        $this->assertSame('slide', $config['documentType']);
        $this->assertSame('pptx', $config['document']['fileType']);
        $this->assertStringStartsWith('presentation-'.$presentation->id.'-', $config['document']['key']);
        $this->assertStringContainsString('signed-file', $config['document']['url']);
        $this->assertStringContainsString('oo_token=', $config['document']['url']);
        $this->assertStringStartsWith('http://laravel:8000', $config['editorConfig']['callbackUrl']);
        $this->assertArrayHasKey('token', $config);
    }

    public function test_edit_rejected_when_presentation_not_ready(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['email_verified_at' => now()]);
        $presentation = Presentation::create([
            'user_id' => $user->id,
            'title' => 'Belum siap',
            'status' => Presentation::STATUS_PROCESSING,
            'visual_template' => 'resmi_klasik',
        ]);

        $component = Livewire::actingAs($user)->test(PresentationWorkspace::class)
            ->call('editPresentation', $presentation->id);

        $this->assertNull($component->instance()->editorConfig());
    }

    // ── Signed file endpoint + token ─────────────────────────────────────────

    public function test_signed_file_route_rejects_request_without_token(): void
    {
        Storage::fake('local');
        config(['services.onlyoffice.laravel_internal_url' => 'http://laravel:8000']);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $presentation = $this->readyPresentation($user);

        // Authenticated owner but missing oo_token + no signature => forbidden.
        $this->actingAs($user)
            ->get(route('presentations.file.signed', $presentation))
            ->assertForbidden();
    }

    public function test_owner_can_fetch_signed_file_with_valid_token(): void
    {
        Storage::fake('local');
        // Internal origin = test host so the HMAC signature validates over the
        // same origin the test request hits.
        config(['services.onlyoffice.laravel_internal_url' => 'http://localhost']);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $presentation = $this->readyPresentation($user);
        $versionId = $presentation->current_version_id;

        $url = app(PresentationDocumentKey::class)->signedFileUrl($presentation, $versionId, 30);

        $this->actingAs($user)
            ->get($this->pathWithQuery($url))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.presentationml.presentation');
    }

    public function test_signed_file_rejects_invalid_token(): void
    {
        Storage::fake('local');
        config(['services.onlyoffice.laravel_internal_url' => 'http://localhost']);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $presentation = $this->readyPresentation($user);
        $versionId = $presentation->current_version_id;

        $url = app(PresentationDocumentKey::class)->signedFileUrl($presentation, $versionId, 30);
        // Swap the valid token for a bogus one — signature stays valid but the
        // token no longer exists in cache, so the request must be rejected.
        $tampered = preg_replace('/oo_token=[^&]+/', 'oo_token='.Str::random(40), $this->pathWithQuery($url));

        $this->actingAs($user)->get($tampered)->assertForbidden();
    }

    // ── Callback ─────────────────────────────────────────────────────────────

    public function test_callback_route_has_throttle_middleware(): void
    {
        $route = Arr::first(app('router')->getRoutes(), fn ($route) => $route->getName() === 'presentations.onlyoffice.callback');

        $this->assertNotNull($route);
        $hasThrottle = (bool) collect($route->gatherMiddleware())->first(
            fn ($middleware) => Str::startsWith($middleware, 'throttle:')
        );

        $this->assertTrue($hasThrottle);
    }

    public function test_callback_rejects_missing_token(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['email_verified_at' => now()]);
        $presentation = $this->readyPresentation($user);

        $this->postJson(route('presentations.onlyoffice.callback', $presentation), [
            'status' => 2,
            'key' => 'presentation-'.$presentation->id.'-v1-x',
            'url' => 'https://onlyoffice.test/file.pptx',
        ])->assertUnauthorized();
    }

    public function test_callback_with_valid_token_saves_pptx(): void
    {
        config([
            'services.onlyoffice.jwt_secret' => 'callback-secret',
            'services.onlyoffice.internal_url' => 'https://onlyoffice.test',
        ]);
        Storage::fake('local');
        Http::fake([
            'https://onlyoffice.test/file.pptx' => Http::response($this->validPresentationPptxBytes(), 200),
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $presentation = $this->readyPresentation($user);
        $version = $presentation->currentVersion;
        $key = $this->callbackKey($presentation, $version);
        $url = 'https://onlyoffice.test/file.pptx';
        $token = (new JwtSigner('callback-secret'))->sign([
            'status' => 6,
            'key' => $key,
            'url' => $url,
            'exp' => time() + 60,
        ]);

        $this->postJson(route('presentations.onlyoffice.callback', ['presentation' => $presentation->id, 'version_id' => $version->id]), [
            'status' => 6,
            'key' => $key,
            'url' => $url,
            'token' => $token,
        ])->assertOk()->assertJson(['error' => 0]);

        $presentation->refresh();
        $this->assertSame(Presentation::STATUS_EDITED, $presentation->status);
        $this->assertSame(Presentation::STATUS_EDITED, $presentation->currentVersion?->status);
        $this->assertTrue($presentation->isReady());
        Storage::disk('local')->assertExists($presentation->pptx_path);
    }

    public function test_callback_rejects_stale_document_key(): void
    {
        config([
            'services.onlyoffice.jwt_secret' => 'callback-secret',
            'services.onlyoffice.internal_url' => 'https://onlyoffice.test',
        ]);
        Storage::fake('local');
        Http::fake();

        $user = User::factory()->create(['email_verified_at' => now()]);
        $presentation = $this->readyPresentation($user);
        $version = $presentation->currentVersion;

        // Use a key shaped correctly but not matching the current editor key.
        $staleKey = 'presentation-'.$presentation->id.'-v'.$version->id.'-0000000000-deadbeefdead';
        $token = (new JwtSigner('callback-secret'))->sign([
            'status' => 6,
            'key' => $staleKey,
            'url' => 'https://onlyoffice.test/file.pptx',
            'exp' => time() + 60,
        ]);

        $this->postJson(route('presentations.onlyoffice.callback', ['presentation' => $presentation->id, 'version_id' => $version->id]), [
            'status' => 6,
            'key' => $staleKey,
            'url' => 'https://onlyoffice.test/file.pptx',
            'token' => $token,
        ])->assertStatus(409);

        Http::assertNothingSent();
    }

    public function test_callback_rejects_untrusted_download_url(): void
    {
        config([
            'services.onlyoffice.jwt_secret' => 'callback-secret',
            'services.onlyoffice.internal_url' => 'https://onlyoffice.test',
        ]);
        Storage::fake('local');
        Http::fake();

        $user = User::factory()->create(['email_verified_at' => now()]);
        $presentation = $this->readyPresentation($user);
        $version = $presentation->currentVersion;
        $key = $this->callbackKey($presentation, $version);
        $url = 'https://evil.test/file.pptx';
        $token = (new JwtSigner('callback-secret'))->sign([
            'status' => 6,
            'key' => $key,
            'url' => $url,
            'exp' => time() + 60,
        ]);

        $this->postJson(route('presentations.onlyoffice.callback', ['presentation' => $presentation->id, 'version_id' => $version->id]), [
            'status' => 6,
            'key' => $key,
            'url' => $url,
            'token' => $token,
        ])->assertForbidden();

        Http::assertNothingSent();
    }

    // ── Force-save before download ───────────────────────────────────────────

    public function test_force_save_triggers_onlyoffice_command(): void
    {
        config([
            'services.onlyoffice.jwt_secret' => 'force-secret',
            'services.onlyoffice.internal_url' => 'https://onlyoffice.test',
            'services.onlyoffice.force_save_wait_seconds' => 1,
            'services.onlyoffice.force_save_poll_microseconds' => 50000,
        ]);
        Storage::fake('local');
        Http::fake([
            'https://onlyoffice.test/command*' => Http::response(['error' => 4], 200),
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $presentation = $this->readyPresentation($user);

        $this->actingAs($user)
            ->postJson(route('presentations.force-save', $presentation), [])
            ->assertOk()
            ->assertJson(['status' => 'no_changes']);
    }

    public function test_force_save_requires_owner(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $intruder = User::factory()->create(['email_verified_at' => now()]);
        $presentation = $this->readyPresentation($owner);

        $this->actingAs($intruder)
            ->postJson(route('presentations.force-save', $presentation), [])
            ->assertForbidden();
    }
}
