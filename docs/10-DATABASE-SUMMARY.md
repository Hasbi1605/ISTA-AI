# Database Summary

Dokumen ini merangkum tabel penting Laravel. Detail kolom lengkap dapat dilihat
di `laravel/database/migrations/`.

## Auth dan Session

| Tabel | Fungsi |
| --- | --- |
| `users` | User, admin, role, active status, email verification, admin security, 2FA |
| `trusted_devices` | Trusted device admin untuk 2FA |
| `password_reset_tokens` | Token reset password |
| `sessions` | Session Laravel |
| `cache`, `cache_locks` | Cache dan lock Laravel |

## Chat dan Dokumen

| Tabel | Fungsi |
| --- | --- |
| `conversations` | Riwayat percakapan per user |
| `messages` | Pesan user/assistant, status streaming, metadata sumber |
| `documents` | Metadata file upload, status ingest/preview, ownership |
| `document_chunks` | Metadata chunk lokal Laravel; vector utama ada di Chroma |

## Memo

| Tabel | Fungsi |
| --- | --- |
| `memos` | Memo milik user, konfigurasi, current version, chat messages |
| `memo_versions` | Versi file DOCX memo |

## Prompy Studio

| Tabel | Fungsi |
| --- | --- |
| `generated_prompts` | Parent riwayat prompt owner-scoped |
| `generated_prompt_versions` | Versi hasil generate/revisi, package JSON, reference image metadata |

## Knowledge Base

| Tabel | Fungsi |
| --- | --- |
| `knowledge_sources` | Kelompok/source knowledge |
| `knowledge_documents` | Dokumen knowledge dan status pipeline |
| `knowledge_chunks` | Ringkasan metadata chunk knowledge |

## Admin dan Observability

| Tabel | Fungsi |
| --- | --- |
| `ai_usage_events` | Usage, model, status, latency, metadata aman |
| `admin_account_audits` | Audit perubahan akun admin |
| `activity_log` | Log aktivitas dari Spatie activitylog |
| `jobs`, `job_batches`, `failed_jobs` | Infrastruktur queue Laravel |

## Tabel Legacy yang Sudah Dihapus oleh Migration

- `ai_model_configs`, `ai_prompt_profiles`, `ai_config_audits` - AI config lama berbasis DB.
- `cloud_storage_files`, `google_drive_oauth_connections` - Google Drive integration lama.
- `presentations`, `presentation_versions` - generator PPTX internal lama.

## Relasi Inti

```text
users 1-* conversations 1-* messages
users 1-* documents
users 1-* memos 1-* memo_versions
users 1-* generated_prompts 1-* generated_prompt_versions
knowledge_sources 1-* knowledge_documents 1-* knowledge_chunks
users 1-* ai_usage_events
users (actor) 1-* admin_account_audits *-1 users (target)
```

## Catatan Data Sensitif

- `documents`, `memos`, `memo_versions`, `generated_prompts`, dan Chroma data harus diperlakukan sebagai data privat.
- Backup database saja belum cukup bila ingin restore penuh; storage file dan Chroma juga perlu dipertimbangkan.
