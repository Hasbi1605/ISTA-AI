# Issue #113 — Summarization cascade SDK bypass (follow-up Devin Review #110)

## Latar belakang

Devin Review pada PR #110 menemukan bahwa `LaravelDocumentService::summarizeWithCascade()` masih memakai SDK `laravel/ai` (`AnonymousAgent` + `AgentPrompt` + `$provider->prompt()`). SDK selalu memanggil endpoint `/responses` (OpenAI Responses API) — endpoint yang **tidak didukung** oleh GitHub Models (`models.inference.ai.azure.com`) yang menjadi target cascade primary.

Ini adalah class bug yang sama dengan yang sudah di-fix PR #107 untuk path chat (`LaravelChatService::streamChatCompletion`), tapi belum di-mirror untuk path summarization. Akibatnya:

- Setiap kali `summarizeDocument` dipanggil di runtime, semua cascade nodes OpenAI-compatible (#1-#4 + #5 Gemini setelah PR #112) gagal dengan HTTP 404 `api_not_supported`.
- `runSummarizationDefault` fallback juga pakai SDK → ikut gagal.
- User mendapat pesan error generic, summarization fitur tidak berfungsi end-to-end.

## Scope perubahan

`laravel/app/Services/Document/LaravelDocumentService.php`:

- Ganti `runSummarizationOnNode()` dan `runSummarizationDefault()` dari SDK call ke direct HTTP `POST {base_url}/chat/completions` via `Illuminate\Support\Facades\Http`. Mirror approach `LaravelChatService::streamChatCompletion()` (PR #107) tapi non-streaming (summarization tidak butuh SSE).
- Tambah helper `callChatCompletion()` yang construct OpenAI-compatible request body, validate response, return content.
- Tambah helper `getSummarizationInstructions()` yang baca `config('ai.prompts.summarization.instructions')` dengan fallback string lama (back-compat dengan PR #108).
- Tambah `use Illuminate\Support\Facades\Http;`.

`laravel/tests/Unit/Services/Document/LaravelDocumentServiceTest.php`:

- Tambah test `test_summarize_calls_chat_completions_endpoint_not_responses` yang:
  1. Stub `Http::fake` untuk `/chat/completions` (200) + `/responses` (404).
  2. Panggil `summarizeWithCascade()` via anonymous subclass.
  3. Assert response cocok dengan `/chat/completions` content.
  4. Assert `Http::assertSent` URL ends with `/chat/completions` (regression guard).

## Acceptance criteria

- [ ] `runSummarizationOnNode` / `runSummarizationDefault` tidak lagi memanggil SDK `$provider->prompt()`.
- [ ] Test baru lulus dan akan gagal kalau ada regresi (SDK path balik).
- [ ] Full test suite tetap hijau (15 LaravelDocumentService tests + 5 baru).

## Risiko

Low — perubahan terbatas pada 2 fungsi internal `LaravelDocumentService`. Public API (`summarizeDocument`) tidak berubah. Semua test existing tetap pass karena mereka mock `summarizeWithCascade()` atau `runSummarizationOnNode()` di higher level.

## Dependency

Branch ini di-stack di atas `devin/1777199500-followup-fixes-config-restore` (PR #112) karena sama-sama menyentuh `LaravelDocumentService.php`. Setelah PR #112 merge, PR ini akan auto-rebase clean.
