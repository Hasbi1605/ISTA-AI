# Environment Guide

Dokumen ini menjelaskan environment variable penting. File env asli tidak boleh
dicommit.

## Template yang Ada

- `.env.droplet.example` - daftar key production dengan placeholder.
- `deploy/digitalocean.env.example` - contoh production single droplet.
- `laravel/.env.example` - local development Laravel.
- `python-ai/.env.example` - local development Python AI.

## Laravel/App

| Variable | Fungsi |
| --- | --- |
| `APP_NAME` | Nama aplikasi |
| `APP_ENV` | `local`, `production`, dll |
| `APP_KEY` | Kunci Laravel yang dibuat unik per environment |
| `APP_DEBUG` | Mode debug; production memakai `false` |
| `APP_URL` | URL publik aplikasi |
| `APP_DOMAIN` | Domain yang dipakai Caddy/production |
| `LETSENCRYPT_EMAIL` | Email untuk TLS Caddy/Let's Encrypt |
| `TRUSTED_PROXIES` | Proxy internal yang dipercaya Laravel |

## Database, Queue, Cache

| Variable | Fungsi |
| --- | --- |
| `DB_*`, `MYSQL_*` | Koneksi dan bootstrap MySQL |
| `REDIS_*` | Redis queue/cache |
| `QUEUE_CONNECTION` | Production memakai Redis |
| `CACHE_STORE` | Store cache Laravel |
| `REDIS_QUEUE_RETRY_AFTER`, `DB_QUEUE_RETRY_AFTER` | Durasi retry queue untuk job dokumen besar |

## Auth dan Admin

| Variable | Fungsi |
| --- | --- |
| `PUBLIC_REGISTRATION_ENABLED` | Buka/tutup registrasi mandiri |
| `ADMIN_SESSION_ABSOLUTE_LIFETIME` | Batas absolut sesi admin, menit |
| `INITIAL_ADMIN_EMAIL/PASSWORD` | Bootstrap admin awal bila command dijalankan |
| `INITIAL_SUPER_ADMIN_EMAIL/PASSWORD` | Bootstrap super-admin awal bila command dijalankan |

## AI Internal

| Variable | Fungsi |
| --- | --- |
| `AI_SERVICE_URL` | URL internal Python chat service |
| `AI_DOCUMENT_SERVICE_URL` | URL internal Python document service |
| `AI_SERVICE_TOKEN` | Bearer token bersama Laravel dan Python |
| `AI_CONFIG_DB_ENABLED` | Saat ini default `false`; AI config dari YAML |

`AI_SERVICE_TOKEN` tidak boleh memakai placeholder seperti `CHANGE_ME`,
`change_me_internal_api_secret`, atau nilai default lama.

## Provider AI dan Search

| Variable | Fungsi |
| --- | --- |
| `GITHUB_TOKEN`, `GITHUB_TOKEN_2` | GitHub Models utama/backup |
| `GROQ_API_KEY` | Fallback Groq |
| `GEMINI_API_KEY` | Fallback Gemini |
| `LANGSEARCH_API_KEY`, `LANGSEARCH_API_KEY_BACKUP` | Web search dan rerank |
| `AWS_BEARER_TOKEN_BEDROCK`, `AWS_BEDROCK_REGION` | Bedrock bila dipakai |

Urutan model dan prompt berada di `python-ai/config/ai_config.yaml`.

## OnlyOffice

| Variable | Fungsi |
| --- | --- |
| `ONLYOFFICE_PUBLIC_URL` | URL publik editor dokumen |
| `ONLYOFFICE_INTERNAL_URL` | URL internal container OnlyOffice |
| `ONLYOFFICE_LARAVEL_INTERNAL_URL` | URL internal Laravel dari OnlyOffice |
| `ONLYOFFICE_JWT_SECRET` | Secret JWT editor/callback |
| `ONLYOFFICE_SIGNED_URL_SECRET` | Secret signed URL file memo |
| `ONLYOFFICE_SIGNED_URL_TTL_MINUTES` | TTL signed URL memo |
| `ONLYOFFICE_DOCUMENTSERVER_TAG` | Versi image OnlyOffice; production memakai tag spesifik, bukan `latest` |

Gunakan secret berbeda untuk `APP_KEY`, `AI_SERVICE_TOKEN`,
`ONLYOFFICE_JWT_SECRET`, dan `ONLYOFFICE_SIGNED_URL_SECRET`.

## Feature Flag

| Variable | Fungsi |
| --- | --- |
| `FEATURE_PROMPY` | Tampilkan/sembunyikan tab Prompy Studio |

`FEATURE_PRESENTATION` hanya fallback legacy untuk env lama.

## Catatan Production

Production memakai `APP_DEBUG=false`, `APP_URL` HTTPS dengan domain final, dan
`PUBLIC_REGISTRATION_ENABLED=false` untuk deployment private. `AI_SERVICE_TOKEN`
perlu sama di Laravel dan Python, sementara secret provider AI/search serta
OnlyOffice disimpan di `.env.droplet` server. File `.env.droplet` tidak masuk Git
dan aksesnya sebaiknya dibatasi ke operator yang memang menangani deployment.
