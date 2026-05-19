# Roadmap Completion: Issues #217, #212, and Deferred #215

## Latar Belakang
Parent issue #209 memecah dashboard admin ISTA AI menjadi beberapa child issue. Fondasi admin, monitoring, knowledge upload, dan UI admin dasar sudah tersedia. Setelah klarifikasi produk, modul ISTURA (#215) tidak dilanjutkan di parent #209 karena kemungkinan akan dibuat sebagai sistem baru terpisah. Scope aktif tersisa:

- #217 Knowledge Internal Retrieval On Demand
- #212 Admin AI Configuration
- #215 ISTURA Excel to API (deferred/not planned untuk parent ini)

## Tujuan
- Menyelesaikan #217 dan #212 masing-masing di branch dan PR terpisah.
- Menutup #215 sebagai deferred/not planned dan memperbarui parent #209 agar ISTURA tidak menjadi blocker.
- Memastikan setiap PR punya test relevan, review, dan komentar approve-style sebelum merge.
- Setelah PR #217 dan #212 approve, merge, deploy ke production, dan lakukan QA end-to-end.
- Verifikasi parent #209 lalu close jika semua child issue dan kriteria parent benar-benar terpenuhi.

## Ruang Lingkup
- #217: rule-based intent detector, retrieval knowledge internal active/global only, top-k kecil, threshold, integrasi prompt chat, dan metadata usage event.
- #212: DB-backed prompt/model config untuk super admin, draft/active/archive/versioning, resolver runtime dengan fallback, audit, rollback, playground ringan, dan UI admin.
- #215: tidak dikerjakan pada parent ini; ditutup sebagai deferred/not planned karena ISTURA kemungkinan menjadi sistem baru terpisah.

## Di Luar Scope
- Mengubah secret/API key lewat dashboard.
- Approval workflow multi-admin.
- Calendar sync/WhatsApp otomatis untuk ISTURA.
- Implementasi ISTURA Excel/API di repo/scope parent ini.
- Refactor besar chat pipeline di luar kebutuhan integrasi runtime config.
- Menampilkan prompt/jawaban/dokumen user secara penuh di admin telemetry.

## Area / File Terkait
- Laravel routes, admin Livewire, models, migrations, services, tests.
- Python chat API, RAG retrieval/prompt, config loader, tests.
- Docker/production deploy hanya setelah PR merged ke main.

## Risiko
- Knowledge internal bisa bocor jika filter Chroma tidak ketat; wajib `user_id=__knowledge__`, `scope=global_internal`, dan `knowledge_status=active`.
- Chat biasa bisa melambat jika detector terlalu agresif; detector harus rule-based dan retrieval hanya saat relevan.
- AI config yang salah bisa merusak semua jawaban; wajib fallback env/config lama dan guardrail hardcoded.
- Tiga PR berurutan bisa konflik jika branch tidak dibuat dari main terbaru setelah merge sebelumnya.

## Langkah Implementasi
1. Close #215 sebagai deferred/not planned dan update parent #209 dengan alasan produk.
2. Sinkronkan `main`, buat branch `codex/issue-217-knowledge-retrieval`.
3. Implement #217 di Python + metadata Laravel, tambah test, buat PR, review dan fix sampai approve-style.
4. Merge #217, sinkronkan `main`, buat branch `codex/issue-212-admin-ai-config`.
5. Implement #212, tambah test, buat PR, review dan fix sampai approve-style.
6. Merge PR #217 dan #212 ke `main`, deploy production dengan docker compose production.
7. QA end-to-end production untuk admin knowledge retrieval, AI config, dan non-regression admin/chat.
8. Jika QA gagal, buat hotfix PR, merge, deploy ulang, dan ulang QA.
9. Baca ulang parent #209 dan close jika semua checklist child non-ISTURA selesai.

## Rencana Test
- #217:
  - `cd python-ai && source venv/bin/activate && pytest tests/test_knowledge_internal_retrieval.py tests/test_retrieval_runner.py tests/test_knowledge_router.py`
  - `cd laravel && php artisan test --filter='AIUsageEvent|ChatStream|ChatOrchestration'`
- #212:
  - `cd laravel && php artisan test --filter='AIConfig|Admin|Chat|Memo'`
  - Python test kontrak payload jika model/prompt override masuk ke service Python.
- Akhir:
  - `cd laravel && php -d memory_limit=512M vendor/bin/phpunit --stop-on-failure`
  - `cd python-ai && source venv/bin/activate && pytest`
  - `cd laravel && npm run build`

## Kriteria Selesai
- #215 ditutup sebagai deferred/not planned dan parent #209 menjelaskan alasan skip.
- #217 dan #212 punya PR terpisah yang sudah direview dan diberi komentar `✅ Approve`.
- PR #217 dan #212 merged ke `main`.
- Production berada di commit main terbaru dan container sehat.
- QA production membuktikan fitur tersedia dan tidak ada error utama.
- Parent issue #209 hanya ditutup setelah semua child issue relevan selesai/closed dan kriteria parent terpenuhi.
