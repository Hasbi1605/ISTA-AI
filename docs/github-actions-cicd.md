# GitHub Actions CI/CD

Repo ini memiliki workflow `.github/workflows/ci-cd.yml` untuk menjalankan CI dan deploy otomatis ke production ketika ada push ke branch `main`.

## Alur Workflow

1. `pull_request` ke `main` menjalankan test Laravel, build frontend, audit dependency, dan test Python.
2. `push` ke `main` menjalankan semua check yang sama.
3. Jika semua check lulus pada `push main`, job `Deploy production` SSH ke server, masuk ke repo production, `git pull --ff-only origin main`, rebuild Docker Compose, menjalankan migrasi, restart service runtime, lalu smoke check health internal.
4. `workflow_dispatch` bisa menjalankan CI manual. Deploy manual hanya berjalan jika input `deploy=true` dan branch yang dipilih adalah `main`.

## Secret GitHub yang Dibutuhkan

Tambahkan di GitHub repository settings: **Settings → Secrets and variables → Actions**.

| Secret | Wajib | Isi |
| --- | --- | --- |
| `ISTA_DEPLOY_HOST` | Ya | IP/hostname server production, misalnya droplet DigitalOcean. |
| `ISTA_DEPLOY_USER` | Ya | User SSH yang punya akses ke repo production dan Docker Compose. |
| `ISTA_DEPLOY_SSH_KEY` | Ya | Private key SSH khusus deploy. Jangan pakai key pribadi harian bila tidak perlu. |
| `ISTA_DEPLOY_PORT` | Tidak | Port SSH. Default workflow: `22`. |
| `ISTA_DEPLOY_PATH` | Tidak | Path repo production. Default workflow: `/opt/ista-ai`. |
| `ISTA_DEPLOY_URL` | Tidak | URL publik untuk smoke check `/up`, misalnya `https://ista-ai.app`. |
| `ISTA_DEPLOY_KNOWN_HOSTS` | Tidak | Isi `known_hosts` server. Jika kosong, workflow memakai `ssh-keyscan` saat runtime. Untuk production, lebih baik isi secret ini agar host key tidak berbasis trust-on-first-use. |

## Persiapan Server

Server harus sudah mengikuti panduan `docs/deploy-digitalocean.md`:

- repo ada sebagai git checkout di `ISTA_DEPLOY_PATH`;
- branch aktif bisa pindah ke `main`;
- file `.env.droplet` ada di root repo server dan tidak dicommit;
- user SSH bisa menjalankan `git` dan `docker compose`;
- Docker volume production seperti MySQL, Redis, Laravel storage, dan Chroma tetap dikelola oleh Compose.

Contoh setup key deploy di laptop/operator:

```bash
ssh-keygen -t ed25519 -C "ista-ai-github-actions-deploy" -f ~/.ssh/ista_ai_github_actions
ssh-copy-id -i ~/.ssh/ista_ai_github_actions.pub USER@SERVER_IP
```

Masukkan isi private key `~/.ssh/ista_ai_github_actions` ke `ISTA_DEPLOY_SSH_KEY`.

Untuk `ISTA_DEPLOY_KNOWN_HOSTS`:

```bash
ssh-keyscan -H SERVER_IP
```

## Perintah Deploy yang Dijalankan Workflow

Di server production, workflow menjalankan pola berikut:

```bash
cd /opt/ista-ai
git fetch origin main
git checkout main
git pull --ff-only origin main
docker compose --env-file .env.droplet -f docker-compose.production.yml config >/tmp/ista-ai-compose-production.yml
docker compose --env-file .env.droplet -f docker-compose.production.yml up -d --build --remove-orphans
docker compose --env-file .env.droplet -f docker-compose.production.yml exec -T laravel php artisan migrate --force
docker compose --env-file .env.droplet -f docker-compose.production.yml restart laravel horizon scheduler
```

Workflow sengaja memakai `git pull --ff-only`. Jika server punya perubahan manual atau branch divergen, deploy gagal agar operator bisa mengecek state production terlebih dahulu.

## Catatan Keamanan

- Jangan simpan `.env.droplet`, API key, OAuth secret, dump database, service-account JSON, atau data Chroma di GitHub secrets kecuali memang dibutuhkan workflow. Untuk workflow ini, secret aplikasi tetap berada di server.
- Gunakan deploy key khusus dengan akses minimum.
- Jika GitHub Environment `production` diberi required reviewers, deploy otomatis akan menunggu approval setelah CI lulus.
- `ISTA_DEPLOY_URL` hanya dipakai untuk request `HEAD /up`; jangan masukkan endpoint yang membuka data privat.
