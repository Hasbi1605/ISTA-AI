# Operations Runbook

Runbook ini untuk operator yang menjaga production ISTA AI.

## Cek Status Service

```bash
cd /opt/ista-ai
docker compose --env-file .env.droplet -f docker-compose.production.yml ps
docker compose --env-file .env.droplet -f docker-compose.production.yml top
```

Service penting:

- `laravel`
- `python-ai`
- `python-ai-docs`
- `mysql`
- `redis`
- `onlyoffice`
- `horizon`
- `scheduler`
- `caddy`

## Health Check

Publik:

```bash
curl -I https://DOMAIN/up
```

Internal dari Compose network:

```bash
docker compose --env-file .env.droplet -f docker-compose.production.yml exec -T python-ai curl -fsS http://127.0.0.1:8001/api/ready
docker compose --env-file .env.droplet -f docker-compose.production.yml exec -T python-ai-docs curl -fsS http://127.0.0.1:8002/api/ready
docker compose --env-file .env.droplet -f docker-compose.production.yml exec -T laravel php artisan horizon:status
```

## Log

```bash
docker compose --env-file .env.droplet -f docker-compose.production.yml logs --tail=200 laravel
docker compose --env-file .env.droplet -f docker-compose.production.yml logs --tail=200 python-ai
docker compose --env-file .env.droplet -f docker-compose.production.yml logs --tail=200 python-ai-docs
docker compose --env-file .env.droplet -f docker-compose.production.yml logs --tail=200 horizon
docker compose --env-file .env.droplet -f docker-compose.production.yml logs --tail=200 onlyoffice
docker compose --env-file .env.droplet -f docker-compose.production.yml logs --tail=200 caddy
```

## Deploy Manual

```bash
cd /opt/ista-ai
git fetch origin main
git pull --ff-only origin main
docker compose --env-file .env.droplet -f docker-compose.production.yml up -d --build --remove-orphans
docker compose --env-file .env.droplet -f docker-compose.production.yml exec -T laravel php artisan migrate --force
docker compose --env-file .env.droplet -f docker-compose.production.yml restart laravel horizon scheduler
```

## Backup Minimum

Database:

```bash
cd /opt/ista-ai
docker compose --env-file .env.droplet -f docker-compose.production.yml exec mysql \
  sh -lc 'mysqldump -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' > ista-ai-mysql-$(date +%F).sql
```

Env:

```bash
cp .env.droplet .env.droplet.bak.$(date +%Y%m%d%H%M%S)
chmod 600 .env.droplet .env.droplet.bak.*
```

Storage dan Chroma:

- Gunakan snapshot server/volume bila tersedia.
- `docker volume prune` baru layak dipertimbangkan setelah backup dan review volume.

## Troubleshooting Cepat

### Chat tidak menjawab

1. Cek `python-ai` ready.
2. Cek `AI_SERVICE_TOKEN` Laravel/Python sama.
3. Cek log `laravel` dan `python-ai`.
4. Cek provider key dan quota provider.

### Upload dokumen stuck

1. Cek `horizon:status`.
2. Cek log `horizon` dan `python-ai-docs`.
3. Cek ukuran/format file.
4. Cek disk dan RAM server.

### OnlyOffice tidak terbuka

1. Cek `ONLYOFFICE_PUBLIC_URL`.
2. Cek `ONLYOFFICE_INTERNAL_URL`.
3. Cek `ONLYOFFICE_LARAVEL_INTERNAL_URL`.
4. Cek `ONLYOFFICE_JWT_SECRET`.
5. Cek log `onlyoffice` dan `laravel`.

### Admin terkunci 2FA

- Gunakan recovery code bila tersedia.
- Jika kehilangan 2FA/recovery code, pemulihan membutuhkan prosedur super-admin atau perubahan database yang diaudit secara manual. Perubahan manual seperti itu perlu persetujuan pemilik sistem.

## Cleanup Aman

Lihat juga `docs/production-maintenance.md`.

Relatif aman setelah review:

```bash
docker builder prune
docker image prune
docker container prune
```

Perintah berikut berisiko jika belum ada backup:

```bash
docker volume prune
```
