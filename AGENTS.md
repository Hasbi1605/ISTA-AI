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
- Setelah verifikasi memadai, perubahan boleh langsung di-commit ke `main` dan push ke remote. Setelah push berhasil, laporkan hasilnya lalu stop; jangan menunggu workflow CI/CD atau deploy production selesai kecuali user meminta eksplisit.
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
- 2026-06-20 — Fase 3 epic Presentasi (#222+#223+#224): pipeline generate Laravel end-to-end. #222 `PresentationGenerationService` (render PPTX via python `/api/presentations/generate`, outline deterministik, simpan private disk) + job `GeneratePresentation` (claim-token guard pending→processing→ready/error, validasi source docs owned+ready, invalidasi pdf saat regenerate) + `FEATURE_PRESENTATION_GENERATION`. #224 `PresentationConverter` (PPTX→PDF via OnlyOffice) + `PresentationDocumentKey` (signed internal URL) + `PresentationFileController` (download PPTX/PDF owner-only, cache pdf, abort 502 saat konversi gagal) + route `presentations.{download,export.pdf,file.signed}`. #223 `PresentationWorkspace` Livewire penuh (form konfigurasi hybrid, pilih dokumen ready, status/history, download, retry, hapus; sub-mode Prompy Studio placeholder) + `PresentationLifecycleService`. — `laravel/app/Services/Presentations/*`, `laravel/app/Jobs/GeneratePresentation.php`, `laravel/app/Services/OnlyOffice/{PresentationConverter,PresentationDocumentKey}.php`, `laravel/app/Http/Controllers/Presentations/PresentationFileController.php`, `laravel/app/Livewire/Presentations/PresentationWorkspace.php`, `laravel/resources/views/livewire/presentations/presentation-workspace.blade.php`, `laravel/routes/web.php`, `laravel/app/Models/AIUsageEvent.php`, `laravel/app/Livewire/Admin/AdminUsage.php`, `laravel/tests/Feature/Presentations/{PresentationGenerationTest,PresentationFileTest,PresentationWorkspaceTest}.php`, `docs/CODEBASE-CONTEXT.md` — (test: full PHPUnit 582 pass via `php -d memory_limit=-1 vendor/bin/phpunit`; filter Presentation 32 pass; python-ai tidak disentuh. Catatan: outline MVP deterministik (pengayaan AI menyusul); OnlyOffice editor penuh tetap di #226)
- 2026-06-20 — Fase 2 epic Presentasi (#221): Python PPTX Renderer MVP — endpoint `/api/presentations/generate` (+`/templates`) di document microservice (verify_token), renderer deterministic outline→PPTX 16:9 dengan logo/header/footer personalisasi + 5 template visual (Resmi Klasik, Modern Minimal, Executive Brief, Data & Tabel, Kegiatan & Dokumentasi), local asset registry (logo emblem + ikon, no-internet), validasi template/slide_count/bullet/required fields, dependency `python-pptx==1.0.2`. — `python-ai/requirements.txt`, `python-ai/app/services/{presentation_render.py,presentation_assets.py}`, `python-ai/app/routers/presentations.py`, `python-ai/app/documents_api.py`, `python-ai/tests/{test_presentation_render.py,test_app_routing.py}`, `docs/CODEBASE-CONTEXT.md` — (test: pytest full 394 pass via venv; renderer deterministic tanpa AI/internet; Laravel tidak tersentuh)
- 2026-06-20 — Fase 1 epic Presentasi (#218): tambah tab Presentasi 3-mode di shell ISTA AI di belakang feature flag `features.presentation` + normalisasi tab (alias `presentasi`, fallback `chat`), placeholder `PresentationWorkspace`, dan data model + akses presentasi (migration `presentations`, model `Presentation` dengan helper fail-closed owned+ready, `PresentationPolicy` owner/download-ready). — `laravel/config/features.php`, `laravel/app/Livewire/Chat/ChatIndex.php`, `laravel/app/Livewire/Presentations/PresentationWorkspace.php`, `laravel/resources/views/livewire/{chat/chat-index.blade.php,chat/partials/chat-memo-tab-toggle.blade.php,presentations/presentation-workspace.blade.php}`, `laravel/resources/js/chat-page.js`, `laravel/database/migrations/2026_06_20_000001_create_presentations_table.php`, `laravel/app/Models/Presentation.php`, `laravel/app/Policies/PresentationPolicy.php`, `laravel/tests/Feature/{Chat/ChatUiTest.php,Presentations/PresentationModelTest.php}`, env examples, `docs/CODEBASE-CONTEXT.md` — (test: full PHPUnit 563 pass via `php -d memory_limit=-1 vendor/bin/phpunit`; `npm run build` pass; python-ai tidak tersentuh)
- 2026-06-18 — Menambahkan aturan workflow agar setelah push berhasil agent langsung berhenti dan tidak menunggu CI/CD atau deploy production selesai kecuali diminta eksplisit. — `AGENTS.md` — (test: n/a, dokumentasi)
- 2026-06-18 — Memperbarui lock dependency frontend untuk menutup audit npm terbaru (`dompurify` 3.4.11, `form-data` 4.0.6, `vite` 6.4.3) agar workflow CI/CD tidak berhenti di audit frontend. — `laravel/package-lock.json` — (test: `npm audit --audit-level=high` clean; `npm run build` pass)
- 2026-06-18 — Memperbaiki CSP production yang mematikan interaksi Livewire/Alpine di `/login` dengan compatibility `unsafe-eval` hanya untuk response HTML ber-marker Livewire/Alpine, plus test regresi header CSP login dan dokumentasi env. — `laravel/app/Http/Middleware/AddSecurityHeaders.php`, `laravel/config/security.php`, `laravel/tests/Feature/SecurityHeadersTest.php`, env examples, docs production/codebase — (test: SecurityHeadersTest 5 pass; full PHPUnit 549 pass via `php -d memory_limit=-1 vendor/bin/phpunit`; `php artisan test` terkena limit memori 128MB)
- 2026-06-10 — Memperbaiki kegagalan CI `npm audit` dengan upgrade devDependency `concurrently` ke ^10.0.3 sehingga `shell-quote` naik ke versi patched 1.8.4. — `laravel/package.json`, `laravel/package-lock.json` — (test: npm audit --audit-level=high clean; npm run build pass; full PHPUnit 547 pass via `php -d memory_limit=-1 vendor/bin/phpunit`)
- 2026-06-09 — Menutup celah deprovisioning akun nonaktif dengan cek `is_active` pada login reguler dan middleware `active` untuk route user terautentikasi, plus test regresi akun admin nonaktif. — `laravel/app/Http/Middleware/EnsureUserIsActive.php`, `laravel/app/Livewire/Forms/LoginForm.php`, `laravel/routes/web.php`, `laravel/routes/auth.php`, `laravel/tests/Feature/Auth/AuthenticationTest.php`, `docs/CODEBASE-CONTEXT.md` — (test: targeted Laravel 70 pass; full PHPUnit 547 pass via `php -d memory_limit=-1 vendor/bin/phpunit`; route-list middleware aktif terverifikasi; `git diff --check` pass)
- 2026-06-09 — Menghapus total fitur Google Drive (import picker + export/upload ke Drive): hapus controller/service/model/Livewire/blade/ikon Drive, route OAuth, konstanta usage `GOOGLE_DRIVE_*`, relasi `cloudStorageFiles`, dependency `google/apiclient`, key env `GOOGLE_DRIVE_*`, dan fungsi Drive di `chat-page.js`; tambah migration drop `cloud_storage_files` & `google_drive_oauth_connections`; pertahankan ekspor/unduh lokal & kolom `documents.source_*` (selalu `local`). — `laravel/app/{Http/Controllers/CloudStorage,Livewire/Chat,Models,Services/CloudStorage}`, `laravel/routes/web.php`, `laravel/config/services.php`, `laravel/resources/{js/chat-page.js,views}`, `laravel/database/migrations/2026_06_09_000001_drop_google_drive_tables.php`, `laravel/composer.{json,lock}`, `docs/CODEBASE-CONTEXT.md`, `PRD-ISTA-AI.md`, `docs/production-config-guide.md`, env examples — (test: php artisan test 547 pass; npm run build pass; composer audit clean; python-ai tidak tersentuh)
- 2026-06-09 — Menaikkan action resmi GitHub CI/CD ke major Node 24 (`actions/checkout@v6`, `actions/setup-node@v6`, `actions/setup-python@v6`) agar annotation deprecation Node 20 hilang, tetap mempertahankan env force Node 24 sebagai guard. — `.github/workflows/ci-cd.yml` — (test: action.yml resmi v6 memakai node24; git diff --check pass; workflow YAML parse pass)
- 2026-06-09 — Mengaktifkan `FORCE_JAVASCRIPT_ACTIONS_TO_NODE24` di workflow CI/CD agar action JavaScript berjalan dengan runtime Node 24 dan tidak bergantung pada Node 20 deprecated. — `.github/workflows/ci-cd.yml` — (test: git diff --check pass; workflow YAML parse pass)
- 2026-06-09 — Menambahkan GitHub Actions CI/CD untuk test Laravel/Python dan deploy otomatis production saat push ke `main` via SSH + Docker Compose, termasuk dokumentasi secret dan update panduan deploy. — `.github/workflows/ci-cd.yml`, `.gitignore`, `docs/github-actions-cicd.md`, `docs/deploy-digitalocean.md`, `docs/CODEBASE-CONTEXT.md` — (test: git diff --check pass; workflow YAML parse pass; composer validate/audit pass; npm audit/build pass; PHPUnit 590 pass; pytest 378 pass)
- 2026-06-09 — Upgrade framework Laravel 11.54 → 13.14 (laravel/framework ^13, laravel/tinker ^3, Symfony 7→8, polyfill php84/php86), naikkan minimum PHP root ke `^8.3`, dan hapus audit-ignore CVE-2026-48019 yang kini tertambal di L13. Bersihkan artefak lokal regenerable (.DS_Store, __pycache__, .pytest_cache, network.log, python-ai/{fastapi,server}.log, truncate laravel.log, .phpunit.result.cache). — `laravel/composer.json`, `laravel/composer.lock` — (test: php artisan test 590 pass; npm run build pass; composer audit clean; python-ai tidak tersentuh)
- 2026-06-09 — Mengubah workflow repo menjadi langsung commit/push ke `main` dan deploy tanpa folder `issue/` maupun PR flow, serta menyesuaikan README. — `AGENTS.md`, `README.md`, `docs/testing-guide.md`, `docs/workflow-review.md`, `issue/` — (test: n/a, dokumentasi; `git diff --check` pass)
- 2026-06-09 — Memperbaiki bug lintas Laravel-python-ai untuk export PDF link aman, sync status knowledge Chroma, stale job knowledge, gate import Google Drive/upload lokal, dan healthcheck readiness production. — `laravel/app/Services/Knowledge/KnowledgeLifecycleService.php`, `laravel/app/Jobs/ProcessKnowledgeDocument.php`, `laravel/app/Services/DocumentLifecycleService.php`, `laravel/app/Services/CloudStorage/GoogleDriveService.php`, `python-ai/app/routers/knowledge.py`, `python-ai/app/services/document_export.py`, `python-ai/app/services/rag_ingest.py`, `docker-compose.production.yml`, `docs/CODEBASE-CONTEXT.md` — (test: targeted Python 49 pass; targeted Laravel 59 pass; full PHPUnit 590 pass via `php -d memory_limit=-1 vendor/bin/phpunit`; full pytest 378 pass; `git diff --check` pass)
- 2026-06-09 — Menyelaraskan `AGENTS.md` agar konsisten dengan template `AGENTS.md` istura-app (struktur: Tentang repo, WAJIB dibaca dulu, Cara bekerja, Verifikasi wajib + Perintah cepat, Keamanan, Ekspektasi output, Done when, Changelog). — `AGENTS.md` — (test: n/a, dokumentasi)
- 2026-06-08 — Mengunci Google Drive OAuth pusat agar wajib active admin/super-admin selain setup key/allowlist email, dan mengetatkan trusted URL callback OnlyOffice ke scheme/host/port exact. — `laravel/app/Services/CloudStorage/GoogleDriveOAuthService.php`, `laravel/app/Http/Controllers/OnlyOfficeCallbackController.php`, `laravel/tests/Feature/CloudStorage/GoogleDriveCentralOAuthTest.php`, `laravel/tests/Feature/Memos/OnlyOfficeCallbackTest.php`, `docs/CODEBASE-CONTEXT.md`, `docs/production-config-guide.md`, `deploy/digitalocean.env.example` — (test: targeted PHPUnit 43 pass; full PHPUnit 584 pass via `php -d memory_limit=-1 vendor/bin/phpunit`; pytest 374 pass; composer audit 1 ignored mitigated; Vite build pass)
- 2026-06-08 — Hardening security audit: tutup public registration default production, kunci Horizon ke super-admin aktif, hilangkan `unsafe-eval` default production, mitigasi email CRLF CVE Laravel 11, update dependency Laravel/Symfony, dan test Chroma internal-only. — `laravel/`, `python-ai/tests/test_production_compose_security.py`, `docker-compose.production.yml`, `docs/production-config-guide.md` — (test: PHPUnit 580 pass; pytest 374 pass; composer audit 1 ignored mitigated; npm audit clean; pip-audit 1 Chroma no fixed version, mitigated internal-only)
- 2026-06-08 — Menambahkan `PRD-ISTA-AI.md` (PRD lengkap: overview, requirements, core features, user/admin flow, arsitektur, skema DB, constraints, acceptance criteria) untuk aplikasi testing eksternal. — `PRD-ISTA-AI.md` — (test: n/a, dokumentasi)
- 2026-06-08 — Menambahkan `docs/CODEBASE-CONTEXT.md` (peta Laravel + python-ai, fitur, flow user & admin) dan menambahkan bagian "WAJIB dibaca dulu (context)" + aturan changelog di `AGENTS.md`. — `AGENTS.md`, `docs/CODEBASE-CONTEXT.md` — (test: n/a, dokumentasi)
