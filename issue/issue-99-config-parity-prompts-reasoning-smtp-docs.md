# Issue #99 — Config Parity: Prompts + Reasoning Lane + SMTP Gmail + Operator Docs

Issue: https://github.com/Hasbi1605/ISTA-AI/issues/99

## Latar Belakang

Audit Laravel-only menemukan empat acceptance criteria #99 yang belum terpenuhi:

1. **Prompts hardcoded di kode Laravel.** Python config `python-ai/config/ai_config.yaml` punya
   `prompts.system.default`, `prompts.web_search.context`, `prompts.web_search.assertive_instruction`,
   `prompts.summarization.{single,partial,final}`, `prompts.fallback.*`. Laravel hanya punya
   `prompts.rag` di `config/ai.php`; sisanya di-hardcode di
   `LaravelChatService::getSystemPrompt()`, `getWebSearchPrompt()`, dan
   `LaravelDocumentService::summarizeDocument()`.
2. **Reasoning lane belum ada.** Python config punya `lanes.reasoning` (default null,
   placeholder DeepSeek). Laravel belum punya struktur ekuivalen untuk eksperimen reasoning model
   tanpa menyentuh kode.
3. **Profile Gmail SMTP belum eksplisit.** `config/mail.php` Laravel tinggal default `smtp` mailer.
   Python config punya `integrations.smtp_gmail` profile siap pakai.
4. **Dokumen operator belum ada.** Operator tidak tahu cara mapping antara YAML Python lama
   (yang masih dirujuk di skrip rollback) dan key Laravel di `config/ai.php` / `config/mail.php`.

## Scope

### In-scope

- `laravel/config/ai.php`:
  - Tambah `prompts.system.default`, `prompts.web_search.context`,
    `prompts.web_search.assertive_instruction`, `prompts.summarization.{single,partial,final}`,
    `prompts.fallback.{document_not_found,document_error}`.
  - Tambah `reasoning_cascade.enabled` (default false) + `reasoning_cascade.nodes` (default
    array kosong) untuk parity dengan `lanes.reasoning` Python (placeholder, tidak aktif).
  - Pertahankan `prompts.rag` (sudah ada).

- `laravel/app/Services/Chat/LaravelChatService.php`:
  - `getSystemPrompt()` baca `config('ai.prompts.system.default')` dengan fallback ke string
    hardcoded sekarang (back-compat).
  - `getWebSearchPrompt()` baca `config('ai.prompts.web_search.assertive_instruction')`.
  - Method baru `getWebSearchContextTemplate()` baca `config('ai.prompts.web_search.context')`
    untuk template saat membangun konteks web (variabel: `{current_date}`, `{results}`).
  - Tidak ada perubahan behavior ketika config kosong (back-compat default).

- `laravel/app/Services/Document/LaravelDocumentService.php`:
  - `summarizeDocument()` baca `instructions` dan `prompt` dari
    `config('ai.prompts.summarization.single')` (split header & body), fallback ke string lama.

- `laravel/config/mail.php`:
  - Tambah named mailer `gmail` (smtp transport, host=smtp.gmail.com, port=587, tls). Default
    mailer tetap di-control via `MAIL_MAILER`.

- `laravel/.env.example`:
  - Tambah/normalisasi entri `MAIL_HOST=smtp.gmail.com`, `MAIL_PORT=587`,
    `MAIL_ENCRYPTION=tls`, `MAIL_USERNAME=`, `MAIL_PASSWORD=`, `MAIL_FROM_ADDRESS=`,
    `MAIL_FROM_NAME=` dengan placeholder kosong (BUKAN credential nyata).

- `docs/operator-config.md` (file baru):
  - Tabel mapping Python YAML key → Laravel config key.
  - Cara override via `.env` (provider key, model, base_url, prompt).
  - Cara mengaktifkan reasoning lane.
  - Cara setup Gmail SMTP (App Password Google, bukan password akun).

- Test:
  - `tests/Unit/Services/Chat/LaravelChatServiceTest.php` — test baru:
    `test_get_system_prompt_uses_config_when_set`.
  - `tests/Unit/Services/Document/LaravelDocumentServiceTest.php` — test baru:
    `test_summarize_document_uses_config_prompt_when_set`.
  - Pastikan parity matrix test (`tests/Feature/Parity/AIParityMatrixTest.php`) tidak regresi.

### Out-of-scope

- TIDAK refactor cascade endpoint path (sudah ditangani di PR #107).
- TIDAK menyentuh `python-ai/` (folder legacy/rollback only).
- TIDAK mengaktifkan reasoning lane secara live (hanya struktur config, default off).
- TIDAK migrasi prompts existing ke prompt baru — kalimat asli dari Python YAML
  diadopsi apa adanya (single source of truth pindah ke `config/ai.php`).

## Acceptance Criteria

1. `config('ai.prompts.system.default')` return string non-kosong setelah patch (default value
   diambil dari Python YAML `prompts.system.default`).
2. `config('ai.prompts.web_search.context')` return template dengan placeholder
   `{current_date}` & `{results}`.
3. `config('ai.prompts.summarization.single')` return template summarisasi multi-section.
4. `config('ai.reasoning_cascade.enabled')` return `false` secara default.
5. `config('ai.reasoning_cascade.nodes')` return array kosong by default.
6. `config('mail.mailers.gmail')` return array dengan `transport=smtp`, `host=smtp.gmail.com`,
   `port=587`, `encryption=tls`.
7. `LaravelChatService::getSystemPrompt()` mengembalikan nilai dari config saat config diisi,
   fallback ke string hardcoded saat config null.
8. `LaravelDocumentService::summarizeDocument()` menggunakan template dari config saat tersedia.
9. File `docs/operator-config.md` ada dan menyebutkan minimal: cascade nodes, prompts, reasoning
   lane, Gmail SMTP, env override.
10. `php artisan test` lulus tanpa regresi dari baseline (205 passed, 3 incomplete pre-existing).

## Risiko

- **Low: Override prompt yang salah dari `.env` memengaruhi semua chat.** Mitigasi: prompt tetap
  di `config/ai.php` (PHP), bukan `.env`. `.env` hanya untuk credential & toggle.
- **Low: Config `reasoning_cascade` terbawa di runtime tetapi belum dipakai.** Mitigasi: marker
  `enabled=false` secara eksplisit; tidak ada code-path yang membaca `nodes` selain placeholder.
- **Low: Fallback ke hardcoded string saat config kosong dapat menyembunyikan typo config key.**
  Mitigasi: test memvalidasi kedua jalur (config diisi & config kosong).

## Verifikasi

- `cd laravel && APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test`
- Manual: `php artisan tinker` → `config('ai.prompts.system.default')` non-empty.

## Dependency

- Tidak depend ke PR #107 (touch line yang berbeda di LaravelChatService). Aman di-rebase
  setelah #107 merge.
