<?php

namespace Tests\Feature\Documents;

use App\Models\Document;
use App\Models\User;
use App\Services\DocumentExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class DocumentExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_route_streams_download_for_authorized_user(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $service = Mockery::mock(DocumentExportService::class);
        $service->shouldReceive('exportContent')
            ->once()
            ->with('<p>Isi jawaban</p>', 'pdf', 'jawaban-ai')
            ->andReturn([
                'body' => '%PDF-1.4 fake',
                'content_type' => 'application/pdf',
                'file_name' => 'jawaban-ai.pdf',
            ]);

        $this->app->instance(DocumentExportService::class, $service);

        $response = $this->actingAs($user)->post(route('documents.export'), [
            'content_html' => '<p>Isi jawaban</p>',
            'target_format' => 'pdf',
            'file_name' => 'jawaban-ai',
        ]);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('attachment; filename="jawaban-ai.pdf"', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('%PDF-1.4 fake', $response->getContent());
    }

    public function test_export_route_rejects_oversized_content_and_does_not_call_service(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $oversized = str_repeat('A', 512001);

        $service = Mockery::mock(DocumentExportService::class);
        $service->shouldNotReceive('exportContent');
        $this->app->instance(DocumentExportService::class, $service);

        $response = $this->actingAs($user)->post(route('documents.export'), [
            'content_html' => $oversized,
            'target_format' => 'pdf',
            'file_name' => 'jawaban-ai',
        ]);

        $response->assertSessionHasErrors(['content_html']);
    }

    public function test_export_route_rejects_spreadsheet_without_table_and_does_not_call_service(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $service = Mockery::mock(DocumentExportService::class);
        $service->shouldNotReceive('exportContent');
        $this->app->instance(DocumentExportService::class, $service);

        $response = $this->actingAs($user)->post(route('documents.export'), [
            'content_html' => '<article><p>Jawaban naratif tanpa tabel.</p></article>',
            'target_format' => 'xlsx',
            'file_name' => 'jawaban-ai',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString(
            'Format spreadsheet hanya tersedia untuk konten yang memiliki tabel.',
            (string) $response->getContent()
        );
    }

    public function test_export_route_sanitizes_html_with_parser_allowlist(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $capturedHtml = null;

        $service = Mockery::mock(DocumentExportService::class);
        $service->shouldReceive('exportContent')
            ->once()
            ->withArgs(function (string $contentHtml, string $targetFormat, string $fileName) use (&$capturedHtml) {
                $capturedHtml = $contentHtml;

                return $targetFormat === 'pdf' && $fileName === 'sanitized';
            })
            ->andReturn([
                'body' => '%PDF-1.4 fake',
                'content_type' => 'application/pdf',
                'file_name' => 'sanitized.pdf',
            ]);
        $this->app->instance(DocumentExportService::class, $service);

        $this->actingAs($user)->post(route('documents.export'), [
            'content_html' => implode('', [
                '<script>alert("x")</script>',
                '<p onclick="alert(1)" style="background:url(https://evil.test/a.png)">Aman</p>',
                '<iframe src="https://evil.test/embed"></iframe>',
                '<img src="https://evil.test/leak.png" alt="leak">',
                '<a href="javascript:alert(1)">link</a>',
                '<a href="https://example.com/rujukan">rujukan</a>',
                '<foo><script>alert("nested")</script><strong>tetap aman</strong></foo>',
                '<p>Karakter Indonesia: surat izin kegiatan ñ é</p>',
                '<table style="color:red"><tr><th colspan="2" onmouseover="alert(1)">Nama</th></tr><tr><td>Hasbi</td></tr></table>',
            ]),
            'target_format' => 'pdf',
            'file_name' => 'sanitized',
        ])->assertOk();

        $this->assertIsString($capturedHtml);
        $this->assertStringContainsString('<p>Aman</p>', $capturedHtml);
        $this->assertStringContainsString('<strong>tetap aman</strong>', $capturedHtml);
        $this->assertStringContainsString('<a href="https://example.com/rujukan">rujukan</a>', $capturedHtml);
        $this->assertStringContainsString('Karakter Indonesia: surat izin kegiatan ñ é', $capturedHtml);
        $this->assertStringContainsString('<table>', $capturedHtml);
        $this->assertStringContainsString('<th colspan="2">Nama</th>', $capturedHtml);
        $this->assertStringNotContainsString('<script', $capturedHtml);
        $this->assertStringNotContainsString('<iframe', $capturedHtml);
        $this->assertStringNotContainsString('<img', $capturedHtml);
        $this->assertStringNotContainsString('onclick', $capturedHtml);
        $this->assertStringNotContainsString('onmouseover', $capturedHtml);
        $this->assertStringNotContainsString('style=', $capturedHtml);
        $this->assertStringNotContainsString('javascript:', $capturedHtml);
        $this->assertStringNotContainsString('https://evil.test', $capturedHtml);
    }

    public function test_export_service_sanitizes_content_before_forwarding_to_python(): void
    {
        config([
            'services.ai_document_service.url' => 'http://document-service.test',
            'services.ai_document_service.token' => 'document-token',
        ]);

        $forwardedHtml = null;

        Http::fake(function ($request) use (&$forwardedHtml) {
            $forwardedHtml = $request->data()['content_html'] ?? null;

            return Http::response('%PDF-1.4 sanitized');
        });

        $service = app(DocumentExportService::class);
        $artifact = $service->exportContent(implode('', [
            '<article><p>Isi aman ñ é.</p>',
            '<foo><script>alert("nested")</script><strong>Teks</strong></foo>',
            '<a href="https://example.com/ref">Referensi</a>',
            '<img src="https://evil.test/leak.png">',
            '</article>',
        ]), 'pdf', 'sanitized-service');

        $this->assertSame('%PDF-1.4 sanitized', $artifact['body']);
        $this->assertIsString($forwardedHtml);
        $this->assertStringContainsString('Isi aman ñ é.', $forwardedHtml);
        $this->assertStringContainsString('<strong>Teks</strong>', $forwardedHtml);
        $this->assertStringContainsString('<a href="https://example.com/ref">Referensi</a>', $forwardedHtml);
        $this->assertStringNotContainsString('<script', $forwardedHtml);
        $this->assertStringNotContainsString('<img', $forwardedHtml);
        $this->assertStringNotContainsString('https://evil.test', $forwardedHtml);
    }

    public function test_documents_export_route_has_throttle_middleware(): void
    {
        $route = Arr::first(app('router')->getRoutes(), fn ($route) => $route->getName() === 'documents.export');

        $this->assertNotNull($route);
        $middlewares = $route->gatherMiddleware();

        $hasThrottle = (bool) collect($middlewares)->first(fn ($middleware) => Str::startsWith($middleware, 'throttle:'));

        $this->assertTrue($hasThrottle);
    }

    public function test_documents_content_html_route_has_throttle_middleware(): void
    {
        $route = Arr::first(app('router')->getRoutes(), fn ($route) => $route->getName() === 'documents.content-html');

        $this->assertNotNull($route);
        $middlewares = $route->gatherMiddleware();

        $hasThrottle = (bool) collect($middlewares)->first(fn ($middleware) => Str::startsWith($middleware, 'throttle:'));

        $this->assertTrue($hasThrottle);
    }

    public function test_documents_extract_tables_route_has_throttle_middleware(): void
    {
        $route = Arr::first(app('router')->getRoutes(), fn ($route) => $route->getName() === 'documents.extract-tables');

        $this->assertNotNull($route);
        $middlewares = $route->gatherMiddleware();

        $hasThrottle = (bool) collect($middlewares)->first(fn ($middleware) => Str::startsWith($middleware, 'throttle:'));

        $this->assertTrue($hasThrottle);
    }

    public function test_extract_tables_route_returns_document_tables_for_owner(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['email_verified_at' => now()]);
        $document = $this->createDocument($user, 'application/pdf', 'sample.pdf');

        Storage::disk('local')->put($document->file_path, '%PDF-1.4 fake');

        $service = Mockery::mock(DocumentExportService::class);
        $service->shouldReceive('extractTables')
            ->once()
            ->with(Mockery::on(fn (Document $value) => $value->is($document)))
            ->andReturn([
                'status' => 'success',
                'filename' => 'sample.pdf',
                'tables' => [
                    [
                        'header' => ['Nama', 'Nilai'],
                        'rows' => [['A', '10']],
                    ],
                ],
            ]);

        $this->app->instance(DocumentExportService::class, $service);

        $this->actingAs($user)
            ->get(route('documents.extract-tables', $document))
            ->assertOk()
            ->assertJsonPath('tables.0.header.0', 'Nama')
            ->assertJsonPath('tables.0.rows.0.1', '10');
    }

    public function test_extract_content_route_returns_document_html_for_owner(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['email_verified_at' => now()]);
        $document = $this->createDocument($user, 'application/pdf', 'sample.pdf');

        Storage::disk('local')->put($document->file_path, '%PDF-1.4 fake');

        $service = Mockery::mock(DocumentExportService::class);
        $service->shouldReceive('extractContent')
            ->once()
            ->with(Mockery::on(fn (Document $value) => $value->is($document)))
            ->andReturn([
                'status' => 'success',
                'filename' => 'sample.pdf',
                'content_html' => '<article><p>Isi lengkap dokumen.</p></article>',
            ]);

        $this->app->instance(DocumentExportService::class, $service);

        $this->actingAs($user)
            ->get(route('documents.content-html', $document))
            ->assertOk()
            ->assertJsonPath('content_html', '<article><p>Isi lengkap dokumen.</p></article>');
    }

    public function test_extract_tables_route_forbids_non_owner(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $other = User::factory()->create(['email_verified_at' => now()]);
        $document = $this->createDocument($owner, 'application/pdf', 'sample.pdf');

        $service = Mockery::mock(DocumentExportService::class);
        $service->shouldNotReceive('extractTables');

        $this->app->instance(DocumentExportService::class, $service);

        $this->actingAs($other)
            ->get(route('documents.extract-tables', $document))
            ->assertForbidden();
    }

    public function test_extract_content_route_forbids_non_owner(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $other = User::factory()->create(['email_verified_at' => now()]);
        $document = $this->createDocument($owner, 'application/pdf', 'sample.pdf');

        $service = Mockery::mock(DocumentExportService::class);
        $service->shouldNotReceive('extractContent');

        $this->app->instance(DocumentExportService::class, $service);

        $this->actingAs($other)
            ->get(route('documents.content-html', $document))
            ->assertForbidden();
    }

    public function test_export_and_extract_routes_require_authentication(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['email_verified_at' => now()]);
        $document = $this->createDocument($user, 'application/pdf', 'sample.pdf');

        $this->post(route('documents.export'), [
            'content_html' => '<p>Isi jawaban</p>',
            'target_format' => 'pdf',
        ])->assertRedirect();

        $this->get(route('documents.content-html', $document))->assertRedirect();
        $this->get(route('documents.extract-tables', $document))->assertRedirect();
    }

    public function test_chat_route_uses_throttle_middleware(): void
    {
        $route = Arr::first(app('router')->getRoutes(), fn ($route) => $route->getName() === 'chat');

        $this->assertNotNull($route);
        $this->assertContains('throttle:30,1', $route->gatherMiddleware());
    }

    private function createDocument(User $user, string $mime, string $name): Document
    {
        return Document::create([
            'user_id' => $user->id,
            'filename' => $name,
            'original_name' => $name,
            'file_path' => 'documents/'.$user->id.'/'.$name,
            'mime_type' => $mime,
            'file_size_bytes' => 1234,
            'status' => 'ready',
            'preview_status' => Document::PREVIEW_STATUS_READY,
        ]);
    }
}
