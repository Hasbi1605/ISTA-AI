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
- `PUBLIC_REGISTRATION_ENABLED` untuk membuka/menutup registrasi mandiri
- `FEATURE_PROMPY` untuk menampilkan/rollback tab Prompy Studio dan tombol dashboard
- `SECURITY_CSP_ALLOW_UNSAFE_EVAL` untuk override global CSP `unsafe-eval` bila benar-benar diperlukan
- `SECURITY_CSP_ALLOW_LIVEWIRE_UNSAFE_EVAL` untuk compatibility halaman Livewire/Alpine tanpa membuka eval pada response plain HTML
- konfigurasi OnlyOffice seperti `ONLYOFFICE_JWT_SECRET`, `ONLYOFFICE_SIGNED_URL_TTL_MINUTES`, dan `ONLYOFFICE_DOCUMENTSERVER_TAG`

Nilai secret wajib diganti dari contoh. Khusus `AI_SERVICE_TOKEN`, jangan gunakan placeholder `CHANGE_ME`, `change_me_internal_api_secret`, atau nilai default lama `your_internal_api_secret`; Python AI akan menolak token kosong/default/placeholder.

Production default menutup registrasi mandiri:

```text
PUBLIC_REGISTRATION_ENABLED=false
```

Aktifkan hanya jika deployment memang menerima pendaftaran publik. Untuk deployment private-document internal, akun user dibuat/dikelola oleh admin.

Fitur Prompy Studio sudah aktif default:

```text
FEATURE_PROMPY=true
```

Set `FEATURE_PROMPY=false` hanya sebagai rollback sementara bila tab Prompy perlu disembunyikan lagi tanpa revert kode. Kode masih membaca `FEATURE_PRESENTATION` sebagai fallback legacy bila env baru belum tersedia.

Production default tidak mengizinkan `unsafe-eval` secara global:

```text
SECURITY_CSP_ALLOW_UNSAFE_EVAL=false
SECURITY_CSP_ALLOW_LIVEWIRE_UNSAFE_EVAL=true
```

`SECURITY_CSP_ALLOW_LIVEWIRE_UNSAFE_EVAL=true` tetap diperlukan selama UI utama memakai Livewire/Alpine standar. Middleware CSP hanya menambahkan `'unsafe-eval'` pada response HTML yang memuat marker Livewire/Alpine (`wire:*`/`x-*`), sehingga response plain HTML atau non-HTML tetap tidak mendapat eval. Gunakan `SECURITY_CSP_ALLOW_UNSAFE_EVAL=true` hanya sebagai rollback global sementara jika ada library frontend legacy lain yang terbukti membutuhkan eval.

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

## ChromaDB

Compose production memakai Chroma secara embedded melalui volume `chroma_data` di service Python. Tidak ada port Chroma yang dipublish ke host atau internet.

Jangan menambahkan service HTTP Chroma publik tanpa autentikasi, firewall, dan review security terpisah. Jika advisory `chromadb` belum menyediakan fixed version, mitigasi aktifnya adalah memastikan Chroma tetap internal-only dan memantau update dependency sebelum upgrade.

## Runtime Decision

Untuk deployment production saat ini, container Laravel tetap menjalankan `php artisan serve` di port internal 8000.

Alasannya:

- Caddy sudah menjadi reverse proxy publik dan terminasi TLS.
- Service Laravel hanya perlu menyediakan HTTP internal untuk Caddy, healthcheck `/up`, dan callback internal OnlyOffice.
- Proses kerja berat tetap dipisah: `horizon` menangani queue dan `scheduler` menangani cron.
- Storage persisten tetap berada di volume `laravel_storage`, jadi perubahan runtime web server tidak mengubah model data.

Keputusan ini sengaja dipertahankan untuk PR cleanup ini agar scope tidak melebar ke migrasi web server. Jika nanti ingin pindah ke `php-fpm` + web server terpisah, itu layak diperlakukan sebagai perubahan deployment tersendiri.

## Horizon Worker Compatibility

Production memakai Laravel 13 dengan Horizon 5.45. Horizon 5.45 belum mendeklarasikan opsi internal `horizon:work --stop-when-empty-for` yang dipakai worker Laravel 13, sehingga tanpa compatibility command worker dapat crash-loop dengan pesan `The "stop-when-empty-for" option does not exist.`.

App menyediakan `App\Console\Commands\CompatibleHorizonWorkCommand` dan binding di `App\Providers\HorizonServiceProvider` agar `php artisan horizon` tetap dipakai, tetapi child worker `horizon:work` menerima opsi Laravel 13 tersebut. Jika suatu saat Horizon upstream sudah memperbaiki signature ini, compatibility command bisa dievaluasi ulang setelah `php artisan horizon:work --help` di container production tetap menampilkan `--stop-when-empty-for`.

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
- `PUBLIC_REGISTRATION_ENABLED=false` untuk deployment private-document internal.
- `SECURITY_CSP_ALLOW_UNSAFE_EVAL=false` kecuali ada rollback global sementara yang terdokumentasi.
- `SECURITY_CSP_ALLOW_LIVEWIRE_UNSAFE_EVAL=true` selama UI masih memakai Livewire/Alpine standar.
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
