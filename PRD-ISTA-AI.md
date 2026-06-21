# PRD — Project Requirements Document

**Product:** ISTA AI — Private-Document AI Assistant for Yogyakarta Presidential Palace Employees
**Document version:** 1.0
**Status:** Live (production-deployed, restricted access)
**App language:** Indonesian (UI & AI persona); this document in English
**Last updated:** June 2026

---

## 1. Overview

### 1.1 Product Summary

ISTA AI is a self-hosted AI assistant over **private documents** for employees of the
Yogyakarta Presidential Palace (Istana Kepresidenan Yogyakarta). It combines a Laravel web
application with Python AI microservices to provide a streaming chat assistant, document
upload + retrieval-augmented generation (RAG), official memorandum (memo) generation with
collaborative editing, document export, an internal knowledge base, and operational admin
dashboards.

The system is a **hybrid monorepo of two services**:

1. **`laravel/`** — web UI (Livewire/Volt + Blade + Alpine.js + Tailwind), authentication &
   authorization, file uploads, document/memo metadata, admin UI, queues, and OnlyOffice
   callbacks.
2. **`python-ai/`** — FastAPI AI services: chat streaming, document ingestion, RAG pipeline,
   model routing/cascade, embeddings, summarization, document export, and memo generation.

The two services communicate over HTTP with a shared bearer token. The vector store is
ChromaDB. Chat responses stream to the browser via **Server-Sent Events (SSE)** — not
WebSockets.

### 1.2 Product Goals

- Give palace staff a private, internal AI assistant that answers questions grounded in
  their own uploaded documents and an internal knowledge base.
- Reduce time spent drafting official memoranda by generating compliant `.docx` drafts that
  can be edited collaboratively (OnlyOffice) and exported (PDF/DOCX).
- Keep document storage, access control, and AI processing explicit and auditable.
- Provide administrators with visibility into AI usage, errors, documents, users, and the
  knowledge base.

### 1.3 Target Users

| Persona     | Description               | Key Needs                                                      |
| -------------| ---------------------------| ----------------------------------------------------------------|
| Staff user  | Palace employee           | Chat over private docs, generate/edit memos, import from Drive |
| Admin       | Operational administrator | Monitor usage/errors, manage documents/users/knowledge         |
| Super Admin | System owner              | All admin access + manage admin accounts with audit trail      |

### 1.4 Key Terms (Glossary)

- **RAG (Retrieval-Augmented Generation):** answering grounded in retrieved document chunks.
- **PDR (Parent Document Retrieval):** small child chunks for precise retrieval + larger
  parent chunks sent to the LLM for full context.
- **HyDE (Hypothetical Document Embeddings):** generating a short hypothetical answer to
  enrich retrieval.
- **Lane:** a configured group of AI models (chat / reasoning / embedding) with a fallback
  cascade.
- **Knowledge base (internal):** admin-curated documents available to all users in general chat.
- **Memo:** an official memorandum (`.docx`) generated and version-controlled.
- **SSE:** Server-Sent Events, the streaming transport for chat tokens.
- **OnlyOffice:** the document server used to edit generated DOCX via signed URL + JWT.

---

## 2. Requirements

### 2.1 Functional Requirements — User

| ID | Requirement |
|----|-------------|
| FR-U1 | Authenticated, email-verified users can start and continue chat conversations with streaming responses. |
| FR-U2 | The assistant automatically chooses the answering mode: document RAG (when documents are active), internal knowledge, web search, or general chat. |
| FR-U3 | Users can upload documents (PDF/DOCX/XLSX/CSV) which are ingested, chunked, embedded, and indexed for RAG. |
| FR-U4 | Active documents scope the chat to RAG mode over those documents. |
| FR-U5 | Users can preview a document (HTML render) and request a summary. |
| FR-U6 | Users can generate an official memorandum (`.docx`) from a structured form / chat context. |
| FR-U7 | Users can edit a generated memo via OnlyOffice (signed URL + JWT) with force-save persistence and versioning. |
| FR-U8 | Users can export documents/memos (HTML → PDF/DOCX) and download memo files. |
| FR-U10 | Chat history, conversations, and memo versions persist per user. |
| FR-U11 | Each chat answer records an AI usage event (feature, model, latency, status). |

### 2.2 Functional Requirements — Admin

| ID     | Requirement                                                                                |
| --------| --------------------------------------------------------------------------------------------|
| FR-A1  | Admins log in via a separate `/admin/login` flow distinct from the user login.             |
| FR-A2  | Admins must complete a forced password change when flagged before accessing the admin app. |
| FR-A3  | Admins can view a dashboard with KPI metrics aggregated from usage data.                   |
| FR-A4  | Admins can view AI usage analytics (chat, web search, document RAG, model, latency).       |
| FR-A5  | Admins can monitor failed/error AI events.                                                 |
| FR-A6  | Admins can manage documents and view ingest-pipeline status.                               |
| FR-A7  | Admins can manage users and view presence (online/last active).                            |
| FR-A8  | Admins can manage the internal knowledge base (CRUD + ingest pipeline).                    |
| FR-A9  | **Super Admins** can manage admin accounts (create/disable/role) with an audit trail.      |
| FR-A10 | Admin account changes are recorded in an immutable audit log (before/after snapshots).     |

### 2.3 Non-Functional Requirements

| ID | Category | Requirement |
|----|----------|-------------|
| NFR-1 | Security | The python-ai service is protected by a shared bearer token (constant-time verification). |
| NFR-2 | Privacy | Prompt content and document text are never logged; latency logging is privacy-safe. |
| NFR-3 | Security | Document access uses signed URLs, private disks, and server-side authorization. |
| NFR-4 | Security | OnlyOffice document access uses signed URLs + JWT. |
| NFR-5 | Security | Security headers including a Content Security Policy (CSP) are applied to responses. |
| NFR-6 | Reliability | The model router cascades across providers/accounts on rate-limit (429) or context-size (413) errors. |
| NFR-7 | Performance | Retrieval is tuned (top_k=5, HyDE smart mode, PDR) to reduce time-to-first-token. |
| NFR-8 | Scalability | Document ingestion and retrieval run in subprocesses; chat persistence uses a single-runner idempotent claim. |
| NFR-9 | Localization | UI and the AI persona respond in Indonesian. |
| NFR-10 | Configurability | All AI behavior (models, prompts, retrieval, chunking) is driven by a single YAML config. |
| NFR-11 | Rate limiting | Chat, stream, export, preview, OnlyOffice callback, and admin login endpoints are rate-limited. |

### 2.4 Business / System Rules

| ID | Rule |
|----|------|
| BR-1 | Only authenticated + email-verified users may access chat, documents, and memos. |
| BR-2 | Document uploads are capped at 50 MB and limited to PDF/DOCX/XLSX/CSV with safe filenames. |
| BR-3 | A document's `original_name` is unique per the system (deduplication). |
| BR-4 | Active documents force RAG mode; answers must prioritize explicit document content and avoid fabrication. |
| BR-5 | When the document has no answer, the assistant offers to continue via web search or general knowledge (no fabrication). |
| BR-6 | Assistant messages are persisted idempotently via a single-runner claim to prevent duplicates. |
| BR-7 | Admin access requires the `admin` role, an active account, and a completed forced password change. |
| BR-8 | Internal knowledge documents use scope `global_internal` / audience `all_users`. |
| BR-9 | Stale "in-progress" chat responses are resolved automatically after 10 minutes (scheduled). |
| BR-10 | Soft-deleted documents are purged after 7 days (scheduled). |

---

## 3. Core Features

### 3.1 Streaming Chat Assistant
A tabbed chat workspace (`ChatIndex` Livewire) where users converse with ISTA AI. The user
message is persisted, then the browser opens an SSE `EventSource` to
`GET /chat/stream/{conversationId}`. The `ChatStreamController` validates ownership, rebuilds
history from the database, resolves active-document context and source policy, and calls
`AIService::sendChat()` → python `/api/chat`. The Python service decides the answering mode
and streams tokens back. The controller sanitizes output, persists the assistant message
idempotently (single-runner claim), and emits events: `chunk`, `model-name`, `sources`,
`final-content`, `message-id`, `done`. The client renders a markdown typewriter (marked +
DOMPurify) with sources.

**Answering modes (decided server-side):**
- **Document RAG** — when active documents exist; retrieves relevant chunks (hybrid vector +
  BM25, HyDE, PDR) and answers grounded in them with sources.
- **Internal knowledge** — answers from the admin-curated internal knowledge base.
- **Web search** — augments with current external context via LangSearch (search + rerank).
- **General chat** — direct LLM answer when no document/knowledge/web context applies.

### 3.2 Document Upload & RAG
Users upload documents from the chat sidebar. A `Document` row is created and a
`ProcessDocument` job calls python `/api/documents/process`, which ingests in a subprocess:
load (PDF/DOCX/XLSX/CSV) → token-aware chunk → embed → store in ChromaDB with PDR
parent/child chunks and per-user metadata. Document status transitions
processing → ready/error; preview render status pending → ready/failed. Active documents
switch chat into RAG mode.

### 3.3 Document Preview, Summary & Export
- **Preview:** documents are rendered to HTML (`documents/{document}/preview/*`: status,
  stream, html).
- **Summary:** `/api/documents/summarize` performs single or hierarchical (token-budgeted
  batched) summarization.
- **Export:** `POST /documents/export` and table/content extraction convert HTML to a target
  format (PDF/DOCX) via python `/api/documents/export`.

### 3.4 Memo Generation & Collaborative Editing
`MemoWorkspace` (Livewire) → `MemoGenerationService` → python `/api/memos/generate-body`
produces an official memorandum `.docx` (naskah dinas style) with a searchable-text header
and creates a `MemoVersion`. Memos are version-controlled (`current_version_id`, revision
instructions). Editing is done in **OnlyOffice** via signed URLs + JWT, with force-save
persistence handled by `OnlyOfficeCallbackController` + `MemoForceSaveService`.

### 3.5 Internal Knowledge Base (Admin)
Admins curate an internal knowledge base. Knowledge documents are ingested via the
`ProcessKnowledgeDocument` job + `KnowledgeLifecycleService` → python `/api/knowledge`
(scope `global_internal`, audience `all_users`). This powers internal-knowledge answers in
general chat for all users. Reuses the document validator + ingest pipeline.

### 3.6 Google Drive Integration (removed)
The Google Drive integration (OAuth connect/callback, the `GoogleDrivePicker`, and document
export to Drive) has been removed entirely. Documents are now added only via local upload
(PDF/DOCX/XLSX/CSV), and answers/documents are exported via local download. The
`cloud_storage_files` and `google_drive_oauth_connections` tables have been dropped.

### 3.7 Admin Console
A dedicated admin app (separate login, role-gated) with:
- **Dashboard** — KPI metrics via `AdminMetricsService`.
- **Usage** — AI usage analytics (feature, model, latency, status) from `ai_usage_events`.
- **Errors** — monitoring of failed AI events.
- **Documents** — document management + ingest pipeline status.
- **Users** — user management + presence.
- **Knowledge** — internal knowledge base CRUD + ingest actions.
- **Accounts (Super Admin)** — admin account management + audit trail.

### 3.8 AI Model Routing & Configuration
A single `python-ai/config/ai_config.yaml` is the source of truth for chat model cascade
(GitHub Models GPT-4.1/4o/mini/nano with dual tokens → Groq Llama 3.3 → Mistral → Bedrock),
embeddings (OpenAI large/small → Bedrock Titan V2), retrieval (LangSearch search+rerank
top_k=5, hybrid BM25 0.3, HyDE smart), chunking (1500/150, PDR child 256 / parent 1500), and
all prompts (persona, RAG, web, summarization, memo, knowledge, HyDE, fallback). The router
falls back across models/accounts on rate-limit (429) or context-size (413) errors.

### 3.9 Authentication, Authorization & Security
Standard user auth (login/register/email verification/forgot/reset via Volt pages) plus a
separate admin login with forced password change, role/active-account guards, presence
tracking, CSP security headers, shared bearer token to python-ai, and signed-URL document
access.

---

## 4. User Flow

### 4.1 User Flow — Staff User

#### 4.1.1 Flow: Login & Email Verification
```
1. Visitor opens the app → guest dashboard / chat CTA.
2. Clicking a chat/memo CTA as a guest stores the intended URL and redirects to login.
3. User logs in (or registers → email verification).
   → Unverified email → redirected to the "verify email" notice; must verify via signed link.
   → Disabled/inactive account → access blocked.
4. On success, the user lands on the chat workspace (or the intended URL).
```

#### 4.1.2 Flow: Streaming Chat (Happy Path)
```
1. User opens chat/{id?} (ChatIndex).
2. User types a prompt and submits → the user message is persisted to DB.
3. The client opens EventSource GET /chat/stream/{conversationId}.
4. ChatStreamController validates ownership, rebuilds history, resolves document context +
   source policy, and calls AIService::sendChat() → python /api/chat.
5. Python decides the mode (RAG / internal knowledge / web search / general) and streams tokens.
6. The client renders a markdown typewriter; events arrive: model-name, sources, chunks,
   final-content, message-id, done.
7. The controller sanitizes and idempotently persists the assistant message; usage is logged
   to ai_usage_events.
8. The completed answer (with sources, if any) remains in the conversation history.
```

#### 4.1.3 Flow: Chat — Alternative / Error Scenarios
```
- No active document & no web/knowledge need → general chat answer.
- Active document but answer not present → assistant states it is not in the document and
  offers web search / general knowledge.
- Provider rate-limit (429) / context too large (413) → router cascades to the next model;
  user still receives an answer.
- All providers fail → an error sentinel is returned and the message is flagged is_error.
- Connection drop mid-stream → stale in-progress response resolved by the scheduled job
  (chat:resolve-stale-responses) after 10 minutes.
```

#### 4.1.4 Flow: Upload Document & RAG
```
1. User uploads a file (PDF/DOCX/XLSX/CSV ≤ 50 MB) from the chat sidebar.
   → Invalid type / oversize / duplicate original_name → rejected with a validation error.
2. A Document row is created (status: processing) and a ProcessDocument job is queued.
3. The job calls python /api/documents/process → subprocess ingest (load → chunk → embed →
   PDR store in ChromaDB).
4. Document status becomes ready (or error); preview render becomes ready/failed.
5. The user marks the document active → chat switches to RAG mode over that document.
6. Subsequent questions retrieve relevant chunks and answer with sources.
```

#### 4.1.5 Flow: Preview & Summarize
```
1. User opens a document preview → documents/{document}/preview/status then /stream or /html.
2. The HTML render is displayed when ready.
3. User requests a summary → /api/documents/summarize (single or hierarchical) → summary shown.
```

#### 4.1.6 Flow: Generate & Edit a Memo
```
1. User opens the Memo tab (MemoWorkspace) and fills the structured memo form.
2. Submit → MemoGenerationService → python /api/memos/generate-body returns a .docx.
3. A Memo + MemoVersion (version 1) is created; the file is stored privately.
4. User opens the memo in OnlyOffice via a signed URL + JWT and edits it.
5. OnlyOffice posts to /onlyoffice/callback/{memo}; force-save persists changes and creates
   a new version.
6. User downloads the memo or exports it to PDF (chat/memos/{memo}/download | export-pdf).
```

### 4.2 Admin Flow

#### 4.2.1 Flow: Admin Login (+ Forced Password Change)
```
1. Admin opens /admin/login (separate from user login).
2. Admin submits credentials (rate-limited 10/min).
   → Wrong credentials / inactive / non-admin → rejected.
3. On success:
   → If force_password_change is true → redirected to /admin/password/change; must set a new
     password before accessing the rest of /admin/*.
   → Otherwise → admin dashboard.
4. last_admin_login_at / last_admin_login_ip are recorded.
```

#### 4.2.2 Flow: Monitor Usage & Errors
```
1. Admin opens /admin (dashboard) → KPI metrics via AdminMetricsService.
2. Admin opens /admin/usage → AI usage analytics (feature, model, latency, status) from
   ai_usage_events with filters & pagination.
3. Admin opens /admin/errors → failed AI events for triage.
```

#### 4.2.3 Flow: Manage Documents
```
1. Admin opens /admin/documents → list of documents with ingest pipeline status.
2. Admin reviews/manages documents (status, reprocess, delete as applicable).
3. Deletions remove vectors via the document lifecycle / python delete endpoint.
```

#### 4.2.4 Flow: Manage Users
```
1. Admin opens /admin/users → user list with presence (online/last active).
2. Admin manages users (enable/disable, role within allowed scope).
3. Changes are auditable.
```

#### 4.2.5 Flow: Manage Internal Knowledge
```
1. Admin opens /admin/knowledge → knowledge sources & documents.
2. Admin uploads a knowledge document → ProcessKnowledgeDocument job +
   KnowledgeLifecycleService → python /api/knowledge (scope global_internal).
3. Status transitions draft → processing → ready (or failed with error_code/message).
4. Ready knowledge powers internal-knowledge answers in general chat for all users.
```

#### 4.2.6 Flow: Manage Admin Accounts (Super Admin only)
```
1. Super Admin opens /admin/accounts (not available to regular admins).
2. Super Admin creates/disables admin accounts or adjusts roles.
3. Each action writes an admin_account_audits entry with before/after snapshots, actor, IP,
   and user agent.
```

### 4.3 State — Document Status
```
processing → ready        (ingest + index succeeded)
processing → error        (ingest failed)
preview: pending → ready | failed   (HTML render)
soft-deleted → purged     (after 7 days, scheduled)
```

### 4.4 State — Knowledge Document Status
```
draft → processing → ready        (ingest succeeded; vectors stored)
processing → failed               (error_code / error_message set)
ready → archived                  (archived_at set)
```

### 4.5 Decision — Chat Answering Mode (server-side)
```
if active documents present:
    → Document RAG (hybrid retrieval + PDR); if no answer → offer web/general
elif internal knowledge applies (knowledge_internal_enabled & relevant):
    → Internal knowledge answer
elif web search needed (explicit request or time-sensitive):
    → Web search augmented answer (LangSearch search + rerank)
else:
    → General chat (persona system prompt)
```

---

## 5. Architecture

### 5.1 Tech Stack

| Layer | Technology |
|-------|------------|
| Web app | Laravel (PHP 8.2+) + **Livewire/Volt + Blade + Alpine.js** + Tailwind (Vite) |
| AI service | **FastAPI** + Pydantic 2, **litellm** (multi-provider routing), ChromaDB, rank-bm25, tiktoken |
| Streaming chat | **SSE** (`EventSource`) — not WebSocket/Echo |
| App data store | MySQL |
| Queue / cache | Redis |
| Vector store | ChromaDB (local persistent path) |
| Document editing | OnlyOffice Document Server (DOCX, signed URL + JWT) |
| AI providers | GitHub Models (GPT-4.1/4o/mini/nano), Groq Llama 3.3, Mistral, AWS Bedrock |
| Web search | LangSearch (web search + semantic rerank) |
| Embeddings | GitHub Models OpenAI (large/small) → AWS Bedrock Titan V2 |
| Doc parsing/export | pdfplumber, python-docx, openpyxl, pandas, weasyprint, beautifulsoup4 |

### 5.2 Service Topology

```
┌──────────────────────────────┐         HTTP + bearer token        ┌──────────────────────────────┐
│  laravel/  (web, :8000)       │ ─────────────────────────────────▶ │  python-ai/                   │
│  - Livewire UI (chat/memo/    │   POST /api/chat (SSE)             │  Chat service (:8001)         │
│    admin)                     │   POST /api/documents/process     │  app/chat_api.py (main.py)    │
│  - Auth/authorization         │   POST /api/documents/summarize   │                               │
│  - Uploads + metadata (MySQL) │   POST /api/documents/export      │  Document service             │
│  - Queues (Redis)             │   POST /api/knowledge/process     │  app/documents_api.py         │
│  - OnlyOffice callbacks       │   POST /api/memos/generate-body   │  (documents/knowledge/memos)  │
└──────────────┬───────────────┘                                    └───────────────┬──────────────┘
               │ SSE EventSource                                                     │
               ▼                                                                     ▼
        Browser (Alpine + marked + DOMPurify)                               ChromaDB (vectors)
                                                                            External AI/Search providers
```

The Laravel `AIService` uses **separate base URLs/tokens** for the chat service vs the
document service. Two FastAPI apps split chat (latency-critical streaming) from document
processing (heavier ingestion/export).

### 5.3 Architecture Patterns

- **Livewire-as-controller:** interactive user/admin UI lives in Livewire components, not
  classic controllers. Controllers handle SSE, files, callbacks, OAuth, and admin auth.
- **No `api.php` / `channels.php`:** SSE is plain HTTP; there is no broadcast channel.
- **Thin Laravel services bridging to Python:** `AIService`, `ChatOrchestrationService`,
  `DocumentLifecycleService`, `MemoGenerationService`, `KnowledgeLifecycleService`.
- **Subprocess isolation in Python:** ingestion (`document_runner`) and retrieval
  (`retrieval_runner`) run in subprocesses.
- **Single-runner idempotent persistence:** assistant messages are claimed once to avoid
  duplicates across the SSE path and the async job path.
- **YAML-driven AI config:** models/prompts/retrieval/chunking centralized in
  `ai_config.yaml`, with per-request runtime overrides.
- **Cascade model routing:** `llm_manager` / `llm_streaming` fall back across
  models/accounts on 429/413.

### 5.4 Key Endpoints

**Laravel (web.php / auth.php)**
```
GET  /                                  # dashboard
GET  /chat/{id?}                        # chat workspace (auth, verified, throttle:30,1)
GET  /chat/stream/{conversationId}      # SSE stream (auth, verified, throttle:60,1)
POST /onlyoffice/callback/{memo}        # OnlyOffice force-save callback (throttle:120,1)
GET  /chat/memos/{memo}/signed-file     # signed memo file
GET  /chat/memos/{memo}/download | /export-pdf ; POST /{memo}/force-save
GET  /documents/{document}/content-html | /extract-tables ; POST /documents/export
GET  /documents/{document}/preview/{status,stream,html}
GET/POST /admin/login ; POST /admin/logout ; GET/POST /admin/password/change
GET  /admin , /admin/users, /admin/usage, /admin/errors, /admin/documents, /admin/knowledge
GET  /admin/accounts                    # super admin
# auth.php: login, register→login, forgot/reset password, verify-email, confirm-password (Volt)
```

**Python AI services**
```
Chat service (:8001):
  GET  /api/health | /api/ready
  POST /api/chat                        # bearer token, SSE
Document service:
  GET  /api/health | /api/ready
  POST /api/documents/process | DELETE /api/documents/{filename}
  POST /api/documents/extract-tables | /extract-content | /export | /summarize
  POST /api/knowledge/process | DELETE /api/knowledge/{filename}
  POST /api/memos/generate-body
```

### 5.5 Middleware & Guards

| Middleware | Function |
|------------|----------|
| `auth`, `verified` | Authenticated + email-verified users |
| `admin` (`EnsureUserIsAdmin`) | Restricts the admin app to active admins |
| `super_admin` (`EnsureUserIsSuperAdmin`) | Restricts admin-account management to super admins |
| `admin.password_changed` (`EnsureAdminPasswordChanged`) | Enforces forced password change before admin app |
| `UpdateUserPresence` | Tracks user presence (last_seen_at / last_active_feature) |
| `AddSecurityHeaders` | Adds CSP & security headers |

### 5.6 Scheduled Commands (console.php)

| Command | Schedule | Function |
|---------|----------|----------|
| `documents:purge-deleted --days=7` | daily 03:00 | Purges soft-deleted documents older than 7 days |
| `chat:resolve-stale-responses --minutes=10` | every minute | Resolves stale in-progress chat responses |

---

## 6. Database Schema (Laravel / MySQL)

> Vector embeddings live in **ChromaDB**, not MySQL. The tables below cover application
> metadata, auth, usage, knowledge, and audit.

### 6.1 `users`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| name | string | |
| email | string unique | |
| email_verified_at | timestamp nullable | null = unverified |
| password | string (hashed) | |
| verification_code | string nullable | email verification |
| role | string(32) (index) | `user` \| `admin` \| `super_admin` (default user) |
| is_active | bool (index) | admin/account active flag |
| disabled_at / disabled_by / disabled_reason | nullable | account disable metadata |
| force_password_change | bool | forces admin password change |
| last_admin_login_at / last_admin_login_ip | nullable | admin login audit |
| last_seen_at (index) / last_active_feature | nullable | presence tracking |
| remember_token | string | |
| timestamps | | |

### 6.2 `conversations`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| user_id | FK → users (cascade) | |
| title | string nullable | |
| timestamps | | indexed for history |

### 6.3 `messages`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| conversation_id | FK → conversations (cascade) | |
| role | enum | system \| user \| assistant |
| content | text/longtext | message body |
| is_error | bool | error-flagged assistant message |
| document_ids | json nullable | documents active for this message |
| timestamps | | |

### 6.4 `documents`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| user_id | FK → users (cascade) | |
| filename / original_name | string | original_name unique |
| file_path | string | private storage path |
| source_provider | string(40) | always `local` (legacy column) |
| source_external_id / source_synced_at | nullable | legacy external source metadata |
| preview_html_path | string nullable | rendered preview path |
| preview_status | enum | pending \| ready \| failed |
| indexed_chunk_count / embedding_provider / indexed_at | nullable | index metadata |
| mime_type / file_size_bytes | nullable | file metadata |
| status | enum | processing \| ready \| error |
| timestamps | | |

### 6.5 `document_chunks`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| document_id | FK → documents (cascade) | |
| page_number | int nullable | |
| text_content | longtext | chunk text (vectors stored in ChromaDB) |
| timestamps | | |

### 6.6 `memos`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| user_id | FK → users (cascade) | |
| title | string | |
| memo_type | string(60) | memorandum type |
| file_path | string nullable | current docx path |
| current_version_id | bigint nullable (index) | active version pointer |
| status | string(40) | draft \| generated \| ... |
| source_conversation_id | FK → conversations nullable | |
| source_document_ids | json nullable | |
| chat_messages / configuration | (added) | generation context & config |
| searchable_text | text nullable | header searchable text |
| timestamps + softDeletes | | index (user_id, created_at) |

### 6.7 `memo_versions`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| memo_id | FK → memos (cascade) | |
| version_number | uint | unique per memo |
| label | string nullable | "Versi 1" |
| file_path | string nullable | |
| status | string(40) | generated \| ... |
| configuration | json nullable | generation config |
| searchable_text | text nullable | |
| revision_instruction | text nullable | revision note |
| timestamps | | unique (memo_id, version_number) |

### 6.8 `ai_usage_events`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| user_id | FK → users nullable | |
| feature | string(64) (index) | chat \| web_search \| document_rag \| ... |
| action | string(64) | |
| status | string(32) (index) | success \| error \| ... |
| request_id | string(64) (index) | correlation id |
| subject_id / subject_type | nullable | polymorphic subject |
| latency_ms | uint nullable | response latency |
| error_code | string(64) nullable | |
| metadata | json nullable | model label, tokens, etc. |
| timestamps | | composite indexes for analytics |

### 6.9 `knowledge_sources`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| created_by_id | FK → users nullable | |
| name | string | |
| slug | string unique | |
| description | text nullable | |
| is_active | bool | |
| timestamps | | |

### 6.10 `knowledge_documents`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| knowledge_source_id | FK → knowledge_sources nullable | |
| uploaded_by_id | FK → users nullable | |
| title / original_name / filename / file_path | string | |
| mime_type / file_size_bytes / checksum_sha256 | nullable | |
| scope | string(32) | default `global_internal` |
| audience | string(32) | default `all_users` |
| status | string(32) | draft \| processing \| ready \| failed \| archived |
| vector_namespace | string(64) | default `knowledge` |
| metadata / notes | nullable | |
| processed_at / archived_at / failed_at | nullable | |
| error_code / error_message | nullable | |
| timestamps | | indexes on status/scope/audience |

### 6.11 `knowledge_chunks`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| knowledge_document_id | FK (cascade, unique) | |
| chunk_count / successful_chunks / failed_chunks | uint | ingest stats |
| embedding_provider | string nullable | |
| summary | json nullable | |
| last_synced_at | timestamp nullable | |
| timestamps | | |

### 6.14 `admin_account_audits`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| actor_id | FK → users nullable | who performed the action |
| target_user_id | FK → users nullable | affected account |
| action | string(64) (index) | create/disable/role-change/... |
| ip_address / user_agent | nullable | request context |
| before_snapshot / after_snapshot | json nullable | state diff |
| metadata | json nullable | |
| timestamps | | index (target_user_id, action) |

### 6.15 System / Other Tables
`password_reset_tokens`, `sessions`, `cache`, `jobs`/`job_batches`/`failed_jobs` (Redis-backed
queue), `activity_log` (Spatie). Note: the legacy `ai_configuration` table was dropped — AI
config is now YAML-driven.

### 6.16 Key Relationships
```
users 1───* conversations 1───* messages
users 1───* documents 1───* document_chunks
users 1───* memos 1───* memo_versions
users 1───* ai_usage_events
knowledge_sources 1───* knowledge_documents 1───1 knowledge_chunks
users (actor) 1───* admin_account_audits *───1 users (target)
```

---

## 7. Design & Technical Constraints

### 7.1 Functional Constraints
- **Auth required:** chat, documents, and memos require authenticated + email-verified users.
- **Upload limits:** documents ≤ 50 MB, types PDF/DOCX/XLSX/CSV, safe filenames, unique
  `original_name`.
- **Grounded answering:** in RAG mode the assistant must prioritize explicit document content
  and must not fabricate details not present; it offers web/general fallback when unanswered.
- **Idempotent persistence:** assistant messages are claimed once (single-runner) to avoid
  duplicates between the SSE path and the async job path.
- **Stale recovery:** in-progress chat responses are resolved after 10 minutes; soft-deleted
  documents purged after 7 days.

### 7.2 Security & Privacy Constraints
- python-ai is protected by a shared bearer token with constant-time verification.
- Prompt content and document text are never logged; latency logging is privacy-safe.
- Document access uses signed URLs, private disks, and server-side authorization; OnlyOffice
  uses signed URL + JWT.
- Security headers including CSP are applied; markdown is sanitized (DOMPurify) on render.
- Secrets (AI provider keys, OnlyOffice secret, DB/OAuth credentials, `AI_SERVICE_TOKEN`)
  live only in local/deploy `.env` and must never be committed.
- Production documents, DB dumps, service-account JSON, and Chroma data must not be committed.

### 7.3 Technical Constraints
- **Hybrid two-service architecture:** Laravel (web/auth/UI) + Python FastAPI (AI). They must
  stay decoupled and communicate over authenticated HTTP.
- **SSE, not WebSocket:** chat streaming uses `EventSource`; there is no broadcast channel.
- **Two FastAPI apps:** chat service (latency-critical) is split from the document service.
- **Subprocess isolation:** ingestion and retrieval run in subprocesses to protect the API
  event loop.
- **YAML single source of truth:** all AI behavior is configured in `ai_config.yaml`; the
  legacy DB-driven AI config was removed.
- **Provider cascade:** the router must degrade gracefully across providers/accounts on
  429/413 instead of failing the request.
- **Vector store:** ChromaDB is a local persistent store; embedding dimensions must remain
  compatible (Titan V2 padded to the max embedding dimension).

### 7.4 AI / RAG Constraints
- Retrieval tuned for office documents: rerank top_k = 5, doc_candidates = 20; hybrid BM25
  weight 0.3; HyDE in **smart** mode (concept queries only) with a tight timeout.
- Chunking: 1500/150 tokens; PDR child 256 / parent 1500 (PDR requires re-upload to take
  effect for old documents).
- Embeddings: GitHub Models OpenAI large (3072-dim) → small (1536) → Bedrock Titan V2 (1024,
  padded), with TTL cache and token counting.
- Web search via LangSearch is augmentation-only; the model must use absolute dates and not
  fabricate real-time facts beyond returned results.

### 7.5 UI/UX Constraints
- Indonesian-language UI and AI persona ("ISTA AI"), warm but professional tone.
- Chat renders a markdown typewriter (marked) sanitized via DOMPurify; sources shown
  separately (no inline source dumps in the model output).
- Admin UI follows a shared design system (sidebar, table, tabs, KPI cards, badges, filters,
  empty states) with consistent pagination.
- Accessibility and responsive fixes are an ongoing priority.

### 7.6 Assumptions & Dependencies
- External AI providers (GitHub Models, Groq, Mistral, AWS Bedrock) and LangSearch are
  reachable and configured via env + YAML.
- MySQL, Redis, ChromaDB, and an OnlyOffice Document Server are available (Docker Compose for
  local/production).
- Email delivery (SMTP) is configured for verification/reset flows.

### 7.7 Out of Scope
- Public/unauthenticated use of chat, documents, or memos.
- The legacy booking domain (`BookingCreated`/`ScheduleUpdated` events and related code) —
  residual from a previous domain and not connected to AI flows; not to be relied upon.
- A reasoning lane (configured as `null`/disabled by default).
- A `pool`/manual distribution or any non-implemented config-only feature.

---

## 8. Acceptance Criteria

Format: Given / When / Then. Written to drive functional test generation.

### 8.1 Authentication & Access

**AC-AUTH-1 — Auth required for chat**
- Given an unauthenticated visitor
- When they try to open the chat/documents/memo routes
- Then they are redirected to login (with the intended URL preserved)

**AC-AUTH-2 — Email verification gate**
- Given a logged-in user with an unverified email
- When they access chat
- Then they are redirected to the email verification notice until they verify via the signed link

**AC-AUTH-3 — Disabled account blocked**
- Given a user/admin account that is disabled (is_active = false)
- When they attempt to access protected areas
- Then access is denied

### 8.2 Chat & Streaming

**AC-CHAT-1 — Streaming answer (general)**
- Given an authenticated user with no active document
- When they send a general prompt
- Then an SSE stream returns model-name, chunks, final-content, message-id, and done, and the
  answer is persisted in the conversation

**AC-CHAT-2 — Ownership enforced**
- Given a conversation that does not belong to the user
- When they open its stream endpoint
- Then the request is rejected (no cross-user access)

**AC-CHAT-3 — Document RAG mode**
- Given an authenticated user with at least one active, ready document
- When they ask a question answerable from the document
- Then the answer is grounded in the document content and sources are emitted

**AC-CHAT-4 — No fabrication when not in document**
- Given an active document that does not contain the answer
- When the user asks about missing detail
- Then the assistant states it is not in the document and offers web search / general knowledge

**AC-CHAT-5 — Provider cascade on limits**
- Given the primary model returns a rate-limit (429) or context-size (413) error
- When the chat request is processed
- Then the router falls back to the next model/account and the user still receives an answer

**AC-CHAT-6 — Idempotent assistant message**
- Given the SSE path and the async job path could both persist a response
- When a single answer completes
- Then exactly one assistant message is stored (no duplicate)

**AC-CHAT-7 — Usage event recorded**
- Given a completed chat answer
- When the response finishes
- Then an ai_usage_events row is recorded with feature, status, latency, and model metadata

**AC-CHAT-8 — Stale response recovery**
- Given a chat response stuck in-progress (e.g., dropped connection)
- When 10 minutes pass
- Then the scheduled chat:resolve-stale-responses command resolves it

### 8.3 Documents

**AC-DOC-1 — Valid upload ingests**
- Given an authenticated user
- When they upload a valid PDF/DOCX/XLSX/CSV ≤ 50 MB
- Then a Document is created (processing) and becomes ready after ingestion, with chunks indexed

**AC-DOC-2 — Invalid upload rejected**
- Given an upload that is the wrong type, exceeds 50 MB, or duplicates an existing original_name
- When the user submits it
- Then it is rejected with a validation error and no vectors are created

**AC-DOC-3 — Preview render**
- Given a document being processed
- When the user opens its preview
- Then the preview status transitions pending → ready and the HTML render is shown (or failed on error)

**AC-DOC-4 — Summarize**
- Given a ready document
- When the user requests a summary
- Then a single or hierarchical summary is returned in the configured Indonesian format

**AC-DOC-5 — Export**
- Given a ready document
- When the user exports it
- Then the content is converted to the requested format (PDF/DOCX) and returned

**AC-DOC-6 — Purge soft-deleted**
- Given a soft-deleted document older than 7 days
- When the daily purge command runs
- Then the document and its vectors are removed

### 8.4 Memos

**AC-MEMO-1 — Generate memo**
- Given an authenticated user filling the memo form
- When they generate
- Then a .docx is produced, a Memo + MemoVersion (v1) is created, and the file is stored privately

**AC-MEMO-2 — Edit via OnlyOffice force-save**
- Given a generated memo opened in OnlyOffice via a signed URL + JWT
- When the user edits and OnlyOffice posts the force-save callback
- Then the changes are persisted and a new memo version is created

**AC-MEMO-3 — Download / export PDF**
- Given a memo with a stored file
- When the user downloads or exports to PDF
- Then the correct file is returned to the authorized owner only

**AC-MEMO-4 — Memo content rules**
- Given a memo generation request
- When the body is generated
- Then it follows naskah-dinas formatting and does not fabricate names/NIP/positions not in the configuration

### 8.5 Knowledge Base (Admin)

**AC-KB-1 — Ingest knowledge document**
- Given an admin on /admin/knowledge
- When they upload a knowledge document
- Then it is ingested (scope global_internal) and its status transitions draft → processing → ready

**AC-KB-2 — Knowledge powers general chat**
- Given a ready internal knowledge document relevant to a query
- When any user asks a related question in general chat
- Then the answer can draw on the internal knowledge; if insufficient, the assistant says so honestly

**AC-KB-3 — Ingest failure surfaced**
- Given a knowledge document that fails ingestion
- When the pipeline errors
- Then status becomes failed with error_code/error_message recorded

### 8.6 Admin Console

**AC-ADM-1 — Separate admin login**
- Given an admin
- When they log in at /admin/login (rate-limited)
- Then they authenticate via the admin flow and last_admin_login_at/ip are recorded

**AC-ADM-2 — Forced password change**
- Given an admin with force_password_change = true
- When they log in
- Then they are routed to /admin/password/change and cannot access other /admin/* until they change it

**AC-ADM-3 — Role-gated admin app**
- Given a non-admin user
- When they try to open /admin
- Then access is denied

**AC-ADM-4 — Usage analytics**
- Given existing ai_usage_events
- When the admin opens /admin/usage
- Then usage metrics (feature, model, latency, status) are shown with filters and pagination

**AC-ADM-5 — Error monitoring**
- Given failed AI events
- When the admin opens /admin/errors
- Then the failed events are listed for triage

**AC-ADM-6 — User presence**
- Given active users
- When the admin opens /admin/users
- Then user presence (online/last active) is displayed

### 8.7 Admin Accounts (Super Admin)

**AC-SADM-1 — Accounts restricted to super admin**
- Given a regular admin (not super admin)
- When they try to open /admin/accounts
- Then access is denied

**AC-SADM-2 — Account change audited**
- Given a super admin
- When they create/disable an admin or change a role
- Then an admin_account_audits entry is written with actor, target, before/after snapshots, IP, and user agent

### 8.8 Security & Privacy

**AC-SEC-1 — python-ai token required**
- Given a request to a python-ai endpoint without/with an invalid bearer token
- When it is received
- Then it is rejected (constant-time verification)

**AC-SEC-2 — Document access authorized**
- Given a document belonging to another user
- When a user requests its file/preview/export
- Then access is denied (server-side authorization + signed URLs)

**AC-SEC-3 — No sensitive logging**
- Given a chat or document request
- When it is processed
- Then prompt content and document text are not written to logs (only privacy-safe metadata)

---

## Appendix A — Endpoint Summary for Test Scenarios

| User Action | Endpoint | Method | Auth |
|-------------|----------|--------|------|
| Open chat workspace | `/chat/{id?}` | GET | User |
| Stream chat answer | `/chat/stream/{conversationId}` | GET (SSE) | User |
| Document preview status | `/documents/{document}/preview/status` | GET | User |
| Export document | `/documents/export` | POST | User |
| Memo force-save (OnlyOffice) | `/onlyoffice/callback/{memo}` | POST | Signed/JWT |
| Memo download / export PDF | `/chat/memos/{memo}/download` / `/export-pdf` | GET | User |
| Admin login | `/admin/login` | POST | Public (guest) |
| Admin dashboard | `/admin` | GET | Admin |
| Admin usage | `/admin/usage` | GET | Admin |
| Admin knowledge | `/admin/knowledge` | GET | Admin |
| Admin accounts | `/admin/accounts` | GET | Super Admin |
| AI chat (internal) | `/api/chat` | POST (SSE) | Bearer token |
| Document process (internal) | `/api/documents/process` | POST | Bearer token |
| Knowledge process (internal) | `/api/knowledge/process` | POST | Bearer token |
| Memo generate (internal) | `/api/memos/generate-body` | POST | Bearer token |

## Appendix B — Roles & Access

| Role | Access |
|------|--------|
| User | Chat, documents, memos, exports |
| Admin | All user features + admin dashboard, usage, errors, documents, users, knowledge |
| Super Admin | All Admin access + admin account management with audit trail |

## Appendix C — AI Model Cascade (from `ai_config.yaml`)

| Lane | Order (fallback cascade) |
|------|--------------------------|
| Chat | GPT-4.1 → GPT-4o → GPT-4.1-mini → GPT-4.1-nano (each ×2 GitHub tokens) → Groq Llama 3.3 70B → Mistral Medium/Small → Bedrock (GPT-OSS 120B, GLM 4.7, Nova Micro) |
| Embedding | GitHub OpenAI large (3072) ×2 → OpenAI small (1536) ×2 → Bedrock Titan V2 (1024, padded) |
| Reasoning | disabled (null) |
| Web search / rerank | LangSearch web-search + langsearch-reranker-v1 (top_k 5) |
