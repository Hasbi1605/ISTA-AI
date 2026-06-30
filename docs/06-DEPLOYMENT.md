# Deployment

Dokumen ini merangkum deployment ISTA AI. Detail lama yang lebih panjang tetap
ada di `docs/deploy-digitalocean.md`, `docs/production-config-guide.md`, dan
`docs/github-actions-cicd.md`.

## Model Production Saat Ini

Production memakai Docker Compose single-server:

- `laravel` - web app di port internal 8000;
- `python-ai` - chat service FastAPI di 8001;
- `python-ai-docs` - document service FastAPI di 8002;
- `mysql`;
- `redis`;
- `onlyoffice`;
- `horizon`;
- `scheduler`;
- `caddy` sebagai reverse proxy publik dan TLS.

ChromaDB dipakai embedded oleh Python melalui volume data, bukan service publik.

## Setup Server Baru

Ringkasan:

```bash
cd /opt
git clone https://github.com/Hasbi1605/ISTA-AI.git /opt/ista-ai
cd /opt/ista-ai
cp deploy/digitalocean.env.example .env.droplet
```

Isi `.env.droplet`, lalu jalankan:

```bash
docker compose --env-file .env.droplet -f docker-compose.production.yml up -d --build
docker compose --env-file .env.droplet -f docker-compose.production.yml exec -T laravel php artisan migrate --force
docker compose --env-file .env.droplet -f docker-compose.production.yml restart laravel horizon scheduler
```

Pembuktian paling sederhana setelah service naik adalah endpoint `/up`:

```bash
curl -I https://DOMAIN/up
```

## GitHub Actions CI/CD

Workflow `.github/workflows/ci-cd.yml` menjalankan:

1. Composer validate/install, composer audit, npm audit, `npm run build`, PHPUnit.
2. Python dependency install, `pip check`, pytest.
3. Pada `push main`, deploy production via SSH bila secret tersedia.

Secret GitHub yang terkait deploy:

- `ISTA_DEPLOY_HOST`
- `ISTA_DEPLOY_USER`
- `ISTA_DEPLOY_SSH_KEY`
- `ISTA_DEPLOY_PORT` (opsional)
- `ISTA_DEPLOY_PATH` (opsional, default `/opt/ista-ai`)
- `ISTA_DEPLOY_URL` (opsional smoke check publik)
- `ISTA_DEPLOY_KNOWN_HOSTS` (opsional tetapi direkomendasikan)

## Perintah Update Production Manual

```bash
cd /opt/ista-ai
git fetch origin main
git checkout main
git pull --ff-only origin main
docker compose --env-file .env.droplet -f docker-compose.production.yml up -d --build --remove-orphans
docker compose --env-file .env.droplet -f docker-compose.production.yml exec -T laravel php artisan migrate --force
docker compose --env-file .env.droplet -f docker-compose.production.yml restart laravel horizon scheduler
docker compose --env-file .env.droplet -f docker-compose.production.yml exec -T laravel php artisan horizon:status
```

## Pembuktian Setelah Deploy

Setelah deploy, pembuktian difokuskan pada service utama yang saling terhubung.
`docker compose ... ps` menunjukkan container berjalan, `/up` membuktikan Laravel
publik sehat, sedangkan `/api/ready` pada `python-ai` dan `python-ai-docs`
membuktikan service internal siap menerima request. Setelah itu cukup uji jalur
produk yang mewakili stack penuh: login, chat pendek, upload satu dokumen kecil
sampai ready, generate memo dan buka OnlyOffice, lalu admin login dengan 2FA.

## Catatan

- `git pull --ff-only` sengaja dipakai agar deploy gagal bila server punya perubahan manual.
- Perubahan file tracked langsung di server production sebaiknya hanya terjadi saat emergency dan tetap dicatat.
- `.env.droplet` tetap berada di server, bukan di GitHub secret aplikasi.
