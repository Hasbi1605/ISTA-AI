# Handover ISTA AI

Dokumen ini adalah ringkasan awal untuk mentor atau teknisi yang akan menilai,
melanjutkan, atau memindahkan sistem ISTA AI.

## Ringkasan Singkat

ISTA AI adalah asisten AI private-document untuk pegawai Istana Kepresidenan
Yogyakarta. Sistem berjalan sebagai monorepo hybrid:

- `laravel/` untuk UI, auth, upload, metadata dokumen, memo, admin, queue, dan callback OnlyOffice.
- `python-ai/` untuk chat streaming, RAG, embedding, summarization, export dokumen, memo generation, dan Prompy Studio.

Data aplikasi tersimpan di MySQL, queue/cache di Redis, vector store di ChromaDB,
dan editing DOCX memakai OnlyOffice Document Server. Provider AI/search eksternal
diatur lewat env secret dan `python-ai/config/ai_config.yaml`.

## Status Sistem Saat Ini

- Akses production bersifat terbatas/private.
- Registrasi mandiri production default tertutup (`PUBLIC_REGISTRATION_ENABLED=false`).
- Chat memakai SSE (`EventSource`), bukan WebSocket.
- Google Drive import/export sudah dihapus.
- Generator PPTX internal sudah dihapus. Fitur deck/visual dipusatkan ke Prompy Studio sebagai generator paket prompt.
- Admin wajib melewati login terpisah, forced password change bila diset, 2FA TOTP, dan absolute session lifetime.

## Fitur yang Bisa Didemokan

- Login user dan email verification.
- Chat umum dengan streaming jawaban.
- Upload dokumen PDF/DOCX/XLSX/CSV, tunggu status ready, lalu tanya jawab berbasis dokumen.
- Generate memo DOCX, buka di OnlyOffice, force-save, download/export.
- Prompy Studio: buat prompt gambar/presentasi/poster/video storyboard, tambah reference image atau dokumen acuan, revisi lewat composer.
- Admin dashboard: usage, errors, documents, users, knowledge, dan accounts untuk super-admin.

## Hal yang Harus Diputuskan Pemilik Sistem

- Klasifikasi dokumen apa saja yang boleh diproses oleh provider AI eksternal.
- Provider AI/search mana yang disetujui untuk production formal.
- Retensi chat, dokumen, memo, dan vector Chroma.
- Mekanisme backup yang disetujui: snapshot server, dump MySQL, backup Laravel storage, dan backup Chroma.
- Domain final dan server/hosting final milik Istana.
- Siapa yang menjadi super-admin awal dan bagaimana rotasi akses dilakukan.

## Checklist Handover

- [ ] Mentor membaca `README.md` dan dokumen ini.
- [ ] Developer/operator membaca `docs/04-ARCHITECTURE.md`.
- [ ] Operator membaca `docs/05-ENVIRONMENT.md`, `docs/06-DEPLOYMENT.md`, dan `docs/08-OPERATIONS-RUNBOOK.md`.
- [ ] Pemilik data membaca `docs/09-SECURITY-PRIVACY.md` dan `docs/data-flow-privacy.md`.
- [ ] `.env.droplet` production disimpan aman di luar repo.
- [ ] Secret deploy GitHub Actions sudah dipetakan di `docs/github-actions-cicd.md`.
- [ ] Backup production minimal sudah diuji restore.
- [ ] Domain final sudah dipetakan dengan checklist `docs/07-SERVER-DOMAIN-MIGRATION.md`.

## Batasan Penting

- Repo tidak menyimpan data produksi.
- Sistem belum menjadi pengganti kebijakan arsip atau klasifikasi dokumen resmi.
- Jawaban AI harus tetap diverifikasi manusia, terutama untuk dokumen dinas.
- Prompy Studio membuat teks prompt, bukan langsung membuat gambar, video, atau deck di platform eksternal.
