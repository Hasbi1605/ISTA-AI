<?php

namespace Tests\Feature\Presentations;

use App\Models\Presentation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PresentationFileTest extends TestCase
{
    use RefreshDatabase;

    private function readyPresentation(User $user): Presentation
    {
        $path = 'presentations/'.$user->id.'/deck.pptx';
        Storage::disk('local')->put($path, 'PK'.str_repeat('x', 200));

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

    private function fakeOnlyOfficeConversion(): void
    {
        config([
            'services.onlyoffice.jwt_secret' => 'converter-secret',
            'services.onlyoffice.internal_url' => 'http://onlyoffice',
            'services.onlyoffice.public_url' => 'https://ista-ai.app',
            'services.onlyoffice.laravel_internal_url' => 'http://laravel:8000',
        ]);

        Http::fake(function (HttpRequest $request) {
            if (str_starts_with($request->url(), 'http://onlyoffice/converter')) {
                return Http::response([
                    'endConvert' => true,
                    'fileType' => 'pdf',
                    'fileUrl' => 'https://ista-ai.app/cache/presentation.pdf',
                    'percent' => 100,
                ], 200);
            }

            if ($request->url() === 'https://ista-ai.app/cache/presentation.pdf') {
                return Http::response('%PDF-presentation', 200, ['Content-Type' => 'application/pdf']);
            }

            return Http::response('unexpected', 500);
        });
    }

    public function test_owner_can_download_pptx(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['email_verified_at' => now()]);
        $presentation = $this->readyPresentation($user);

        $response = $this->actingAs($user)->get(route('presentations.download', $presentation));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.presentationml.presentation');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_non_owner_cannot_download_pptx(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $intruder = User::factory()->create(['email_verified_at' => now()]);
        $presentation = $this->readyPresentation($owner);

        $this->actingAs($intruder)
            ->get(route('presentations.download', $presentation))
            ->assertForbidden();
    }

    public function test_export_pdf_returns_pdf_via_onlyoffice(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['email_verified_at' => now()]);
        $presentation = $this->readyPresentation($user);
        $this->fakeOnlyOfficeConversion();

        $response = $this->actingAs($user)->get(route('presentations.export.pdf', $presentation));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame('%PDF-presentation', $response->getContent());

        $presentation->refresh();
        $this->assertNotNull($presentation->pdf_path);
        Storage::disk('local')->assertExists($presentation->pdf_path);
    }

    public function test_export_pdf_reuses_cached_pdf(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['email_verified_at' => now()]);
        $presentation = $this->readyPresentation($user);
        $pdfPath = 'presentations/'.$user->id.'/deck.pdf';
        Storage::disk('local')->put($pdfPath, '%PDF-cached');
        $presentation->forceFill(['pdf_path' => $pdfPath])->save();

        Http::fake(); // should not be called

        $response = $this->actingAs($user)->get(route('presentations.export.pdf', $presentation));

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame('%PDF-cached', $response->getContent());
        Http::assertNothingSent();
    }

    public function test_export_pdf_returns_clear_error_when_conversion_fails(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['email_verified_at' => now()]);
        $presentation = $this->readyPresentation($user);

        config([
            'services.onlyoffice.jwt_secret' => 'converter-secret',
            'services.onlyoffice.internal_url' => 'http://onlyoffice',
            'services.onlyoffice.public_url' => 'https://ista-ai.app',
        ]);
        Http::fake([
            'http://onlyoffice/converter*' => Http::response('boom', 500),
        ]);

        $this->actingAs($user)
            ->get(route('presentations.export.pdf', $presentation))
            ->assertStatus(502);

        $presentation->refresh();
        $this->assertNull($presentation->pdf_path);
    }

    public function test_signed_file_route_rejects_request_without_signature(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['email_verified_at' => now()]);
        $presentation = $this->readyPresentation($user);

        $this->get(route('presentations.file.signed', $presentation))->assertForbidden();
    }
}
