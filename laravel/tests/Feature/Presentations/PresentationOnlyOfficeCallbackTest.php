<?php

namespace Tests\Feature\Presentations;

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
use Tests\TestCase;
use ZipArchive;

class PresentationOnlyOfficeCallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_callback_route_has_light_throttle_middleware(): void
    {
        $route = Arr::first(app('router')->getRoutes(), fn ($route) => $route->getName() === 'onlyoffice.presentation.callback');

        $this->assertNotNull($route);
        $hasThrottle = (bool) collect($route->gatherMiddleware())->first(
            fn ($middleware) => Str::startsWith($middleware, 'throttle:')
        );

        $this->assertTrue($hasThrottle);
    }

    public function test_callback_rejects_missing_token(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $presentation = $this->createPresentation($user);

        $this->postJson(route('onlyoffice.presentation.callback', $presentation), [
            'status' => 2,
            'key' => 'presentation-'.$presentation->id.'-123',
            'url' => 'https://onlyoffice.test/file.pptx',
        ])->assertUnauthorized();
    }

    public function test_callback_rejects_token_without_exp(): void
    {
        $this->configureOnlyOffice();
        Http::fake();

        $user = User::factory()->create(['email_verified_at' => now()]);
        $presentation = $this->createPresentation($user);
        $key = $this->callbackKey($presentation);
        $url = 'https://onlyoffice.test/file.pptx';

        $token = (new JwtSigner('callback-secret'))->sign([
            'status' => 2,
            'key' => $key,
            'url' => $url,
        ]);

        $this->postJson(route('onlyoffice.presentation.callback', $presentation), [
            'status' => 2,
            'key' => $key,
            'url' => $url,
            'token' => $token,
        ])->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_callback_with_valid_token_saves_pptx_and_invalidates_pdf(): void
    {
        $this->configureOnlyOffice();
        Storage::fake('local');
        Http::fake([
            'https://onlyoffice.test/file.pptx' => Http::response($this->validPptxBytes(), 200),
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $presentation = $this->createPresentation($user, withPdf: true);
        $version = $presentation->currentVersion()->firstOrFail();
        $key = $this->callbackKey($presentation, $version);
        $url = 'https://onlyoffice.test/file.pptx';
        $token = (new JwtSigner('callback-secret'))->sign([
            'status' => 2,
            'key' => $key,
            'url' => $url,
            'exp' => time() + 60,
        ]);

        $this->postJson(route('onlyoffice.presentation.callback', [
            'presentation' => $presentation,
            'version_id' => $version->id,
        ]), [
            'status' => 2,
            'key' => $key,
            'url' => $url,
            'token' => $token,
        ])->assertOk()->assertJson(['error' => 0]);

        $presentation->refresh();
        $version->refresh();
        $this->assertSame(PresentationVersion::STATUS_EDITED, $version->status);
        $this->assertSame($version->pptx_path, $presentation->pptx_path);
        $this->assertNull($presentation->pdf_path);
        Storage::disk('local')->assertExists($presentation->pptx_path);
        $this->assertStringStartsWith("PK\x03\x04", Storage::disk('local')->get($presentation->pptx_path));
    }

    public function test_callback_rejects_tampered_token(): void
    {
        $this->configureOnlyOffice();
        $user = User::factory()->create(['email_verified_at' => now()]);
        $presentation = $this->createPresentation($user);
        $key = $this->callbackKey($presentation);
        $url = 'https://onlyoffice.test/file.pptx';
        $token = (new JwtSigner('callback-secret'))->sign([
            'status' => 2,
            'key' => $key,
            'url' => $url,
            'exp' => time() + 60,
        ]);

        $this->postJson(route('onlyoffice.presentation.callback', $presentation), [
            'status' => 2,
            'key' => $key,
            'url' => $url,
            'token' => $token.'x',
        ])->assertUnauthorized();
    }

    public function test_callback_rejects_untrusted_download_url(): void
    {
        $this->configureOnlyOffice();
        Http::fake();

        $user = User::factory()->create(['email_verified_at' => now()]);
        $presentation = $this->createPresentation($user);
        $version = $presentation->currentVersion()->firstOrFail();
        $key = $this->callbackKey($presentation, $version);
        $url = 'https://evil.test/file.pptx';
        $token = (new JwtSigner('callback-secret'))->sign([
            'status' => 2,
            'key' => $key,
            'url' => $url,
            'exp' => time() + 60,
        ]);

        $this->postJson(route('onlyoffice.presentation.callback', [
            'presentation' => $presentation,
            'version_id' => $version->id,
        ]), [
            'status' => 2,
            'key' => $key,
            'url' => $url,
            'token' => $token,
        ])->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_callback_rejects_stale_document_key(): void
    {
        $this->configureOnlyOffice();
        Storage::fake('local');
        Http::fake([
            'https://onlyoffice.test/stale.pptx' => Http::response($this->validPptxBytes(), 200),
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $presentation = $this->createPresentation($user);
        $version = $presentation->currentVersion()->firstOrFail();

        $key = 'presentation-'.$presentation->id.'-v'.$version->id.'-stale';
        $url = 'https://onlyoffice.test/stale.pptx';
        $token = (new JwtSigner('callback-secret'))->sign([
            'status' => 2,
            'key' => $key,
            'url' => $url,
            'exp' => time() + 60,
        ]);

        $this->postJson(route('onlyoffice.presentation.callback', [
            'presentation' => $presentation,
            'version_id' => $version->id,
        ]), [
            'status' => 2,
            'key' => $key,
            'url' => $url,
            'token' => $token,
        ])->assertConflict();

        Http::assertNothingSent();
    }

    public function test_callback_rejects_non_pptx_response_body(): void
    {
        $this->configureOnlyOffice();
        Storage::fake('local');
        Http::fake([
            'https://onlyoffice.test/bad.pptx' => Http::response('<html>error</html>', 200),
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $presentation = $this->createPresentation($user);
        $version = $presentation->currentVersion()->firstOrFail();
        Storage::disk('local')->put($presentation->pptx_path, 'PK'.str_repeat('x', 200));

        $key = $this->callbackKey($presentation, $version);
        $url = 'https://onlyoffice.test/bad.pptx';
        $token = (new JwtSigner('callback-secret'))->sign([
            'status' => 2,
            'key' => $key,
            'url' => $url,
            'exp' => time() + 60,
        ]);

        $this->postJson(route('onlyoffice.presentation.callback', [
            'presentation' => $presentation,
            'version_id' => $version->id,
        ]), [
            'status' => 2,
            'key' => $key,
            'url' => $url,
            'token' => $token,
        ])->assertStatus(502);

        // File asli tidak boleh tertimpa.
        $this->assertSame('PK'.str_repeat('x', 200), Storage::disk('local')->get($presentation->pptx_path));
    }

    public function test_callback_replay_is_rejected_after_successful_save(): void
    {
        $this->configureOnlyOffice();
        Storage::fake('local');
        Http::fake([
            'https://onlyoffice.test/replay.pptx' => Http::response($this->validPptxBytes(), 200),
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $presentation = $this->createPresentation($user);
        $version = $presentation->currentVersion()->firstOrFail();
        $key = $this->callbackKey($presentation, $version);
        $url = 'https://onlyoffice.test/replay.pptx';
        $token = (new JwtSigner('callback-secret'))->sign([
            'status' => 2,
            'key' => $key,
            'url' => $url,
            'exp' => time() + 60,
        ]);

        $payload = ['status' => 2, 'key' => $key, 'url' => $url, 'token' => $token];
        $route = route('onlyoffice.presentation.callback', [
            'presentation' => $presentation,
            'version_id' => $version->id,
        ]);

        $this->postJson($route, $payload)->assertOk()->assertJson(['error' => 0]);
        $this->postJson($route, $payload)->assertConflict();
    }

    public function test_callback_status_1_acknowledges_without_saving(): void
    {
        $this->configureOnlyOffice();
        Storage::fake('local');
        Http::fake();

        $user = User::factory()->create(['email_verified_at' => now()]);
        $presentation = $this->createPresentation($user);
        $version = $presentation->currentVersion()->firstOrFail();
        Storage::disk('local')->put($presentation->pptx_path, 'original');

        $key = $this->callbackKey($presentation, $version);
        $token = (new JwtSigner('callback-secret'))->sign([
            'status' => 1,
            'key' => $key,
            'exp' => time() + 60,
        ]);

        $this->postJson(route('onlyoffice.presentation.callback', [
            'presentation' => $presentation,
            'version_id' => $version->id,
        ]), [
            'status' => 1,
            'key' => $key,
            'token' => $token,
        ])->assertOk()->assertJson(['error' => 0]);

        Http::assertNothingSent();
        $this->assertSame('original', Storage::disk('local')->get($presentation->pptx_path));
    }

    public function test_callback_rejects_url_with_path_traversal(): void
    {
        $this->configureOnlyOffice();
        Http::fake();

        $user = User::factory()->create(['email_verified_at' => now()]);
        $presentation = $this->createPresentation($user);
        $version = $presentation->currentVersion()->firstOrFail();
        $key = $this->callbackKey($presentation, $version);
        $url = 'https://onlyoffice.test/../../../etc/passwd';
        $token = (new JwtSigner('callback-secret'))->sign([
            'status' => 2,
            'key' => $key,
            'url' => $url,
            'exp' => time() + 60,
        ]);

        $this->postJson(route('onlyoffice.presentation.callback', [
            'presentation' => $presentation,
            'version_id' => $version->id,
        ]), [
            'status' => 2,
            'key' => $key,
            'url' => $url,
            'token' => $token,
        ])->assertForbidden();

        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function configureOnlyOffice(): void
    {
        config([
            'services.onlyoffice.jwt_secret' => 'callback-secret',
            'services.onlyoffice.internal_url' => 'https://onlyoffice.test',
            'services.onlyoffice.laravel_internal_url' => 'http://laravel:8000',
        ]);
    }

    private function callbackKey(Presentation $presentation, ?PresentationVersion $version = null): string
    {
        return app(PresentationDocumentKey::class)->forEditor($presentation->refresh(), $version?->refresh());
    }

    private function createPresentation(User $user, bool $withPdf = false): Presentation
    {
        $pptxPath = 'presentations/'.$user->id.'/deck.pptx';
        Storage::disk('local')->put($pptxPath, 'PK'.str_repeat('x', 200));

        $attributes = [
            'user_id' => $user->id,
            'title' => 'Paparan Resmi',
            'status' => Presentation::STATUS_READY,
            'visual_template' => 'resmi_klasik',
            'pptx_path' => $pptxPath,
            'generated_at' => now(),
        ];

        if ($withPdf) {
            $pdfPath = 'presentations/'.$user->id.'/deck.pdf';
            Storage::disk('local')->put($pdfPath, '%PDF-cached');
            $attributes['pdf_path'] = $pdfPath;
        }

        $presentation = Presentation::create($attributes);

        $version = $presentation->versions()->create([
            'version_number' => 1,
            'label' => 'Versi 1',
            'pptx_path' => $pptxPath,
            'status' => PresentationVersion::STATUS_GENERATED,
        ]);

        $presentation->forceFill(['current_version_id' => $version->id])->save();

        return $presentation->refresh();
    }

    /**
     * Bangun bytes PPTX minimal yang valid (arsip ZIP OOXML presentation).
     */
    private function validPptxBytes(): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'test-pptx-');

        $zip = new ZipArchive;
        $zip->open($tempPath, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>');
        $zip->addFromString('ppt/presentation.xml', '<?xml version="1.0"?><p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"></p:presentation>');
        $zip->close();

        $bytes = (string) file_get_contents($tempPath);
        @unlink($tempPath);

        return $bytes;
    }
}
