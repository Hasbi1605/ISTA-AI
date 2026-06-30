# Architecture

Dokumen ini adalah ringkasan arsitektur. Detail lengkap ada di
`docs/CODEBASE-CONTEXT.md`.

## Komponen Utama

| Komponen | Peran |
| --- | --- |
| `laravel/` | UI, auth, authorization, upload, metadata dokumen, memo, admin, queue, OnlyOffice callback |
| `python-ai/` chat service | FastAPI untuk `/api/chat`, streaming SSE dari model |
| `python-ai/` document service | FastAPI untuk ingest dokumen, export, summary, memo, knowledge, Prompy |
| MySQL | Data aplikasi: user, chat, dokumen, memo, usage, admin audit |
| Redis | Queue/cache |
| ChromaDB | Vector store dokumen dan knowledge |
| OnlyOffice | Editing DOCX memo |
| Provider AI/search | GitHub Models, Groq, Gemini, Bedrock, LangSearch sesuai konfigurasi |

## Alur Chat

```text
User submit pesan
  -> Livewire ChatIndex simpan pesan user
  -> browser buka EventSource /chat/stream/{conversationId}
  -> ChatStreamController validasi ownership dan context
  -> ChatOrchestrationService tentukan dokumen/knowledge/web policy
  -> AIService POST ke python-ai /api/chat
  -> Python pilih mode general/RAG/knowledge/web
  -> token dikirim balik sebagai stream
  -> Laravel persist assistant message secara idempoten
  -> client render markdown yang disanitasi
```

## Alur Dokumen RAG

```text
Upload dokumen
  -> Document row dibuat
  -> ProcessDocument job
  -> python-ai /api/documents/process
  -> load file, split token-aware, embedding
  -> simpan vector di Chroma
  -> dokumen ready
```

## Alur Memo

```text
MemoWorkspace
  -> MemoGenerationService
  -> python-ai /api/memos/generate-body
  -> DOCX disimpan private
  -> MemoVersion dibuat
  -> OnlyOffice membuka signed URL + JWT
  -> callback force-save membuat versi baru
```

## Alur Prompy Studio

```text
PrompyStudio
  -> PromptStudioService
  -> validasi platform, jenis, reference image, dokumen acuan
  -> python-ai /api/prompts/generate atau /api/prompts/chat
  -> vision analysis untuk reference image bila ada
  -> LLM membuat/revisi paket prompt JSON
  -> GeneratedPrompt dan GeneratedPromptVersion disimpan owner-scoped
```

## Konfigurasi AI

`python-ai/config/ai_config.yaml` adalah single source of truth untuk:

- urutan model dan fallback;
- model embedding;
- prompt system/RAG/web/memo/knowledge/Prompy;
- retrieval, rerank, HyDE, chunking, dan timeout.

Secret provider tetap di `.env`, bukan di YAML.

## Catatan Desain

- UI interaktif berada di Livewire, bukan controller tebal.
- Controller menangani SSE, file, callback, auth admin, dan endpoint khusus.
- Tidak ada `routes/api.php` atau broadcast channel untuk chat.
- Python service dilindungi bearer token.
- Deployment production memakai Docker Compose dan Caddy sebagai reverse proxy/TLS.
