<?php

namespace Tests\Feature\Memos;

use App\Models\Memo;
use App\Models\MemoVersion;
use App\Models\User;
use App\Services\OnlyOffice\JwtSigner;
use App\Services\OnlyOffice\MemoDocumentKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OnlyOfficeCallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_callback_rejects_missing_token(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $memo = $this->createMemo($user);

        $this->postJson(route('onlyoffice.callback', $memo), [
            'status' => 2,
            'key' => 'memo-'.$memo->id.'-123',
            'url' => 'https://onlyoffice.test/file.docx',
        ])->assertUnauthorized();
    }

    public function test_callback_rejects_token_without_exp(): void
    {
        config([
            'services.onlyoffice.jwt_secret' => 'callback-secret',
            'services.onlyoffice.internal_url' => 'https://onlyoffice.test',
        ]);
        Http::fake();

        $user = User::factory()->create(['email_verified_at' => now()]);
        $memo = $this->createMemo($user);
        $key = $this->callbackKey($memo);
        $url = 'https://onlyoffice.test/file.docx';

        // Token signed without 'exp' claim — must be rejected.
        $token = (new JwtSigner('callback-secret'))->sign([
            'status' => 2,
            'key' => $key,
            'url' => $url,
            // deliberately omit 'exp'
        ]);

        $this->postJson(route('onlyoffice.callback', $memo), [
            'status' => 2,
            'key' => $key,
            'url' => $url,
            'token' => $token,
        ])->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_callback_with_valid_token_saves_file(): void
    {
        config([
            'services.onlyoffice.jwt_secret' => 'callback-secret',
            'services.onlyoffice.internal_url' => 'https://onlyoffice.test',
        ]);
        Storage::fake('local');
        Http::fake([
            'https://onlyoffice.test/file.docx' => Http::response($this->validMemoDocxBytes(), 200),
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $memo = $this->createMemo($user);
        $key = $this->callbackKey($memo);
        $url = 'https://onlyoffice.test/file.docx';
        $token = (new JwtSigner('callback-secret'))->sign([
            'status' => 2,
            'key' => $key,
            'url' => $url,
            'exp' => time() + 60,
        ]);

        $this->postJson(route('onlyoffice.callback', $memo), [
            'status' => 2,
            'key' => $key,
            'url' => $url,
            'token' => $token,
        ])->assertOk()
            ->assertJson(['error' => 0]);

        $memo->refresh();
        $this->assertSame(Memo::STATUS_EDITED, $memo->status);
        $this->assertSame(Memo::STATUS_EDITED, $memo->currentVersion?->status);
        $this->assertSame($memo->file_path, $memo->currentVersion?->file_path);
        Storage::disk('local')->assertExists($memo->file_path);
        $this->assertStringStartsWith("PK\x03\x04", Storage::disk('local')->get($memo->file_path));
    }

    public function test_callback_accepts_public_onlyoffice_download_url(): void
    {
        config([
            'services.onlyoffice.jwt_secret' => 'callback-secret',
            'services.onlyoffice.internal_url' => 'http://onlyoffice',
            'services.onlyoffice.public_url' => 'https://ista-ai.app',
        ]);
        Storage::fake('local');
        Http::fake([
            'https://ista-ai.app/cache/files/data/output.docx*' => Http::response($this->validMemoDocxBytes(), 200),
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $memo = $this->createMemo($user);
        $key = $this->callbackKey($memo);
        $url = 'https://ista-ai.app/cache/files/data/output.docx?md5=abc&expires=123';
        $token = (new JwtSigner('callback-secret'))->sign([
            'status' => 2,
            'key' => $key,
            'url' => $url,
            'exp' => time() + 60,
        ]);

        $this->postJson(route('onlyoffice.callback', $memo), [
            'status' => 2,
            'key' => $key,
            'url' => $url,
            'token' => $token,
        ])->assertOk()
            ->assertJson(['error' => 0]);

        Storage::disk('local')->assertExists($memo->refresh()->file_path);
        $this->assertStringStartsWith("PK\x03\x04", Storage::disk('local')->get($memo->file_path));
    }

    public function test_callback_accepts_onlyoffice_header_payload_wrapper(): void
    {
        config([
            'services.onlyoffice.jwt_secret' => 'callback-secret',
            'services.onlyoffice.internal_url' => 'https://onlyoffice.test',
        ]);
        Storage::fake('local');
        Http::fake([
            'https://onlyoffice.test/header-file.docx' => Http::response($this->validMemoDocxBytes(), 200),
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $memo = $this->createMemo($user);
        $body = [
            'status' => 2,
            'key' => $this->callbackKey($memo),
            'url' => 'https://onlyoffice.test/header-file.docx',
        ];
        $token = (new JwtSigner('callback-secret'))->sign([
            'payload' => $body,
            'exp' => time() + 60,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(route('onlyoffice.callback', $memo), $body)
            ->assertOk()
            ->assertJson(['error' => 0]);

        Storage::disk('local')->assertExists($memo->refresh()->file_path);
        $this->assertStringStartsWith("PK\x03\x04", Storage::disk('local')->get($memo->file_path));
    }

    public function test_callback_accepts_onlyoffice_token_in_body_payload_only(): void
    {
        config([
            'services.onlyoffice.jwt_secret' => 'callback-secret',
            'services.onlyoffice.internal_url' => 'https://onlyoffice.test',
        ]);
        Storage::fake('local');
        Http::fake([
            'https://onlyoffice.test/body-file.docx' => Http::response($this->validMemoDocxBytes(), 200),
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $memo = $this->createMemo($user);
        $token = (new JwtSigner('callback-secret'))->sign([
            'status' => 2,
            'key' => $this->callbackKey($memo),
            'url' => 'https://onlyoffice.test/body-file.docx',
            'exp' => time() + 60,
        ]);

        $this->postJson(route('onlyoffice.callback', $memo), [
            'token' => $token,
        ])->assertOk()
            ->assertJson(['error' => 0]);

        Storage::disk('local')->assertExists($memo->refresh()->file_path);
        $this->assertStringStartsWith("PK\x03\x04", Storage::disk('local')->get($memo->file_path));
    }

    public function test_callback_rejects_mismatch_between_header_payload_and_body(): void
    {
        config([
            'services.onlyoffice.jwt_secret' => 'callback-secret',
            'services.onlyoffice.internal_url' => 'https://onlyoffice.test',
        ]);
        Http::fake();

        $user = User::factory()->create(['email_verified_at' => now()]);
        $memo = $this->createMemo($user);
        $key = $this->callbackKey($memo);
        $token = (new JwtSigner('callback-secret'))->sign([
            'payload' => [
                'status' => 2,
                'key' => $key,
                'url' => 'https://onlyoffice.test/header-file.docx',
            ],
            'exp' => time() + 60,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(route('onlyoffice.callback', $memo), [
                'status' => 2,
                'key' => $key,
                'url' => 'https://evil.test/header-file.docx',
            ])->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_editor_config_token_cannot_be_used_as_callback_token(): void
    {
        config([
            'services.onlyoffice.jwt_secret' => 'callback-secret',
            'services.onlyoffice.internal_url' => 'https://onlyoffice.test',
        ]);
        Storage::fake('local');
        Http::fake();

        $user = User::factory()->create(['email_verified_at' => now()]);
        $memo = $this->createMemo($user);
        $key = $this->callbackKey($memo);
        $url = 'https://onlyoffice.test/file.docx';
        $token = (new JwtSigner('callback-secret'))->sign([
            'document' => [
                'key' => $key,
                'url' => route('memos.file.signed', $memo),
            ],
            'documentType' => 'word',
            'editorConfig' => [
                'callbackUrl' => route('onlyoffice.callback', $memo),
            ],
            'token_use' => 'editor_config',
            'exp' => time() + 60,
        ]);

        $this->postJson(route('onlyoffice.callback', $memo), [
            'status' => 2,
            'key' => $key,
            'url' => $url,
            'token' => $token,
        ])->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_callback_rejects_tampered_token(): void
    {
        config(['services.onlyoffice.jwt_secret' => 'callback-secret']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $memo = $this->createMemo($user);
        $key = $this->callbackKey($memo);
        $url = 'https://onlyoffice.test/file.docx';
        $token = (new JwtSigner('callback-secret'))->sign([
            'status' => 2,
            'key' => $key,
            'url' => $url,
            'exp' => time() + 60,
        ]);

        $this->postJson(route('onlyoffice.callback', $memo), [
            'status' => 2,
            'key' => $key,
            'url' => $url,
            'token' => $token.'x',
        ])->assertUnauthorized();
    }

    public function test_callback_rejects_untrusted_download_url(): void
    {
        config([
            'services.onlyoffice.jwt_secret' => 'callback-secret',
            'services.onlyoffice.internal_url' => 'https://onlyoffice.test',
        ]);
        Http::fake();

        $user = User::factory()->create(['email_verified_at' => now()]);
        $memo = $this->createMemo($user);
        $key = $this->callbackKey($memo);
        $url = 'https://evil.test/file.docx';
        $token = (new JwtSigner('callback-secret'))->sign([
            'status' => 2,
            'key' => $key,
            'url' => $url,
            'exp' => time() + 60,
        ]);

        $this->postJson(route('onlyoffice.callback', $memo), [
            'status' => 2,
            'key' => $key,
            'url' => $url,
            'token' => $token,
        ])->assertForbidden();

        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // Status 1: document being edited — acknowledge, no file save
    // -------------------------------------------------------------------------

    public function test_callback_status_1_acknowledges_without_saving(): void
    {
        config([
            'services.onlyoffice.jwt_secret' => 'callback-secret',
            'services.onlyoffice.internal_url' => 'https://onlyoffice.test',
        ]);
        Storage::fake('local');
        Http::fake();

        $user = User::factory()->create(['email_verified_at' => now()]);
        $memo = $this->createMemo($user);
        $originalPath = $memo->file_path;
        Storage::disk('local')->put($originalPath, 'original-content');

        $key = $this->callbackKey($memo);
        $token = (new JwtSigner('callback-secret'))->sign([
            'status' => 1,
            'key' => $key,
            'exp' => time() + 60,
        ]);

        $this->postJson(route('onlyoffice.callback', $memo), [
            'status' => 1,
            'key' => $key,
            'token' => $token,
        ])->assertOk()
            ->assertJson(['error' => 0]);

        // No file should be downloaded or written.
        Http::assertNothingSent();
        $this->assertSame('original-content', Storage::disk('local')->get($originalPath));
    }

    // -------------------------------------------------------------------------
    // Status 4: no editors remaining — acknowledge, no file save
    // -------------------------------------------------------------------------

    public function test_callback_status_4_acknowledges_without_saving(): void
    {
        config([
            'services.onlyoffice.jwt_secret' => 'callback-secret',
            'services.onlyoffice.internal_url' => 'https://onlyoffice.test',
        ]);
        Storage::fake('local');
        Http::fake();

        $user = User::factory()->create(['email_verified_at' => now()]);
        $memo = $this->createMemo($user);
        $originalPath = $memo->file_path;
        Storage::disk('local')->put($originalPath, 'original-content');

        $key = $this->callbackKey($memo);
        $token = (new JwtSigner('callback-secret'))->sign([
            'status' => 4,
            'key' => $key,
            'exp' => time() + 60,
        ]);

        $this->postJson(route('onlyoffice.callback', $memo), [
            'status' => 4,
            'key' => $key,
            'token' => $token,
        ])->assertOk()
            ->assertJson(['error' => 0]);

        Http::assertNothingSent();
        $this->assertSame('original-content', Storage::disk('local')->get($originalPath));
    }

    // -------------------------------------------------------------------------
    // Status 3: save error — acknowledge, no file write, logged
    // -------------------------------------------------------------------------

    public function test_callback_status_3_acknowledges_and_does_not_save_file(): void
    {
        config([
            'services.onlyoffice.jwt_secret' => 'callback-secret',
            'services.onlyoffice.internal_url' => 'https://onlyoffice.test',
        ]);
        Storage::fake('local');
        Http::fake();

        $user = User::factory()->create(['email_verified_at' => now()]);
        $memo = $this->createMemo($user);
        $originalPath = $memo->file_path;
        Storage::disk('local')->put($originalPath, 'original-content');

        $key = $this->callbackKey($memo);
        $token = (new JwtSigner('callback-secret'))->sign([
            'status' => 3,
            'key' => $key,
            'exp' => time() + 60,
        ]);

        $this->postJson(route('onlyoffice.callback', $memo), [
            'status' => 3,
            'key' => $key,
            'token' => $token,
        ])->assertOk()
            ->assertJson(['error' => 0]);

        // No HTTP request should have been made (no file to download for errors).
        Http::assertNothingSent();

        // Original file must NOT be overwritten.
        $this->assertSame('original-content', Storage::disk('local')->get($originalPath));

        // Memo status must remain unchanged.
        $this->assertNotSame(Memo::STATUS_EDITED, $memo->refresh()->status);
    }

    // -------------------------------------------------------------------------
    // Status 7: force-save error — acknowledge, no file write, logged
    // -------------------------------------------------------------------------

    public function test_callback_status_7_acknowledges_and_does_not_save_file(): void
    {
        config([
            'services.onlyoffice.jwt_secret' => 'callback-secret',
            'services.onlyoffice.internal_url' => 'https://onlyoffice.test',
        ]);
        Storage::fake('local');
        Http::fake();

        $user = User::factory()->create(['email_verified_at' => now()]);
        $memo = $this->createMemo($user);
        $originalPath = $memo->file_path;
        Storage::disk('local')->put($originalPath, 'original-content');

        $key = $this->callbackKey($memo);
        $token = (new JwtSigner('callback-secret'))->sign([
            'status' => 7,
            'key' => $key,
            'exp' => time() + 60,
        ]);

        $this->postJson(route('onlyoffice.callback', $memo), [
            'status' => 7,
            'key' => $key,
            'token' => $token,
        ])->assertOk()
            ->assertJson(['error' => 0]);

        Http::assertNothingSent();
        $this->assertSame('original-content', Storage::disk('local')->get($originalPath));
        $this->assertNotSame(Memo::STATUS_EDITED, $memo->refresh()->status);
    }

    // -------------------------------------------------------------------------
    // Anti-replay: identical callback (same key+status+url) must be rejected
    //              after a successful save, but retries after failures allowed
    // -------------------------------------------------------------------------

    public function test_callback_replay_is_rejected_after_successful_save(): void
    {
        config([
            'services.onlyoffice.jwt_secret' => 'callback-secret',
            'services.onlyoffice.internal_url' => 'https://onlyoffice.test',
        ]);
        Storage::fake('local');
        Http::fake([
            'https://onlyoffice.test/replay.docx' => Http::response($this->validMemoDocxBytes(), 200),
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $memo = $this->createMemo($user);
        $key = $this->callbackKey($memo);
        $url = 'https://onlyoffice.test/replay.docx';
        $token = (new JwtSigner('callback-secret'))->sign([
            'status' => 2,
            'key' => $key,
            'url' => $url,
            'exp' => time() + 60,
        ]);

        $payload = ['status' => 2, 'key' => $key, 'url' => $url, 'token' => $token];

        // First call: must succeed and mark the replay guard.
        $this->postJson(route('onlyoffice.callback', $memo), $payload)
            ->assertOk()
            ->assertJson(['error' => 0]);

        // Second call with identical payload (same key+status+url): the replay
        // guard was marked after the successful save, so this must be rejected.
        $this->postJson(route('onlyoffice.callback', $memo), $payload)
            ->assertConflict();
    }

    public function test_callback_retry_is_allowed_when_first_attempt_failed_before_write(): void
    {
        config([
            'services.onlyoffice.jwt_secret' => 'callback-secret',
            'services.onlyoffice.internal_url' => 'https://onlyoffice.test',
        ]);
        Storage::fake('local');

        $user = User::factory()->create(['email_verified_at' => now()]);
        $memo = $this->createMemo($user);
        $key = $this->callbackKey($memo);
        $url = 'https://onlyoffice.test/transient-file.docx';
        $token = (new JwtSigner('callback-secret'))->sign([
            'status' => 2,
            'key' => $key,
            'url' => $url,
            'exp' => time() + 60,
        ]);

        $callCount = 0;
        Http::fake([
            'https://onlyoffice.test/transient-file.docx' => function () use (&$callCount) {
                $callCount++;

                // First attempt: OnlyOffice returns a transient non-DOCX error page
                // (e.g., a 200 HTML error from a temporarily overloaded server).
                // Second attempt: the real DOCX is available.
                return $callCount === 1
                    ? Http::response('<html>Service Unavailable</html>', 200)
                    : Http::response($this->validMemoDocxBytes(), 200);
            },
        ]);

        $payload = ['status' => 2, 'key' => $key, 'url' => $url, 'token' => $token];

        // First attempt: rejected at DOCX validation — file is not written,
        // replay marker must NOT be set.
        $this->postJson(route('onlyoffice.callback', $memo), $payload)
            ->assertStatus(502);

        Storage::disk('local')->assertMissing($memo->file_path);

        // Second attempt (retry): the replay guard was never marked, so this
        // must succeed and write the DOCX.
        $this->postJson(route('onlyoffice.callback', $memo), $payload)
            ->assertOk()
            ->assertJson(['error' => 0]);

        Storage::disk('local')->assertExists($memo->refresh()->file_path);
    }

    public function test_two_distinct_saves_in_same_session_are_not_blocked_as_replay(): void
    {
        config([
            'services.onlyoffice.jwt_secret' => 'callback-secret',
            'services.onlyoffice.internal_url' => 'https://onlyoffice.test',
        ]);
        Storage::fake('local');
        Http::fake([
            'https://onlyoffice.test/save-v1.docx' => Http::response($this->validMemoDocxBytes(), 200),
            'https://onlyoffice.test/save-v2.docx' => Http::response($this->validMemoDocxBytes(), 200),
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $memo = $this->createMemo($user);
        $key = $this->callbackKey($memo);

        // First force-save: same key + status but URL is unique per save (OnlyOffice behaviour).
        $token1 = (new JwtSigner('callback-secret'))->sign([
            'status' => 6,
            'key' => $key,
            'url' => 'https://onlyoffice.test/save-v1.docx',
            'exp' => time() + 60,
        ]);

        $this->postJson(route('onlyoffice.callback', $memo), [
            'status' => 6,
            'key' => $key,
            'url' => 'https://onlyoffice.test/save-v1.docx',
            'token' => $token1,
        ])->assertOk()->assertJson(['error' => 0]);

        // Second force-save in the same session: same key + status, but different URL
        // (OnlyOffice generates a new download URL for each save output).
        // Fingerprint is key:status:url — since URL differs this is NOT a replay.
        $token2 = (new JwtSigner('callback-secret'))->sign([
            'status' => 6,
            'key' => $key,
            'url' => 'https://onlyoffice.test/save-v2.docx',
            'exp' => time() + 60,
        ]);

        $this->postJson(route('onlyoffice.callback', $memo), [
            'status' => 6,
            'key' => $key,
            'url' => 'https://onlyoffice.test/save-v2.docx',
            'token' => $token2,
        ])->assertOk()->assertJson(['error' => 0]);

        // Both saves must have been written — no 409 from the replay guard.
        $this->assertSame(Memo::STATUS_EDITED, $memo->refresh()->status);
    }

    // -------------------------------------------------------------------------
    // Non-DOCX download response rejected before write
    // -------------------------------------------------------------------------

    public function test_callback_rejects_non_docx_response_body(): void
    {
        config([
            'services.onlyoffice.jwt_secret' => 'callback-secret',
            'services.onlyoffice.internal_url' => 'https://onlyoffice.test',
        ]);
        Storage::fake('local');
        // Server returns an HTML error page instead of a DOCX file.
        Http::fake([
            'https://onlyoffice.test/bad.docx' => Http::response('<html><body>Error</body></html>', 200),
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $memo = $this->createMemo($user);
        $originalPath = $memo->file_path;
        Storage::disk('local')->put($originalPath, 'original-content');

        $key = $this->callbackKey($memo);
        $url = 'https://onlyoffice.test/bad.docx';
        $token = (new JwtSigner('callback-secret'))->sign([
            'status' => 2,
            'key' => $key,
            'url' => $url,
            'exp' => time() + 60,
        ]);

        $this->postJson(route('onlyoffice.callback', $memo), [
            'status' => 2,
            'key' => $key,
            'url' => $url,
            'token' => $token,
        ])->assertStatus(502);

        // Original file must not be overwritten.
        $this->assertSame('original-content', Storage::disk('local')->get($originalPath));
    }

    public function test_callback_with_version_id_updates_only_that_version_file(): void
    {
        config([
            'services.onlyoffice.jwt_secret' => 'callback-secret',
            'services.onlyoffice.internal_url' => 'https://onlyoffice.test',
        ]);
        Storage::fake('local');
        Http::fake([
            'https://onlyoffice.test/version-one.docx' => Http::response($this->validMemoDocxBytes(), 200),
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $memo = $this->createMemo($user);
        $versionOne = $memo->versions()->where('version_number', 1)->firstOrFail();
        $versionTwo = $memo->versions()->create([
            'version_number' => 2,
            'label' => 'Versi 2',
            'file_path' => 'memos/'.$user->id.'/memo-v2.docx',
            'status' => Memo::STATUS_GENERATED,
            'configuration' => [],
            'searchable_text' => 'Versi 2',
        ]);

        Storage::disk('local')->put($versionOne->file_path, self::corruptDocxBytes('original-v1'));
        Storage::disk('local')->put($versionTwo->file_path, self::corruptDocxBytes('current-v2'));

        $memo->forceFill([
            'file_path' => $versionTwo->file_path,
            'current_version_id' => $versionTwo->id,
        ])->save();

        $key = $this->callbackKey($memo, $versionOne);
        $url = 'https://onlyoffice.test/version-one.docx';
        $token = (new JwtSigner('callback-secret'))->sign([
            'status' => 2,
            'key' => $key,
            'url' => $url,
            'exp' => time() + 60,
        ]);

        $this->postJson(route('onlyoffice.callback', [
            'memo' => $memo,
            'version_id' => $versionOne->id,
        ]), [
            'status' => 2,
            'key' => $key,
            'url' => $url,
            'token' => $token,
        ])->assertOk()
            ->assertJson(['error' => 0]);

        // Version one should now have the new DOCX content.
        $this->assertStringStartsWith("PK\x03\x04", Storage::disk('local')->get($versionOne->file_path));
        // Version two must remain unchanged.
        $savedV2 = Storage::disk('local')->get($versionTwo->file_path);
        $this->assertStringContainsString('current-v2', $savedV2);

        $memo->refresh();
        $this->assertSame($versionTwo->id, $memo->current_version_id);
        $this->assertSame($versionTwo->file_path, $memo->file_path);
        $this->assertSame(Memo::STATUS_EDITED, $versionOne->refresh()->status);
        $this->assertSame(Memo::STATUS_GENERATED, $versionTwo->refresh()->status);
    }

    public function test_callback_rejects_stale_version_document_key(): void
    {
        config([
            'services.onlyoffice.jwt_secret' => 'callback-secret',
            'services.onlyoffice.internal_url' => 'https://onlyoffice.test',
        ]);
        Storage::fake('local');
        Http::fake([
            'https://onlyoffice.test/legacy-version-one.docx' => Http::response(self::corruptDocxBytes(), 200),
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $memo = $this->createMemo($user);
        $versionOne = $memo->versions()->where('version_number', 1)->firstOrFail();
        $versionTwo = $memo->versions()->create([
            'version_number' => 2,
            'label' => 'Versi 2',
            'file_path' => 'memos/'.$user->id.'/memo-v2.docx',
            'status' => Memo::STATUS_GENERATED,
            'configuration' => [],
            'searchable_text' => 'Versi 2',
        ]);

        Storage::disk('local')->put($versionOne->file_path, 'original-version-one');
        Storage::disk('local')->put($versionTwo->file_path, 'current-version-two');

        $memo->forceFill([
            'file_path' => $versionTwo->file_path,
            'current_version_id' => $versionTwo->id,
        ])->save();

        $key = 'memo-'.$memo->id.'-v'.$versionOne->id.'-legacy';
        $url = 'https://onlyoffice.test/legacy-version-one.docx';
        $token = (new JwtSigner('callback-secret'))->sign([
            'status' => 2,
            'key' => $key,
            'url' => $url,
            'exp' => time() + 60,
        ]);

        $this->postJson(route('onlyoffice.callback', $memo), [
            'status' => 2,
            'key' => $key,
            'url' => $url,
            'token' => $token,
        ])->assertConflict();

        Http::assertNothingSent();
        $this->assertSame('original-version-one', Storage::disk('local')->get($versionOne->file_path));
        $this->assertSame('current-version-two', Storage::disk('local')->get($versionTwo->file_path));

        $memo->refresh();
        $this->assertSame($versionTwo->id, $memo->current_version_id);
        $this->assertSame($versionTwo->file_path, $memo->file_path);
    }

    public function test_callback_rejects_stale_current_document_key(): void
    {
        config([
            'services.onlyoffice.jwt_secret' => 'callback-secret',
            'services.onlyoffice.internal_url' => 'https://onlyoffice.test',
        ]);
        Storage::fake('local');
        Http::fake([
            'https://onlyoffice.test/stale-current.docx' => Http::response(self::corruptDocxBytes(), 200),
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $memo = $this->createMemo($user);
        Storage::disk('local')->put($memo->file_path, 'current-docx');

        $key = 'memo-'.$memo->id.'-current-stale';
        $url = 'https://onlyoffice.test/stale-current.docx';
        $token = (new JwtSigner('callback-secret'))->sign([
            'status' => 2,
            'key' => $key,
            'url' => $url,
            'exp' => time() + 60,
        ]);

        $this->postJson(route('onlyoffice.callback', $memo), [
            'status' => 2,
            'key' => $key,
            'url' => $url,
            'token' => $token,
        ])->assertConflict();

        Http::assertNothingSent();
        $this->assertSame('current-docx', Storage::disk('local')->get($memo->file_path));
    }

    // -------------------------------------------------------------------------
    // Bug #155: searchable_text harus diperbarui dari konten DOCX setelah edit
    // -------------------------------------------------------------------------

    public function test_callback_status_2_updates_searchable_text_from_docx(): void
    {
        config([
            'services.onlyoffice.jwt_secret' => 'callback-secret',
            'services.onlyoffice.internal_url' => 'https://onlyoffice.test',
        ]);
        Storage::fake('local');

        $docxContent = file_get_contents(base_path('tests/Fixtures/edited-memo.docx'));
        Http::fake([
            'https://onlyoffice.test/edited.docx' => Http::response($docxContent, 200),
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $memo = $this->createMemo($user);
        $originalSearchableText = $memo->currentVersion->searchable_text;

        $key = $this->callbackKey($memo);
        $url = 'https://onlyoffice.test/edited.docx';
        $token = (new JwtSigner('callback-secret'))->sign([
            'status' => 2,
            'key' => $key,
            'url' => $url,
            'exp' => time() + 60,
        ]);

        $this->postJson(route('onlyoffice.callback', $memo), [
            'status' => 2,
            'key' => $key,
            'url' => $url,
            'token' => $token,
        ])->assertOk()->assertJson(['error' => 0]);

        $memo->refresh();
        $version = $memo->currentVersion;

        // searchable_text harus berubah dari nilai awal
        $this->assertNotSame($originalSearchableText, $memo->searchable_text);
        $this->assertNotSame($originalSearchableText, $version?->searchable_text);

        // harus mengandung teks dari DOCX yang baru
        $this->assertStringContainsString('diedit manual', $memo->searchable_text);
        $this->assertStringContainsString('diedit manual', $version?->searchable_text ?? '');
    }

    public function test_callback_status_6_updates_searchable_text_from_docx(): void
    {
        config([
            'services.onlyoffice.jwt_secret' => 'callback-secret',
            'services.onlyoffice.internal_url' => 'https://onlyoffice.test',
        ]);
        Storage::fake('local');

        $docxContent = file_get_contents(base_path('tests/Fixtures/edited-memo.docx'));
        Http::fake([
            'https://onlyoffice.test/force-saved.docx' => Http::response($docxContent, 200),
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $memo = $this->createMemo($user);
        $originalSearchableText = $memo->currentVersion->searchable_text;

        $key = $this->callbackKey($memo);
        $url = 'https://onlyoffice.test/force-saved.docx';
        $token = (new JwtSigner('callback-secret'))->sign([
            'status' => 6,
            'key' => $key,
            'url' => $url,
            'exp' => time() + 60,
        ]);

        $this->postJson(route('onlyoffice.callback', $memo), [
            'status' => 6,
            'key' => $key,
            'url' => $url,
            'token' => $token,
        ])->assertOk()->assertJson(['error' => 0]);

        $memo->refresh();
        $version = $memo->currentVersion;

        $this->assertNotSame($originalSearchableText, $memo->searchable_text);
        $this->assertStringContainsString('diedit manual', $memo->searchable_text);
        $this->assertStringContainsString('diedit manual', $version?->searchable_text ?? '');
    }

    public function test_callback_rejects_corrupt_zip_prefixed_docx_and_keeps_existing_file(): void
    {
        config([
            'services.onlyoffice.jwt_secret' => 'callback-secret',
            'services.onlyoffice.internal_url' => 'https://onlyoffice.test',
        ]);
        Storage::fake('local');

        Http::fake([
            'https://onlyoffice.test/corrupt.docx' => Http::response(self::corruptDocxBytes('corrupt'), 200),
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $memo = $this->createMemo($user);
        Storage::disk('local')->put($memo->file_path, 'original-content');

        $key = $this->callbackKey($memo);
        $url = 'https://onlyoffice.test/corrupt.docx';
        $token = (new JwtSigner('callback-secret'))->sign([
            'status' => 2,
            'key' => $key,
            'url' => $url,
            'exp' => time() + 60,
        ]);

        $this->postJson(route('onlyoffice.callback', $memo), [
            'status' => 2,
            'key' => $key,
            'url' => $url,
            'token' => $token,
        ])->assertStatus(502);

        $this->assertSame('original-content', Storage::disk('local')->get($memo->file_path));
        $this->assertSame($memo->title, $memo->refresh()->searchable_text);
        $this->assertSame($memo->title, $memo->currentVersion?->searchable_text);
    }

    public function test_callback_status_2_updates_searchable_text_for_non_current_version(): void
    {
        config([
            'services.onlyoffice.jwt_secret' => 'callback-secret',
            'services.onlyoffice.internal_url' => 'https://onlyoffice.test',
        ]);
        Storage::fake('local');

        $docxContent = file_get_contents(base_path('tests/Fixtures/edited-memo.docx'));
        Http::fake([
            'https://onlyoffice.test/version-edited.docx' => Http::response($docxContent, 200),
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $memo = $this->createMemo($user);
        $versionOne = $memo->versions()->where('version_number', 1)->firstOrFail();

        // Buat versi 2 sebagai current version
        $versionTwo = $memo->versions()->create([
            'version_number' => 2,
            'label' => 'Versi 2',
            'file_path' => 'memos/'.$user->id.'/memo-v2.docx',
            'status' => Memo::STATUS_GENERATED,
            'configuration' => [],
            'searchable_text' => 'Teks versi dua asli',
        ]);
        Storage::disk('local')->put($versionOne->file_path, 'original-v1');
        Storage::disk('local')->put($versionTwo->file_path, 'original-v2');
        $memo->forceFill([
            'file_path' => $versionTwo->file_path,
            'current_version_id' => $versionTwo->id,
            'searchable_text' => 'Teks versi dua asli',
        ])->save();

        // Edit versi 1 (bukan current)
        $key = $this->callbackKey($memo, $versionOne);
        $url = 'https://onlyoffice.test/version-edited.docx';
        $token = (new JwtSigner('callback-secret'))->sign([
            'status' => 2,
            'key' => $key,
            'url' => $url,
            'exp' => time() + 60,
        ]);

        $this->postJson(route('onlyoffice.callback', [
            'memo' => $memo,
            'version_id' => $versionOne->id,
        ]), [
            'status' => 2,
            'key' => $key,
            'url' => $url,
            'token' => $token,
        ])->assertOk()->assertJson(['error' => 0]);

        // Versi 1 searchable_text harus diperbarui dari DOCX baru
        $this->assertStringContainsString('diedit manual', $versionOne->refresh()->searchable_text);

        // Versi 2 (current) tidak boleh terpengaruh
        $this->assertSame('Teks versi dua asli', $versionTwo->refresh()->searchable_text);
        $this->assertSame('Teks versi dua asli', $memo->refresh()->searchable_text);
    }

    // -------------------------------------------------------------------------
    // Path traversal in download URL rejected
    // -------------------------------------------------------------------------

    public function test_callback_rejects_url_with_path_traversal(): void
    {
        config([
            'services.onlyoffice.jwt_secret' => 'callback-secret',
            'services.onlyoffice.internal_url' => 'https://onlyoffice.test',
        ]);
        Http::fake();

        $user = User::factory()->create(['email_verified_at' => now()]);
        $memo = $this->createMemo($user);
        $key = $this->callbackKey($memo);
        $url = 'https://onlyoffice.test/../../../etc/passwd';
        $token = (new JwtSigner('callback-secret'))->sign([
            'status' => 2,
            'key' => $key,
            'url' => $url,
            'exp' => time() + 60,
        ]);

        $this->postJson(route('onlyoffice.callback', $memo), [
            'status' => 2,
            'key' => $key,
            'url' => $url,
            'token' => $token,
        ])->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_callback_status_2_rotates_editor_key_for_next_session(): void
    {
        config([
            'services.onlyoffice.jwt_secret' => 'callback-secret',
            'services.onlyoffice.internal_url' => 'https://onlyoffice.test',
        ]);
        Storage::fake('local');
        Http::fake([
            'https://onlyoffice.test/final-save.docx' => Http::response($this->validMemoDocxBytes(), 200),
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $memo = $this->createMemo($user);
        $initialKey = $this->callbackKey($memo);
        $url = 'https://onlyoffice.test/final-save.docx';
        $token = (new JwtSigner('callback-secret'))->sign([
            'status' => 2,
            'key' => $initialKey,
            'url' => $url,
            'exp' => time() + 60,
        ]);

        $this->postJson(route('onlyoffice.callback', $memo), [
            'status' => 2,
            'key' => $initialKey,
            'url' => $url,
            'token' => $token,
        ])->assertOk()->assertJson(['error' => 0]);

        $nextKey = app(MemoDocumentKey::class)->forEditor($memo->refresh());

        $this->assertNotSame($initialKey, $nextKey);
    }

    public function test_callback_status_4_rotates_editor_key_after_session_closes(): void
    {
        config([
            'services.onlyoffice.jwt_secret' => 'callback-secret',
            'services.onlyoffice.internal_url' => 'https://onlyoffice.test',
        ]);
        Storage::fake('local');

        $user = User::factory()->create(['email_verified_at' => now()]);
        $memo = $this->createMemo($user);
        $initialKey = $this->callbackKey($memo);
        $token = (new JwtSigner('callback-secret'))->sign([
            'status' => 4,
            'key' => $initialKey,
            'exp' => time() + 60,
        ]);

        $this->postJson(route('onlyoffice.callback', $memo), [
            'status' => 4,
            'key' => $initialKey,
            'token' => $token,
        ])->assertOk()->assertJson(['error' => 0]);

        $nextKey = app(MemoDocumentKey::class)->forEditor($memo->refresh());

        $this->assertNotSame($initialKey, $nextKey);
    }

    public function test_callback_status_6_after_status_4_rejects_closed_editor_key(): void
    {
        config([
            'services.onlyoffice.jwt_secret' => 'callback-secret',
            'services.onlyoffice.internal_url' => 'https://onlyoffice.test',
        ]);
        Storage::fake('local');
        Http::fake([
            'https://onlyoffice.test/force-save-after-close.docx' => Http::response($this->validMemoDocxBytes(), 200),
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $memo = $this->createMemo($user);
        $initialKey = $this->callbackKey($memo);

        // Simulate another save/update while this editor key is still cached.
        // If status 4 deletes the cache entry, the next validation would
        // regenerate a different key from this updated timestamp.
        $memo->forceFill(['updated_at' => now()->addMinute()])->save();

        $status4Token = (new JwtSigner('callback-secret'))->sign([
            'status' => 4,
            'key' => $initialKey,
            'exp' => time() + 60,
        ]);

        $this->postJson(route('onlyoffice.callback', $memo), [
            'status' => 4,
            'key' => $initialKey,
            'token' => $status4Token,
        ])->assertOk()->assertJson(['error' => 0]);

        $url = 'https://onlyoffice.test/force-save-after-close.docx';
        $status6Token = (new JwtSigner('callback-secret'))->sign([
            'status' => 6,
            'key' => $initialKey,
            'url' => $url,
            'exp' => time() + 60,
        ]);

        $this->postJson(route('onlyoffice.callback', $memo), [
            'status' => 6,
            'key' => $initialKey,
            'url' => $url,
            'token' => $status6Token,
        ])->assertConflict();

        Http::assertNothingSent();
    }

    public function test_callback_status_6_keeps_active_editor_key(): void
    {
        config([
            'services.onlyoffice.jwt_secret' => 'callback-secret',
            'services.onlyoffice.internal_url' => 'https://onlyoffice.test',
        ]);
        Storage::fake('local');
        Http::fake([
            'https://onlyoffice.test/force-save.docx' => Http::response($this->validMemoDocxBytes(), 200),
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $memo = $this->createMemo($user);
        $version = $memo->currentVersion()->firstOrFail();
        $initialKey = $this->callbackKey($memo, $version);
        $url = 'https://onlyoffice.test/force-save.docx';
        $token = (new JwtSigner('callback-secret'))->sign([
            'status' => 6,
            'key' => $initialKey,
            'url' => $url,
            'exp' => time() + 60,
        ]);

        $this->postJson(route('onlyoffice.callback', [
            'memo' => $memo,
            'version_id' => $version->id,
        ]), [
            'status' => 6,
            'key' => $initialKey,
            'url' => $url,
            'token' => $token,
        ])->assertOk()->assertJson(['error' => 0]);

        $activeKey = app(MemoDocumentKey::class)->forEditor($memo->refresh(), $memo->currentVersion?->refresh());

        $this->assertSame($initialKey, $activeKey);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build corrupt bytes that look like the beginning of a ZIP file.
     */
    private static function corruptDocxBytes(string $tag = ''): string
    {
        $padding = str_repeat("\x00", max(0, 20 - strlen($tag)));

        return "PK\x03\x04".$padding.$tag;
    }

    protected function callbackKey(Memo $memo, ?MemoVersion $version = null): string
    {
        return app(MemoDocumentKey::class)->forEditor($memo->refresh(), $version?->refresh());
    }

    protected function createMemo(User $user): Memo
    {
        $memo = Memo::create([
            'user_id' => $user->id,
            'title' => 'Memo Test',
            'memo_type' => 'memo_internal',
            'file_path' => 'memos/'.$user->id.'/memo.docx',
            'status' => Memo::STATUS_GENERATED,
            'searchable_text' => 'Memo Test',
        ]);

        $version = $memo->versions()->create([
            'version_number' => 1,
            'label' => 'Versi 1',
            'file_path' => $memo->file_path,
            'status' => Memo::STATUS_GENERATED,
            'configuration' => [],
            'searchable_text' => $memo->title,
        ]);

        $memo->forceFill(['current_version_id' => $version->id])->save();

        return $memo->refresh();
    }
}
