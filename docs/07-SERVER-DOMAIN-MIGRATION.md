# Server and Domain Migration Checklist

Checklist ini dipakai bila ISTA AI dipindah ke server/hosting atau domain milik
Istana.

## Sebelum Migrasi

- [ ] Tentukan domain final.
- [ ] Tentukan server final dan akses SSH operator.
- [ ] Tentukan apakah akan memakai GitHub Actions deploy atau deploy manual.
- [ ] Backup `.env.droplet` production lama.
- [ ] Backup MySQL.
- [ ] Backup Laravel storage.
- [ ] Backup Chroma data bila indeks dokumen ingin dipertahankan.
- [ ] Catat versi commit production yang sedang berjalan.

## DNS dan TLS

- [ ] Arahkan A/AAAA/CNAME domain final ke server baru.
- [ ] Jika memakai Cloudflare, mulai dari DNS-only sampai TLS origin sehat.
- [ ] Set `APP_DOMAIN` di `.env.droplet`.
- [ ] Set `APP_URL=https://DOMAIN_FINAL`.
- [ ] Set `LETSENCRYPT_EMAIL`.
- [ ] Jalankan Caddy/Compose dan pastikan sertifikat TLS terbit.

## Laravel Env yang Perlu Dicek

- [ ] `APP_URL`
- [ ] `APP_DOMAIN`
- [ ] `SESSION_DOMAIN` bila domain cookie perlu dibatasi.
- [ ] `SESSION_SECURE_COOKIE=true` untuk HTTPS production.
- [ ] `TRUSTED_PROXIES`
- [ ] `PUBLIC_REGISTRATION_ENABLED=false` untuk deployment private.
- [ ] `FEATURE_PROMPY=true` bila Prompy tetap aktif.
- [ ] `AI_SERVICE_URL` dan `AI_DOCUMENT_SERVICE_URL` mengarah ke service internal.
- [ ] `AI_SERVICE_TOKEN` sama dengan Python.

## OnlyOffice

- [ ] `ONLYOFFICE_PUBLIC_URL=https://DOMAIN_FINAL` atau host publik OnlyOffice yang dipakai.
- [ ] `ONLYOFFICE_INTERNAL_URL=http://onlyoffice` bila tetap satu Compose network.
- [ ] `ONLYOFFICE_LARAVEL_INTERNAL_URL=http://laravel:8000` bila tetap satu Compose network.
- [ ] `ONLYOFFICE_JWT_SECRET` tetap sama jika membuka dokumen lama yang masih aktif.
- [ ] `ONLYOFFICE_SIGNED_URL_SECRET` disimpan aman.
- [ ] Smoke test buka memo DOCX dan force-save.

## GitHub Actions

Jika deploy otomatis tetap dipakai, update repository secrets:

- [ ] `ISTA_DEPLOY_HOST`
- [ ] `ISTA_DEPLOY_USER`
- [ ] `ISTA_DEPLOY_SSH_KEY`
- [ ] `ISTA_DEPLOY_PORT`
- [ ] `ISTA_DEPLOY_PATH`
- [ ] `ISTA_DEPLOY_URL`
- [ ] `ISTA_DEPLOY_KNOWN_HOSTS`

Pastikan deploy key sudah dipasang di server baru dan user SSH bisa menjalankan
`git` serta `docker compose`.

## Provider dan Email

- [ ] Provider AI/search key masih valid di environment baru.
- [ ] SMTP/Resend/MAIL config masih bisa mengirim email verification/reset.
- [ ] Jika IP/server berubah, cek allowlist provider bila ada.

## Data Restore

Urutan aman:

1. Deploy source code di server baru.
2. Isi `.env.droplet`.
3. Restore database.
4. Restore Laravel storage.
5. Restore Chroma data bila dipakai.
6. Jalankan Compose.
7. Jalankan migration.
8. Smoke test fitur utama.

## Smoke Test Setelah Migrasi

- [ ] `curl -I https://DOMAIN_FINAL/up`.
- [ ] Login user.
- [ ] Chat umum.
- [ ] Upload dokumen kecil dan tanya dari dokumen.
- [ ] Generate memo dan buka di OnlyOffice.
- [ ] Prompy Studio generate prompt sederhana.
- [ ] Admin login, 2FA, dashboard, usage, knowledge.
- [ ] GitHub Actions deploy berikutnya berhasil bila CI/CD dipakai.

## Rollback

- Simpan server lama sampai smoke test server baru selesai.
- Simpan backup database/storage sebelum migration di server baru.
- Jika deploy otomatis gagal, kembalikan `ISTA_DEPLOY_*` ke server lama atau jalankan deploy manual.
- Jika domain sudah diarahkan dan perlu rollback cepat, turunkan TTL DNS sebelum migrasi.
