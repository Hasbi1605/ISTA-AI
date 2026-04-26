# Issue #112 — Follow-up: restore lost config sections + Gemini base_url + summarization prompt wire-up

## Latar belakang

Setelah merge sequence #103-#111 selesai, audit Devin Review menemukan beberapa concrete regression / leftover yang tidak tertangani saat resolve konflik:

1. **#107 review** — `LaravelChatService::streamChatCompletion()` default `base_url` ke `https://api.openai.com/v1` jika node tidak punya `base_url`. Node Gemini di `config/ai.php` cascade tidak punya `base_url` → dipanggil ke OpenAI URL dengan API key Gemini → selalu gagal. Last fallback cascade tidak berfungsi.

2. **#108 review** — saat resolve konflik `config/ai.php` (ambil PR #108's version dengan `--ours`), section yang sebelumnya ditambahkan PR #103 dan #106 ikut terhapus:
   - `rag.batching` (#103) — env var `RAG_BATCH_SIZE`, `RAG_MAX_TOKENS_PER_BATCH`, `RAG_BATCH_DELAY`, `RAG_BATCH_RETRY`, `RAG_BATCH_RETRY_DELAY` tidak terbaca lagi oleh `IngestThrottleService`.
   - `ocr` (#106) — env var `AI_OCR_ENABLED`, `TESSERACT_PATH`, `AI_OCR_FALLBACK_TESSERACT` tidak terbaca oleh `PdfParser`, `TesseractOcrService`, `OcrOrchestrator`.
   - `vision_cascade` (#106) — `VisionOcrService` mendapat `nodes = []` → throw `"No vision nodes configured"` setiap kali OCR cascade dipanggil → silently degrade ke Tesseract-only.

3. **#108 review** — `config('ai.prompts.summarization.single')` belum ada placeholder `{document}` yang seharusnya jadi single source of truth (parity dengan Python YAML).

4. **Stashed work** — saat resolve konflik #108, helper `LaravelDocumentService::buildSummarizationPrompt()` yang menyambung config prompts ke runtime summarization sempat di-stash dan tidak di-commit. Akibatnya runtime masih pakai string hardcoded.

## Scope perubahan

- `laravel/config/ai.php`:
  - Tambah ulang section `rag.batching` (5 env vars, default sama seperti #103).
  - Tambah ulang section `ocr` (8 env vars, default sama seperti #106 + 3 env tambahan untuk PdfToImageRenderer).
  - Tambah ulang section `vision_cascade` dengan 5 nodes (4 OpenAI-compatible + 1 Gemini, sama seperti #106).
  - Node Gemini di `cascade.nodes` ditambah `base_url` (default `https://generativelanguage.googleapis.com/v1beta/openai`) dan model jadi env-overridable. Tanpa ini cascade fallback terakhir hit OpenAI URL dengan key Gemini → 401.
  - Template `prompts.summarization.single` di-prepend `Dokumen:\n{document}` agar jadi single source of truth saat di-wire ke runtime.

- `laravel/app/Services/Document/LaravelDocumentService.php`:
  - Tambah helper `buildSummarizationPrompt(string $content): string` yang baca `config('ai.prompts.summarization.partial')` dan substitute `{batch}`, `{part_number}`, `{total_parts}`. Fallback ke string hardcoded saat config kosong.
  - `runSummarizationOnNode` + `runSummarizationDefault` panggil helper tersebut alih-alih hardcoded `'Rangkum bagian dokumen berikut...'`.

- `laravel/tests/Unit/Config/AiConfigParityTest.php`:
  - Tambah 5 test smoke baru: `rag_batching_keys_exist`, `ocr_keys_exist`, `vision_cascade_has_nodes`, `chat_cascade_nodes_all_have_base_url` (regression guard untuk Gemini bug), `summarization_single_template_has_document_placeholder`.

## Acceptance criteria

- [ ] `config('ai.rag.batching.*')`, `config('ai.ocr.*')`, `config('ai.vision_cascade.nodes')` tidak null lagi.
- [ ] Tiap node di `config('ai.cascade.nodes')` punya `base_url` (Gemini node tidak lagi default ke OpenAI).
- [ ] `config('ai.prompts.summarization.single')` mengandung `{document}`.
- [ ] `LaravelDocumentService::summarizeWithCascade()` runtime memakai template dari config (verified via test atau smoke).
- [ ] Full test suite tetap hijau tanpa regresi.

## Risiko

Minimal — semua perubahan additive (restore config) dengan default backwards-compatible. Helper `buildSummarizationPrompt` punya fallback ke string lama saat config null.
