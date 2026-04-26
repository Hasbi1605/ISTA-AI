# Issue #100 (proposed) — Fix cascade chat: pakai `/chat/completions` bukan `/responses`

Parent blueprint: #84  
Discovered selama audit Laravel-only E2E (lihat `audit-laravel-only-final.md` §4.2).

## Latar Belakang

`LaravelChatService` saat ini memanggil chat completion via `Laravel\Ai\AiManager::textProvider()` yang akhirnya dieksekusi oleh `vendor/laravel/ai/src/Gateway/OpenAi/OpenAiGateway.php`. Gateway tersebut **selalu** memakai endpoint `/responses` (OpenAI Responses API):

- `OpenAiGateway.php:72` `$this->client(...)->post('responses', $body)`
- `Concerns/HandlesTextStreaming.php:398` `->post('responses', $body)`

Endpoint GitHub Models (`https://models.inference.ai.azure.com`) yang dipakai cascade **tidak** mendukung Responses API:

```
curl -X POST .../responses        → 404 {"code":"api_not_supported"}
curl -X POST .../chat/completions → 200 OK
```

Akibatnya seluruh node OpenAI di cascade pulang `HTTP 404`, padahal token valid. Chat real-runtime **tidak akan pernah berhasil** sampai bug ini di-fix.

Issue ini adalah blocker decommission `python-ai`.

## Tujuan

- `LaravelChatService` memanggil `chat/completions` langsung lewat `Illuminate\Http\Client\Factory` (bypass `laravel/ai` SDK pada path text generation), sambil tetap mempertahankan:
  - cascade fallback antar node
  - marker `[MODEL:{label}]` & `[SOURCES:[…]]`
  - integrasi LangSearch (web search)
  - kompatibilitas test (`Http::fake()`)
- Tidak menyentuh embedding cascade (SDK `embeddings` endpoint native didukung GitHub Models).
- Tidak menyentuh dokumen retrieval / hybrid retrieval / HyDE.

## Scope

### In-scope

- `app/Services/Chat/LaravelChatService.php`:
  - Tambah method `streamChatCompletion(array $node, string $systemPrompt, string $userPrompt, array $initialSources): \Generator` yang melakukan SSE streaming via `Http::withToken()->withOptions(['stream' => true])->post(rtrim($node['base_url'],'/').'/chat/completions', [...])` dan parsing chunked SSE delta.
  - Ganti 4 occurrence `$provider = $this->getProviderForNode(...)` + `$provider->stream($promptObj)` + `streamResponseWithSources(...)` dengan `streamChatCompletion(...)`.
  - Hapus method `getProviderForNode()` (no longer used).
  - Pertahankan method `streamResponseWithSources()` yang tetap dipakai untuk citation events kalau ada (untuk forward compat saat SDK suatu hari di-fix).

- `tests/Unit/Services/Chat/LaravelChatServiceTest.php`:
  - Ganti Mockery `provider->stream` mocks → `Http::fake()` SSE stream mock terhadap URL `models.inference.ai.azure.com/chat/completions`.
  - Pertahankan assertion `[MODEL:...]`, body content, `[SOURCES:...]`, dan cascade fallback (primary 5xx → backup OK).

### Out-of-scope

- Embedding cascade (separate path, SDK works for it).
- OCR vision cascade (PR #106).
- Issue #99 config parity.

## Acceptance Criteria

1. `LaravelChatService::chat()` tanpa dokumen → real-runtime call ke GitHub Models cascade berhasil (response stream menghasilkan `[MODEL:<label>]` + content + optional `[SOURCES:...]`).
2. `LaravelChatService` dengan dokumen aktif (RAG) → streaming sukses, citation block tetap muncul.
3. Cascade fallback berjalan: kalau node #1 pulang 4xx, lanjut node #2.
4. `php artisan test` Laravel hijau (existing tests + tambahan baru). Test parity LangSearch yang fail di audit (`AIParityMatrixTest`) di luar scope — dibahas terpisah.
5. Tidak ada lagi panggilan ke endpoint `/responses` dari `LaravelChatService`.
6. Tidak ada perubahan di `vendor/laravel/ai/*` (forking SDK ditolak).

## Verifikasi

- `cd laravel && php artisan test --filter=LaravelChatService`
- `cd laravel && php artisan test`
- Smoke E2E lokal: jalankan `php artisan serve`, login, kirim chat, pastikan response stream muncul.

## Risiko

- Bila SDK `laravel/ai` upgrade ke v0.7+ dan memperkenalkan opsi endpoint, refactor ulang akan dibutuhkan untuk re-route ke SDK lagi. Risk-mitigation: keep code minimal & well-commented.
- SSE parsing manual: harus handle `data: [DONE]` end marker, partial-chunk buffering, dan trailing newlines dari berbagai server.
- Beberapa provider `groq`/`gemini` mungkin tidak share schema yang sama (Gemini v1beta misalnya). Saat ini cascade hanya ada OpenAI-compatible nodes, jadi aman; ke depan jika ada Gemini native, perlu adapter terpisah.
