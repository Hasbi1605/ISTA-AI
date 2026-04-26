# Issue #101 — RAG Runtime Fixes (PDF parser + Provider File Search default)

## Konteks

Re-test E2E pasca-merge PR #103 (parsing/chunking) + PR #107 (chat /chat/completions) menemukan dua bug runtime yang membuat RAG happy-path tidak berfungsi:

1. `smalot/pdfparser:^0.15` (versi yang dipakai PR #103) gagal parse PDF apapun pada runtime karena dependensi lama `TCPDF_PARSER` tidak otomatis terpasang. Test PR #103 lolos karena hanya pakai `method_exists`/`ReflectionMethod`, tidak pernah memanggil `parser->parseFile()` pada PDF nyata.
2. Default `AI_USE_PROVIDER_FILE_SEARCH=true` mengarahkan retrieval ke OpenAI Files API + Assistants File Search via SDK `laravel/ai`. GitHub Models tidak menyediakan endpoint tersebut → SDK lempar 401 → chat dengan dokumen mengembalikan "Saya belum bisa membaca konteks dokumen".

## Tujuan
- RAG happy-path berhasil end-to-end Laravel-only (upload PDF → status=ready → chunks tersimpan → chat dengan filename → jawaban berisi konten dokumen).
- Default config selaras dengan provider runtime (GitHub Models) tanpa konfigurasi tambahan.
- Regresi parser tertangkap test sebelum runtime.

## Acceptance Criteria
- [ ] `smalot/pdfparser` minimal `^2.0` pada `composer.json`.
- [ ] `composer.lock` ter-update dengan v2.x.
- [ ] `config('ai.laravel_ai.use_provider_file_search')` default = `false`.
- [ ] `.env.example` set `AI_USE_PROVIDER_FILE_SEARCH=false`.
- [ ] Test runtime baru `PdfParserRuntimeTest` yang benar-benar memanggil `PdfParser::parse()` pada PDF nyata dan memverifikasi marker teks bisa diekstrak.
- [ ] `php artisan test` tetap hijau pada test suite Laravel.
- [ ] Runtime smoke: upload PDF dengan watermark `TBR-7Q3X-DEVIN-2026` → chat jawab dengan watermark.

## Scope
**In scope:**
- `laravel/composer.json` + `laravel/composer.lock` — bump pdfparser.
- `laravel/config/ai.php` + `laravel/.env.example` — flip default file-search.
- `laravel/tests/Unit/Services/Document/Parsing/PdfParserRuntimeTest.php` — test runtime parser baru.
- `issue/issue-101-rag-runtime-fixes.md` — dokumen ini.

**Out of scope:**
- Refactor `LaravelDocumentRetrievalService::searchViaProviderFileSearch` (tetap dibiarkan untuk operator yang punya akun OpenAI native).
- Embedding cascade vs hybrid search wiring (issue lain).
- Generasi embedding pada path `processWithLaravel` (chunks tetap disimpan dengan `embedding=null`; BM25 cukup untuk happy-path).

## Risiko
- **Bump versi major (0.15 → 2.x)** — API public `Smalot\PdfParser\Parser::parseFile()` + `Page::getText()` tetap kompatibel; smoke test runtime mengonfirmasi.
- **Operator yang masih pakai OpenAI native** sekarang harus secara eksplisit set `AI_USE_PROVIDER_FILE_SEARCH=true`. Akan didokumentasikan di `.env.example`.

## Ketergantungan
- Berdasar di atas branch `feature/issue-91-parsing-chunking-throttle-laravel` (PR #103). PR #103 harus merge dulu, kemudian PR ini.
