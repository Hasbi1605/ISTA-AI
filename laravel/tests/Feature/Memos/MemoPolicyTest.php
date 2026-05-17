<?php

namespace Tests\Feature\Memos;

use App\Models\Memo;
use App\Models\MemoVersion;
use App\Models\User;
use App\Services\OnlyOffice\JwtSigner;
use App\Services\OnlyOffice\MemoForceSaveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class MemoPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_download_memo_file(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['email_verified_at' => now()]);
        $memo = $this->createMemo($user);
        $memo->forceFill(['searchable_text' => 'preview html text'])->save();
        Storage::disk('local')->put($memo->file_path, 'docx-bytes');

        $response = $this->actingAs($user)
            ->get(route('memos.download', $memo))
            ->assertOk()
            ->assertDownload('Memo-Test.docx')
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $this->assertSame('docx-bytes', file_get_contents($response->baseResponse->getFile()->getPathname()));
    }

    public function test_owner_can_download_selected_memo_version_file(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['email_verified_at' => now()]);
        $memo = $this->createMemo($user);
        [$firstVersion, $secondVersion] = $this->createMemoVersions($memo);
        Storage::disk('local')->put($firstVersion->file_path, 'docx-v1');
        Storage::disk('local')->put($secondVersion->file_path, 'docx-v2');

        $memo->forceFill([
            'file_path' => $firstVersion->file_path,
            'current_version_id' => $firstVersion->id,
        ])->save();

        $response = $this->actingAs($user)
            ->get(route('memos.download', ['memo' => $memo, 'version_id' => $secondVersion->id]))
            ->assertOk()
            ->assertDownload('Memo-Test.docx');

        $this->assertSame('docx-v2', file_get_contents($response->baseResponse->getFile()->getPathname()));
    }

    public function test_get_download_ignores_body_version_id_and_uses_query_only(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['email_verified_at' => now()]);
        $memo = $this->createMemo($user);
        [$firstVersion, $secondVersion] = $this->createMemoVersions($memo);
        Storage::disk('local')->put($firstVersion->file_path, 'docx-v1');
        Storage::disk('local')->put($secondVersion->file_path, 'docx-v2');

        $memo->forceFill([
            'file_path' => $firstVersion->file_path,
            'current_version_id' => $firstVersion->id,
        ])->save();

        $response = $this->actingAs($user)
            ->call(
                'GET',
                route('memos.download', $memo),
                [],
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode(['version_id' => $secondVersion->id])
            );

        $response->assertOk()->assertDownload('Memo-Test.docx');
        $this->assertSame('docx-v1', file_get_contents($response->baseResponse->getFile()->getPathname()));
    }

    public function test_non_owner_cannot_download_memo_file(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $other = User::factory()->create(['email_verified_at' => now()]);
        $memo = $this->createMemo($owner);
        Storage::disk('local')->put($memo->file_path, 'docx-bytes');

        $this->actingAs($other)
            ->get(route('memos.download', $memo))
            ->assertForbidden();
    }

    public function test_signed_file_route_accepts_relative_signature_for_onlyoffice(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['email_verified_at' => now()]);
        $memo = $this->createMemo($user);
        Storage::disk('local')->put($memo->file_path, 'docx-bytes');

        $ooToken = Str::random(40);
        Cache::put('oo_file_token:'.$ooToken, ['memo_id' => $memo->id, 'version_id' => null], now()->addHour());

        $path = URL::temporarySignedRoute('memos.file.signed', now()->addHour(), [
            'memo' => $memo,
            'oo_token' => $ooToken,
        ], false);

        $this->get($path)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    }

    public function test_signed_file_route_can_stream_selected_memo_version(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['email_verified_at' => now()]);
        $memo = $this->createMemo($user);
        [$firstVersion, $secondVersion] = $this->createMemoVersions($memo);
        Storage::disk('local')->put($firstVersion->file_path, 'docx-v1');
        Storage::disk('local')->put($secondVersion->file_path, 'docx-v2');

        $memo->forceFill([
            'file_path' => $firstVersion->file_path,
            'current_version_id' => $firstVersion->id,
        ])->save();

        $ooToken = Str::random(40);
        Cache::put('oo_file_token:'.$ooToken, ['memo_id' => $memo->id, 'version_id' => $secondVersion->id], now()->addHour());

        $path = URL::temporarySignedRoute('memos.file.signed', now()->addHour(), [
            'memo' => $memo,
            'version_id' => $secondVersion->id,
            'oo_token' => $ooToken,
        ], false);

        $response = $this->get($path)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $this->assertSame('docx-v2', file_get_contents($response->baseResponse->getFile()->getPathname()));
    }

    public function test_signed_file_route_rejects_token_for_different_memo_version(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $memo = $this->createMemo($user);
        [$firstVersion, $secondVersion] = $this->createMemoVersions($memo);

        $ooToken = Str::random(40);
        Cache::put('oo_file_token:'.$ooToken, [
            'memo_id' => $memo->id,
            'version_id' => $firstVersion->id,
        ], now()->addHour());

        $path = URL::temporarySignedRoute('memos.file.signed', now()->addHour(), [
            'memo' => $memo,
            'version_id' => $secondVersion->id,
            'oo_token' => $ooToken,
        ], false);

        $this->get($path)->assertForbidden();
    }

    public function test_export_pdf_converts_stored_memo_docx_through_onlyoffice(): void
    {
        Storage::fake('local');
        config([
            'services.onlyoffice.jwt_secret' => 'converter-secret',
            'services.onlyoffice.internal_url' => 'http://onlyoffice',
            'services.onlyoffice.public_url' => 'https://ista-ai.app',
            'services.onlyoffice.laravel_internal_url' => 'http://laravel:8000',
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $memo = $this->createMemo($user);
        $memo->forceFill(['searchable_text' => 'preview html text'])->save();
        Storage::disk('local')->put($memo->file_path, 'docx-bytes');

        $conversionPayload = null;

        Http::fake(function (HttpRequest $request) use (&$conversionPayload) {
            if (str_starts_with($request->url(), 'http://onlyoffice/converter')) {
                $data = $request->data();
                $conversionPayload = (new JwtSigner('converter-secret'))->verify((string) $data['token']);

                return Http::response([
                    'endConvert' => true,
                    'fileType' => 'pdf',
                    'fileUrl' => 'https://ista-ai.app/cache/memo.pdf',
                    'percent' => 100,
                ], 200);
            }

            if ($request->url() === 'https://ista-ai.app/cache/memo.pdf') {
                return Http::response('%PDF-from-docx', 200, [
                    'Content-Type' => 'application/pdf',
                ]);
            }

            return Http::response('unexpected request', 500);
        });

        $response = $this->actingAs($user)
            ->get(route('memos.export.pdf', $memo))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringContainsString('attachment; filename="Memo-Test.pdf"', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('%PDF-from-docx', $response->getContent());
        $this->assertIsArray($conversionPayload);
        $this->assertSame('docx', $conversionPayload['filetype']);
        $this->assertSame('pdf', $conversionPayload['outputtype']);
        $this->assertStringStartsWith('memo-'.$memo->id.'-current-', $conversionPayload['key']);
        $this->assertStringEndsWith('-pdf', $conversionPayload['key']);
        $this->assertStringStartsWith('http://laravel:8000/chat/memos/'.$memo->id.'/signed-file?', $conversionPayload['url']);
        $this->assertSame('Memo-Test.docx', $conversionPayload['title']);
    }

    public function test_export_pdf_converts_selected_memo_version_file(): void
    {
        Storage::fake('local');
        config([
            'services.onlyoffice.jwt_secret' => 'converter-secret',
            'services.onlyoffice.internal_url' => 'http://onlyoffice',
            'services.onlyoffice.public_url' => 'https://ista-ai.app',
            'services.onlyoffice.laravel_internal_url' => 'http://laravel:8000',
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $memo = $this->createMemo($user);
        [$firstVersion, $secondVersion] = $this->createMemoVersions($memo);
        Storage::disk('local')->put($firstVersion->file_path, 'docx-v1');
        Storage::disk('local')->put($secondVersion->file_path, 'docx-v2');

        $memo->forceFill([
            'file_path' => $firstVersion->file_path,
            'current_version_id' => $firstVersion->id,
        ])->save();

        $conversionPayload = null;

        Http::fake(function (HttpRequest $request) use (&$conversionPayload) {
            if (str_starts_with($request->url(), 'http://onlyoffice/converter')) {
                $data = $request->data();
                $conversionPayload = (new JwtSigner('converter-secret'))->verify((string) $data['token']);

                return Http::response([
                    'endConvert' => true,
                    'fileType' => 'pdf',
                    'fileUrl' => 'https://ista-ai.app/cache/memo-v2.pdf',
                    'percent' => 100,
                ], 200);
            }

            if ($request->url() === 'https://ista-ai.app/cache/memo-v2.pdf') {
                return Http::response('%PDF-from-v2', 200, [
                    'Content-Type' => 'application/pdf',
                ]);
            }

            return Http::response('unexpected request', 500);
        });

        $response = $this->actingAs($user)
            ->get(route('memos.export.pdf', ['memo' => $memo, 'version_id' => $secondVersion->id]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertSame('%PDF-from-v2', $response->getContent());
        $this->assertIsArray($conversionPayload);
        $this->assertStringStartsWith('memo-'.$memo->id.'-v'.$secondVersion->id.'-', $conversionPayload['key']);
        $this->assertStringEndsWith('-pdf', $conversionPayload['key']);
        $this->assertStringContainsString('version_id='.$secondVersion->id, $conversionPayload['url']);
        $this->assertSame('Memo-Test.docx', $conversionPayload['title']);
    }

    public function test_owner_can_force_save_selected_memo_version(): void
    {
        config([
            'services.onlyoffice.jwt_secret' => 'force-secret',
            'services.onlyoffice.internal_url' => 'http://onlyoffice',
            'services.onlyoffice.force_save_wait_seconds' => 1,
            'services.onlyoffice.force_save_poll_microseconds' => 1000,
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $memo = $this->createMemo($user);
        [, $secondVersion] = $this->createMemoVersions($memo);
        $memo->forceFill([
            'file_path' => $secondVersion->file_path,
            'current_version_id' => $secondVersion->id,
        ])->save();

        $commandPayload = null;

        Http::fake(function (HttpRequest $request) use (&$commandPayload, $memo, $secondVersion) {
            if (str_starts_with($request->url(), 'http://onlyoffice/command')) {
                $commandPayload = (new JwtSigner('force-secret'))->verify((string) $request->data()['token']);
                app(MemoForceSaveService::class)->markSucceeded((string) $commandPayload['userdata'], $memo, $secondVersion);

                return Http::response(['error' => 0], 200);
            }

            return Http::response('unexpected request', 500);
        });

        $this->actingAs($user)
            ->postJson(route('memos.force-save', $memo), [
                'version_id' => $secondVersion->id,
            ])
            ->assertOk()
            ->assertJson(['status' => 'saved']);

        $this->assertIsArray($commandPayload);
        $this->assertSame('forcesave', $commandPayload['c']);
        $this->assertStringStartsWith('memo-'.$memo->id.'-v'.$secondVersion->id.'-', $commandPayload['key']);
        $this->assertStringStartsWith('memo-force-save:'.$memo->id.':'.$secondVersion->id.':', $commandPayload['userdata']);
    }

    public function test_force_save_no_changes_is_treated_as_success(): void
    {
        config([
            'services.onlyoffice.jwt_secret' => 'force-secret',
            'services.onlyoffice.internal_url' => 'http://onlyoffice',
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $memo = $this->createMemo($user);

        Http::fake([
            'http://onlyoffice/command*' => Http::response(['error' => 4], 200),
        ]);

        $this->actingAs($user)
            ->postJson(route('memos.force-save', $memo))
            ->assertOk()
            ->assertJson(['status' => 'no_changes']);
    }

    public function test_force_save_retries_transient_command_failure(): void
    {
        config([
            'services.onlyoffice.jwt_secret' => 'force-secret',
            'services.onlyoffice.internal_url' => 'http://onlyoffice',
            'services.onlyoffice.force_save_wait_seconds' => 1,
            'services.onlyoffice.force_save_poll_microseconds' => 1000,
            'services.onlyoffice.force_save_command_attempts' => 2,
            'services.onlyoffice.force_save_command_retry_microseconds' => 50000,
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $memo = $this->createMemo($user);
        $attempts = 0;

        Http::fake(function (HttpRequest $request) use (&$attempts, $memo) {
            if (str_starts_with($request->url(), 'http://onlyoffice/command')) {
                $attempts++;

                if ($attempts === 1) {
                    return Http::response(['error' => 'temporary'], 502);
                }

                $commandPayload = (new JwtSigner('force-secret'))->verify((string) $request->data()['token']);
                app(MemoForceSaveService::class)->markSucceeded((string) $commandPayload['userdata'], $memo);

                return Http::response(['error' => 0], 200);
            }

            return Http::response('unexpected request', 500);
        });

        $this->actingAs($user)
            ->postJson(route('memos.force-save', $memo))
            ->assertOk()
            ->assertJson(['status' => 'saved']);

        $this->assertSame(2, $attempts);
    }

    protected function createMemo(User $user): Memo
    {
        return Memo::create([
            'user_id' => $user->id,
            'title' => 'Memo Test',
            'memo_type' => 'memo_internal',
            'file_path' => 'memos/'.$user->id.'/memo.docx',
            'status' => Memo::STATUS_GENERATED,
        ]);
    }

    /**
     * @return array{0: MemoVersion, 1: MemoVersion}
     */
    protected function createMemoVersions(Memo $memo): array
    {
        return [
            $memo->versions()->create([
                'version_number' => 1,
                'label' => 'Versi 1',
                'file_path' => 'memos/'.$memo->user_id.'/memo-v1.docx',
                'status' => Memo::STATUS_GENERATED,
                'configuration' => ['subject' => $memo->title],
                'searchable_text' => 'Versi 1',
            ]),
            $memo->versions()->create([
                'version_number' => 2,
                'label' => 'Versi 2',
                'file_path' => 'memos/'.$memo->user_id.'/memo-v2.docx',
                'status' => Memo::STATUS_GENERATED,
                'configuration' => ['subject' => $memo->title],
                'searchable_text' => 'Versi 2',
            ]),
        ];
    }
}
