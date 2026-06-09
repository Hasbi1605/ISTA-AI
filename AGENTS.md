# AGENTS.md — ISTA AI (Magang-Istana)

## Tentang repo
Monorepo hybrid **Laravel (Livewire/Volt + Blade + Alpine.js) + python-ai (FastAPI)** untuk
asisten AI atas dokumen privat pegawai Istana Kepresidenan Yogyakarta. `laravel/` melayani web
UI, auth/otorisasi, upload, metadata, admin UI, queue, dan callback OnlyOffice; `python-ai/`
melayani chat streaming (SSE), ingest dokumen, RAG, model routing, embeddings, summarization,
export, dan memo. Data di MySQL + Redis; vektor di ChromaDB. Konfigurasi AI tunggal di
`python-ai/config/ai_config.yaml`.

## WAJIB dibaca dulu (context)
Sebelum mengubah kode, baca dokumen konteks ini agar tidak eksplorasi ulang:
1. `docs/CODEBASE-CONTEXT.md` — peta backend (Laravel + Livewire) & AI service (python-ai), fitur, dan flow user/admin. **Acuan utama.**
2. `PRD-ISTA-AI.md` — produk, requirements, core features, user/admin flow, arsitektur, skema DB, constraints.
3. `README.md` — gambaran produk, arsitektur hybrid, dan setup. `docs/` lain bila relevan (`data-flow-privacy.md`, `production-*`, `deploy-*`).

Jika perubahanmu mengubah struktur folder, service, route, Livewire/Blade, router/endpoint
python-ai, atau flow, **perbarui `docs/CODEBASE-CONTEXT.md`** pada bagian yang relevan di perubahan yang sama.

## Cara bekerja
- Untuk tugas kompleks, mulai dengan plan singkat di percakapan sebelum menulis kode. Repo ini tidak memakai folder `issue/` atau PR flow lagi.
- Setelah verifikasi memadai, perubahan boleh langsung di-commit ke `main`, push ke remote, lalu deploy sesuai scope tugas.
- Gunakan perubahan sekecil mungkin yang menyelesaikan masalah. Jangan refactor besar
  kecuali diminta atau benar-benar diperlukan.
- Ikuti pola yang sudah ada: thin controller, **UI interaktif di Livewire**, logika domain di
  Service, `AIService` sebagai jembatan HTTP ke python-ai, prompt/model diatur lewat
  `ai_config.yaml` (bukan hardcode). Chat memakai **SSE** (`EventSource`), bukan Echo/WebSocket.
- python-ai: prompt sebagai single source of truth di `config/ai_config.yaml`; jaga pipeline
  RAG (chunk/embed/PDR/retrieval) dan cascade model tetap konsisten.
- Jika ada ketidakpastian, nyatakan asumsi secara eksplisit.

## Verifikasi wajib
Setiap perubahan kode wajib diverifikasi pada area terdampak.
- **Laravel:** jika menyentuh `laravel/`, jalankan `cd laravel && php artisan test` (atau filter test relevan).
- **Python:** jika menyentuh `python-ai/`, aktifkan virtualenv lalu jalankan
  `cd python-ai && source venv/bin/activate && pytest` (atau test relevan).
- Jika perilaku penting berubah tetapi test belum ada, tambahkan test dulu.
- Jangan anggap tugas selesai bila test relevan belum dijalankan. Untuk perubahan lintas-layanan,
  jalankan verifikasi penuh kedua sisi (Laravel + Python) sebelum dianggap selesai.

### Perintah cepat
```bash
cd laravel && php artisan test                       # test Laravel
cd python-ai && source venv/bin/activate && pytest   # test Python (aktifkan venv dulu)
cd laravel && php artisan serve                      # web app
cd laravel && npm run dev                            # Vite (Livewire/Alpine assets)
cd python-ai && source venv/bin/activate && uvicorn app.main:app --host 127.0.0.1 --port 8001  # AI service
```

## Keamanan (jaga selalu)
- python-ai dilindungi bearer token (`verify_token`); jangan log/echo isi prompt, dokumen, atau
  rahasia. `AIService` sudah meredaksi rahasia di log — pertahankan.
- Token AI, secret OnlyOffice/JWT, kredensial DB, OAuth, dan API key provider hanya di `.env`
  lokal/deploy. Jangan commit `.env` nyata, dokumen produksi, dump DB, service-account JSON, atau data Chroma.
- Akses dokumen via signed URL, private disk, dan otorisasi server-side. Baca `docs/data-flow-privacy.md` sebelum memakai data nyata.
- Endpoint admin harus tetap di belakang `admin` / `super_admin` / `admin.password_changed` sesuai kebutuhan. Jangan melonggarkan tanpa alasan eksplisit.

## Ekspektasi output
Saat menyelesaikan tugas, ringkas: apa yang diubah, file utama yang disentuh, test yang
dijalankan, test yang ditambahkan, risiko/tindak lanjut, dan update changelog.

## Done when
- Tujuan tercapai, perubahan utama terimplementasi.
- Test relevan dijalankan (dan ditambahkan bila sebelumnya belum ada/memadai); untuk perubahan
  lintas-layanan, test Laravel & Python sama-sama dijalankan.
- `docs/CODEBASE-CONTEXT.md` diperbarui bila struktur/flow berubah.
- Entri changelog ditambahkan (lihat di bawah).
- Risiko & tindak lanjut diringkas.

---

## Aturan Changelog (WAJIB)
Setiap kali membuat atau melakukan perubahan pada repo ini, **catat entri changelog** di
bagian "## Changelog" di bawah. Aturan:
- Tambahkan entri baru di **paling atas** daftar (terbaru di atas).
- Format: `- YYYY-MM-DD — <ringkas perubahan> — file/area utama — (test: <hasil>)`.
- Satu entri per perubahan logis. Tetap ringkas dan faktual.
- Jangan menghapus entri lama; changelog bersifat append-only sebagai jejak riwayat.
- Changelog ini adalah indeks ringkas semua perubahan repo.

## Changelog
- 2026-06-09 — Mengubah workflow repo menjadi langsung commit/push ke `main` dan deploy tanpa folder `issue/` maupun PR flow, serta menyesuaikan README. — `AGENTS.md`, `README.md`, `docs/testing-guide.md`, `docs/workflow-review.md`, `issue/` — (test: n/a, dokumentasi; `git diff --check` pass)
- 2026-06-09 — Memperbaiki bug lintas Laravel-python-ai untuk export PDF link aman, sync status knowledge Chroma, stale job knowledge, gate import Google Drive/upload lokal, dan healthcheck readiness production. — `laravel/app/Services/Knowledge/KnowledgeLifecycleService.php`, `laravel/app/Jobs/ProcessKnowledgeDocument.php`, `laravel/app/Services/DocumentLifecycleService.php`, `laravel/app/Services/CloudStorage/GoogleDriveService.php`, `python-ai/app/routers/knowledge.py`, `python-ai/app/services/document_export.py`, `python-ai/app/services/rag_ingest.py`, `docker-compose.production.yml`, `docs/CODEBASE-CONTEXT.md` — (test: targeted Python 49 pass; targeted Laravel 59 pass; full PHPUnit 590 pass via `php -d memory_limit=-1 vendor/bin/phpunit`; full pytest 378 pass; `git diff --check` pass)
- 2026-06-09 — Menyelaraskan `AGENTS.md` agar konsisten dengan template `AGENTS.md` istura-app (struktur: Tentang repo, WAJIB dibaca dulu, Cara bekerja, Verifikasi wajib + Perintah cepat, Keamanan, Ekspektasi output, Done when, Changelog). — `AGENTS.md` — (test: n/a, dokumentasi)
- 2026-06-08 — Mengunci Google Drive OAuth pusat agar wajib active admin/super-admin selain setup key/allowlist email, dan mengetatkan trusted URL callback OnlyOffice ke scheme/host/port exact. — `laravel/app/Services/CloudStorage/GoogleDriveOAuthService.php`, `laravel/app/Http/Controllers/OnlyOfficeCallbackController.php`, `laravel/tests/Feature/CloudStorage/GoogleDriveCentralOAuthTest.php`, `laravel/tests/Feature/Memos/OnlyOfficeCallbackTest.php`, `docs/CODEBASE-CONTEXT.md`, `docs/production-config-guide.md`, `deploy/digitalocean.env.example` — (test: targeted PHPUnit 43 pass; full PHPUnit 584 pass via `php -d memory_limit=-1 vendor/bin/phpunit`; pytest 374 pass; composer audit 1 ignored mitigated; Vite build pass)
- 2026-06-08 — Hardening security audit: tutup public registration default production, kunci Horizon ke super-admin aktif, hilangkan `unsafe-eval` default production, mitigasi email CRLF CVE Laravel 11, update dependency Laravel/Symfony, dan test Chroma internal-only. — `laravel/`, `python-ai/tests/test_production_compose_security.py`, `docker-compose.production.yml`, `docs/production-config-guide.md` — (test: PHPUnit 580 pass; pytest 374 pass; composer audit 1 ignored mitigated; npm audit clean; pip-audit 1 Chroma no fixed version, mitigated internal-only)
- 2026-06-08 — Menambahkan `PRD-ISTA-AI.md` (PRD lengkap: overview, requirements, core features, user/admin flow, arsitektur, skema DB, constraints, acceptance criteria) untuk aplikasi testing eksternal. — `PRD-ISTA-AI.md` — (test: n/a, dokumentasi)
- 2026-06-08 — Menambahkan `docs/CODEBASE-CONTEXT.md` (peta Laravel + python-ai, fitur, flow user & admin) dan menambahkan bagian "WAJIB dibaca dulu (context)" + aturan changelog di `AGENTS.md`. — `AGENTS.md`, `docs/CODEBASE-CONTEXT.md` — (test: n/a, dokumentasi)
