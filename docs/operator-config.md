# Operator Config Reference (Laravel-only Runtime)

Dokumen ini menjelaskan kunci konfigurasi yang dipakai oleh runtime Laravel dan
mapping-nya ke YAML Python lama (`python-ai/config/ai_config.yaml`). Folder
`python-ai/` saat ini hanya tinggal sebagai legacy/rollback — semua perilaku AI
runtime dikontrol dari `laravel/config/ai.php`, `laravel/config/mail.php`, dan
`laravel/.env`.

> Setelah memperbarui config, jalankan `php artisan config:clear` (atau
> `php artisan optimize:clear`) supaya cache tidak menahan nilai lama.

## 1. AI Cascade (Chat)

Cascade adalah daftar model yang dicoba berurutan saat node sebelumnya gagal
(rate limit, 4xx/5xx, context window habis, dst).

| Python YAML | Laravel | Catatan |
| --- | --- | --- |
| `lanes.chat.models[].label` | `config('ai.cascade.nodes.*.label')` | Tampil di stream sebagai `[MODEL:<label>]`. |
| `lanes.chat.models[].provider` | `config('ai.cascade.nodes.*.provider')` | `openai`, `groq`, `gemini` (lihat `LaravelChatService`). |
| `lanes.chat.models[].model_name` | `config('ai.cascade.nodes.*.model')` | Nama model di provider. |
| `lanes.chat.models[].api_key_env` | `config('ai.cascade.nodes.*.api_key')` | Laravel langsung pakai env, bukan nama env. |
| `lanes.chat.models[].base_url` | `config('ai.cascade.nodes.*.base_url')` | URL OpenAI-compatible (mis. `https://models.inference.ai.azure.com`). |

Override umum via `.env`:

```env
GITHUB_TOKEN=...
GITHUB_TOKEN_2=...
GROQ_API_KEY=...
GEMINI_API_KEY=...
AI_CASCADE_ENABLED=true
```

Default cascade nodes ada di `laravel/config/ai.php`:
GPT-4.1 (Primary) → GPT-4.1 (Backup) → GPT-4o (Primary) → GPT-4o (Backup) →
Llama 3.3 70B (Groq) → Gemini 3 Flash.

## 2. Embedding Cascade (Indexing & RAG)

| Python YAML | Laravel |
| --- | --- |
| `lanes.embedding.models` | `config('ai.embedding.cascade')` |

Default: `text-embedding-3-large` (primary & backup) → `text-embedding-3-small`
(2× fallback). Lihat `EmbeddingCascadeService`.

## 3. Reasoning Lane (Parity Placeholder)

Lane reasoning belum aktif di runtime; struktur config disediakan untuk parity
dengan Python YAML. Untuk eksperimen DeepSeek R1 atau model reasoning lain:

| Python YAML | Laravel |
| --- | --- |
| `lanes.reasoning.model` | `config('ai.reasoning_cascade.nodes')` (array) |
| (implicit) | `config('ai.reasoning_cascade.enabled')` (bool) |

Aktivasi:

```env
AI_REASONING_CASCADE_ENABLED=true
```

Lalu isi `nodes` di `config/ai.php`. Saat ini belum ada code-path yang membaca
`reasoning_cascade.nodes`; mengaktifkan flag tidak mengubah perilaku.

## 4. RAG / Document Retrieval

| Python YAML | Laravel |
| --- | --- |
| `retrieval.search.*` | `config('ai.rag.*')` |
| `retrieval.hyde.*` | `config('ai.hyde.*')` |
| `retrieval.parent_document.*` | `config('ai.parent_chunks.*')` (kalau ada) |

## 5. LangSearch (Web Search)

| Python YAML | Laravel | Env |
| --- | --- | --- |
| `integrations.langsearch.api_key` | `config('ai.langsearch.api_key')` | `LANGSEARCH_API_KEY` |
| (backup) | `config('ai.langsearch.api_key_backup')` | `LANGSEARCH_API_KEY_BACKUP` |
| `integrations.langsearch.api_url` | `config('ai.langsearch.api_url')` | `LANGSEARCH_API_URL` |
| `integrations.langsearch.rerank_url` | `config('ai.langsearch.rerank_url')` | `LANGSEARCH_RERANK_URL` |

Default endpoint: `https://api.langsearch.com/v1/web-search` &
`https://api.langsearch.com/v1/rerank`.

## 6. Prompts (Single Source of Truth)

Selaras dengan `prompts.*` di Python YAML. Ubah teks di
`laravel/config/ai.php` → `prompts` untuk mengkustomisasi behavior AI **tanpa**
menyentuh kode service.

| Python YAML | Laravel |
| --- | --- |
| `prompts.system.default` | `config('ai.prompts.system.default')` |
| `prompts.rag.document` | `config('ai.prompts.rag')` |
| `prompts.web_search.context` | `config('ai.prompts.web_search.context')` |
| `prompts.web_search.assertive_instruction` | `config('ai.prompts.web_search.assertive_instruction')` |
| `prompts.summarization.single` | `config('ai.prompts.summarization.single')` |
| `prompts.summarization.partial` | `config('ai.prompts.summarization.partial')` |
| `prompts.summarization.final` | `config('ai.prompts.summarization.final')` |
| `prompts.fallback.document_not_found` | `config('ai.prompts.fallback.document_not_found')` |
| `prompts.fallback.document_error` | `config('ai.prompts.fallback.document_error')` |

Variabel template yang tersedia (sama persis dengan Python):

- `{context_str}` — isi dokumen RAG
- `{web_section}` — block hasil web search yang ditempel ke RAG prompt
- `{question}` — pertanyaan user
- `{current_date}` — tanggal saat ini (format Indonesia)
- `{results}` — hasil pencarian web yang diformat
- `{document}` — isi dokumen untuk summarization
- `{batch}` — bagian dokumen untuk partial summarization
- `{part_number}` / `{total_parts}` — index bagian
- `{combined_summaries}` — gabungan ringkasan partial untuk final summary

## 7. SMTP Gmail

`config/mail.php` punya 2 mailer SMTP yang bisa dipakai:

| Mailer | Pakai env |
| --- | --- |
| `smtp` (default) | `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` |
| `gmail` (parity Python) | `MAIL_GMAIL_HOST`, `MAIL_GMAIL_PORT`, `MAIL_GMAIL_USERNAME`, `MAIL_GMAIL_PASSWORD` (fallback ke `MAIL_USERNAME`/`MAIL_PASSWORD` kalau kosong) |

Aktifkan profile `gmail`:

```env
MAIL_MAILER=gmail
MAIL_GMAIL_HOST=smtp.gmail.com
MAIL_GMAIL_PORT=587
MAIL_GMAIL_ENCRYPTION=tls
MAIL_GMAIL_USERNAME=alamat@gmail.com
MAIL_GMAIL_PASSWORD=xxxxxxxxxxxxxxxx   # App Password Google (16 karakter)
MAIL_FROM_ADDRESS=alamat@gmail.com
MAIL_FROM_NAME="ISTA AI"
```

> **Penting:** Gmail menolak password akun biasa untuk SMTP sejak 2022. Pakai
> App Password (16 karakter, tanpa spasi). Generate di
> https://myaccount.google.com/apppasswords (akun perlu 2-Step Verification).

Smoke test:

```bash
php artisan tinker
>>> Mail::raw('test', fn($m) => $m->to('penerima@example.com')->subject('SMTP test'));
```

## 8. Feature Flags & Toggle

| Env | Efek |
| --- | --- |
| `AI_CASCADE_ENABLED` | Aktifkan cascade fallback. Default `true`. |
| `AI_DOCUMENT_PROCESS_ENABLED` | Izinkan job processDocument. Default `true`. |
| `AI_DOCUMENT_SUMMARIZE_ENABLED` | Izinkan endpoint summarize. Default `true`. |
| `AI_DOCUMENT_RETRIEVAL_ENABLED` | Izinkan RAG retrieval. Default `true`. |
| `AI_REASONING_CASCADE_ENABLED` | Placeholder reasoning lane. Default `false`. |
| `AI_WEB_SEARCH_ENABLED` | Aktifkan tool web search. Default `true`. |

## 9. Rollback ke Python (sementara dinonaktifkan)

Folder `python-ai/` masih di-mount via `docker-compose.yml` tapi tidak dipanggil
runtime. Untuk rollback emergency: ubah `AIRuntimeResolver` agar mengarah ke
`PythonLegacyAdapter` (lihat `app/Services/AIRuntimeResolver.php`) dan jalankan
service Python di port 8001.

## 10. Verifikasi Konfigurasi

```bash
cd laravel
php artisan tinker
>>> config('ai.prompts.system.default')   # harus non-empty
>>> config('ai.reasoning_cascade.enabled') # false
>>> config('mail.mailers.gmail.host')      # smtp.gmail.com
```

Untuk test menyeluruh:

```bash
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test
```
