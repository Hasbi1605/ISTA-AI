# Server and Domain Migration Notes

Dokumen ini menjelaskan hal yang perlu diperhatikan bila ISTA AI dipindahkan ke
server, hosting, atau domain milik Istana. Bentuknya sengaja dibuat sebagai
catatan migrasi, bukan daftar instruksi kaku, karena detail akhir biasanya
bergantung pada kebijakan infrastruktur yang dipakai.

## Gambaran Migrasi

ISTA AI relatif mudah dipindahkan selama source code, environment, database,
storage file, dan data Chroma diperlakukan sebagai satu paket. Source code dapat
diambil dari branch `main`, sedangkan konfigurasi production berada di
`.env.droplet` server dan tidak ikut disimpan di Git.

Data yang biasanya perlu dibawa dari server lama adalah database MySQL, Laravel
storage, dan Chroma data. Jika indeks dokumen tidak perlu dipertahankan, Chroma
dapat dibangun ulang melalui ingest ulang dokumen, tetapi keputusan itu perlu
disesuaikan dengan waktu proses dan ukuran dokumen.

## Domain dan TLS

Domain final memengaruhi `APP_DOMAIN`, `APP_URL`, konfigurasi Caddy, cookie
session, dan URL publik OnlyOffice. Pada deployment Docker Compose saat ini,
Caddy menangani reverse proxy publik dan TLS otomatis. Jika domain memakai
Cloudflare atau proxy lain, mode DNS/TLS perlu disesuaikan agar request publik
tetap sampai ke Caddy dengan header proxy yang benar.

Nilai yang biasanya ikut berubah saat domain pindah:

- `APP_DOMAIN`
- `APP_URL`
- `LETSENCRYPT_EMAIL`
- `SESSION_DOMAIN` bila cookie dibatasi per domain
- `SESSION_SECURE_COOKIE` untuk HTTPS production
- `ONLYOFFICE_PUBLIC_URL`
- `ISTA_DEPLOY_URL` bila GitHub Actions tetap dipakai

## Environment Laravel dan Python

Laravel dan Python berkomunikasi lewat URL internal Docker network. Dalam model
Compose yang sama, nilai internal biasanya tetap seperti ini:

```text
AI_SERVICE_URL=http://python-ai:8001
AI_DOCUMENT_SERVICE_URL=http://python-ai-docs:8002
ONLYOFFICE_INTERNAL_URL=http://onlyoffice
ONLYOFFICE_LARAVEL_INTERNAL_URL=http://laravel:8000
```

`AI_SERVICE_TOKEN` perlu sama antara Laravel dan Python. Secret provider AI,
LangSearch, OnlyOffice, database, dan email tetap berada di `.env.droplet` server
baru. Jika IP atau environment provider berubah, beberapa provider mungkin perlu
allowlist atau pengaturan ulang dari dashboard masing-masing.

## OnlyOffice

OnlyOffice sensitif terhadap URL publik dan internal. URL publik dipakai browser,
sedangkan URL internal dipakai komunikasi antar-container. Jika domain berubah,
bagian yang paling sering perlu dicek adalah `ONLYOFFICE_PUBLIC_URL`, JWT secret,
signed URL secret, dan kemampuan OnlyOffice memanggil balik Laravel.

Cara paling sederhana membuktikan bagian ini sehat adalah membuka memo DOCX dari
aplikasi, membuat perubahan kecil, lalu memastikan force-save menghasilkan versi
baru.

## GitHub Actions dan Deploy Otomatis

Jika server baru tetap memakai deploy otomatis dari GitHub Actions, secret
deploy di repository perlu menunjuk ke server baru. Secret yang terkait deploy
adalah:

- `ISTA_DEPLOY_HOST`
- `ISTA_DEPLOY_USER`
- `ISTA_DEPLOY_SSH_KEY`
- `ISTA_DEPLOY_PORT`
- `ISTA_DEPLOY_PATH`
- `ISTA_DEPLOY_URL`
- `ISTA_DEPLOY_KNOWN_HOSTS`

Workflow production sengaja memakai `git pull --ff-only`. Artinya deploy akan
berhenti bila checkout di server punya perubahan manual atau history divergen.
Perilaku itu membantu menjaga production tetap bisa ditelusuri dari Git.

## Urutan Restore yang Aman

Secara umum, migrasi paling aman dilakukan dengan menyiapkan server baru,
mengisi `.env.droplet`, lalu merestore database, Laravel storage, dan Chroma
sebelum service dibuka untuk pengguna. Setelah container berjalan, migration
Laravel dijalankan dari service `laravel`.

Contoh pola production saat ini:

```bash
docker compose --env-file .env.droplet -f docker-compose.production.yml up -d --build
docker compose --env-file .env.droplet -f docker-compose.production.yml exec -T laravel php artisan migrate --force
docker compose --env-file .env.droplet -f docker-compose.production.yml restart laravel horizon scheduler
```

## Pembuktian Setelah Migrasi

Pembuktian migrasi cukup difokuskan pada jalur yang mewakili semua komponen
besar: halaman `/up`, login, chat pendek, upload dokumen kecil sampai ready,
tanya jawab berbasis dokumen, generate memo dan buka di OnlyOffice, Prompy
Studio generate prompt sederhana, serta admin login dengan 2FA. Jika CI/CD tetap
dipakai, satu push kecil berikutnya juga dapat dipakai untuk memastikan deploy
otomatis sudah mengarah ke server baru.

## Rollback

Server lama sebaiknya tidak langsung dimatikan sebelum domain, data, dan fitur
utama terbukti berjalan di server baru. Jika perlu rollback cepat, DNS bisa
dikembalikan ke server lama selama data belum berubah jauh. Untuk migrasi yang
melibatkan data aktif, rollback perlu mempertimbangkan perubahan database dan
storage yang sudah terjadi di server baru.
