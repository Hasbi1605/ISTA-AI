# Production Config Guide

Dokumen ini menjelaskan pembagian konfigurasi production ISTA AI agar operator tahu mana yang diubah di `.env.droplet` dan mana yang diubah di `python-ai/config/ai_config.yaml`.

## Sumber Konfigurasi

### `.env.droplet`

File ini berada di root repo pada server production dan tidak boleh dicommit. Isinya untuk secret dan nilai runtime deployment:

- `APP_URL`, `APP_DOMAIN`, `LETSENCRYPT_EMAIL`
- `APP_KEY`
- kredensial MySQL dan Redis
- `AI_SERVICE_TOKEN`
- API key provider AI: `GITHUB_TOKEN`, `GITHUB_TOKEN_2`, `GROQ_API_KEY`, `GEMINI_API_KEY`, `LANGSEARCH_API_KEY`
- URL internal Laravel ke Python
- konfigurasi OnlyOffice seperti `ONLYOFFICE_JWT_SECRET`, `ONLYOFFICE_SIGNED_URL_TTL_MINUTES`, dan `ONLYOFFICE_DOCUMENTSERVER_TAG`

Nilai secret wajib diganti dari contoh. Khusus `AI_SERVICE_TOKEN`, jangan gunakan placeholder `CHANGE_ME`, `change_me_internal_api_secret`, atau nilai default lama `your_internal_api_secret`; Python AI akan menolak token kosong/default/placeholder.

### `python-ai/config/ai_config.yaml`

File ini dicommit dan menjadi konfigurasi non-secret untuk AI:

- urutan model chat dan fallback
- model embedding
- endpoint provider non-secret
- prompt system, RAG, web search, summarization, dan memo
- pengaturan retrieval, rerank, hybrid search, HyDE, dan chunking

Mengubah prompt/model di file ini membutuhkan commit dan deploy ulang container Python.

## GitHub Models Endpoint

Endpoint aktif untuk GitHub Models adalah:

```text
https://models.github.ai/inference
```

Jangan gunakan lagi endpoint lama:

```text
https://models.inference.ai.azure.com
```

Endpoint lama sudah deprecated oleh GitHub dan harus dianggap tidak aman untuk konfigurasi baru.

## OnlyOffice

Production tidak memakai tag `latest`. Default image dipin lewat compose:

```text
onlyoffice/documentserver:${ONLYOFFICE_DOCUMENTSERVER_TAG:-9.3.1.2}
```

Untuk upgrade OnlyOffice:

1. Cek tag resmi `onlyoffice/documentserver`.
2. Ubah `ONLYOFFICE_DOCUMENTSERVER_TAG` di `.env.droplet`.
3. Jalankan smoke test editor memo setelah deploy.
4. Pastikan route `/web-apps`, `/sdkjs`, `/fonts`, dan callback tetap sehat.

Signed URL file memo dikendalikan oleh:

```text
ONLYOFFICE_SIGNED_URL_SECRET=isi_dengan_secret_acak_berbeda_dari_app_key
ONLYOFFICE_SIGNED_URL_TTL_MINUTES=30
```

Gunakan `ONLYOFFICE_SIGNED_URL_SECRET` yang berbeda dari `APP_KEY` dan `ONLYOFFICE_JWT_SECRET` untuk key separation. Nilai TTL 15-30 menit direkomendasikan untuk production. Jangan naikkan ke hitungan jam kecuali ada alasan operasional yang jelas.

## Runtime Decision

Untuk deployment production saat ini, container Laravel tetap menjalankan `php artisan serve` di port internal 8000.

Alasannya:

- Caddy sudah menjadi reverse proxy publik dan terminasi TLS.
- Service Laravel hanya perlu menyediakan HTTP internal untuk Caddy, healthcheck `/up`, dan callback internal OnlyOffice.
- Proses kerja berat tetap dipisah: `horizon` menangani queue dan `scheduler` menangani cron.
- Storage persisten tetap berada di volume `laravel_storage`, jadi perubahan runtime web server tidak mengubah model data.

Keputusan ini sengaja dipertahankan untuk PR cleanup ini agar scope tidak melebar ke migrasi web server. Jika nanti ingin pindah ke `php-fpm` + web server terpisah, itu layak diperlakukan sebagai perubahan deployment tersendiri.

## Command Compose Production

Gunakan `--env-file .env.droplet` agar variable interpolation seperti `ONLYOFFICE_JWT_SECRET` dan `ONLYOFFICE_DOCUMENTSERVER_TAG` terbaca konsisten:

```bash
docker compose --env-file .env.droplet -f docker-compose.production.yml ps
docker compose --env-file .env.droplet -f docker-compose.production.yml up -d --build
docker compose --env-file .env.droplet -f docker-compose.production.yml exec -T laravel php artisan migrate --force
```

Jalankan migration dari service `laravel` yang aktif karena service profile `artisan` tidak selalu ikut rebuild saat `up -d --build`.

## Checklist Sebelum Deploy Config

- `.env.droplet` ada di server dan tidak masuk git.
- `APP_DEBUG=false`.
- `APP_URL` memakai HTTPS domain production.
- `AI_SERVICE_TOKEN` sama antara Laravel dan Python, bukan default.
- `ONLYOFFICE_JWT_SECRET` terisi dan tidak sama dengan token lain.
- `ONLYOFFICE_DOCUMENTSERVER_TAG` bukan `latest`.
- `python-ai/config/ai_config.yaml` tidak memuat secret.
- Tidak ada endpoint deprecated `models.inference.ai.azure.com`.

## Validasi Cepat

```bash
docker compose --env-file .env.droplet -f docker-compose.production.yml config >/tmp/ista-compose.yaml
docker compose --env-file .env.droplet -f docker-compose.production.yml ps
curl -I https://ista-ai.app/up
```

Setelah perubahan AI provider, uji minimal:

- chat pendek tanpa dokumen
- upload dokumen kecil
- chat dengan dokumen
- generate memo sederhana
- buka memo di OnlyOffice
