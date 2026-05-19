# Checklist Deploy DigitalOcean untuk `ista-ai.app`

Checklist ini memakai file lokal `.env.droplet` yang sudah disiapkan di repo root.

## Sebelum Mulai

- pastikan droplet Ubuntu 24.04 sudah dibuat
- pastikan domain `ista-ai.app` dan `www.ista-ai.app` diarahkan ke IP droplet
- pastikan port `80` dan `443` dibuka di firewall droplet
- pastikan Anda akan deploy dari branch `main`

## 1. Masuk ke Droplet

```bash
ssh root@YOUR_DROPLET_IP
```

Atau jika memakai user non-root:

```bash
ssh YOUR_USER@YOUR_DROPLET_IP
```

## 2. Install Docker

```bash
sudo apt update
sudo apt install -y ca-certificates curl git gnupg
sudo install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
sudo chmod a+r /etc/apt/keyrings/docker.gpg
echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu \
  $(. /etc/os-release && echo \"$VERSION_CODENAME\") stable" | \
  sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
sudo apt update
sudo apt install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
sudo usermod -aG docker $USER
newgrp docker
```

## 3. Siapkan Folder Aplikasi

```bash
cd /opt
sudo mkdir -p ista-ai
sudo chown $USER:$USER ista-ai
git clone https://github.com/Hasbi1605/ISTA-AI.git /opt/ista-ai
cd /opt/ista-ai
git checkout main
git pull origin main
```

## 4. Salin `.env.droplet` dari Laptop ke Droplet

Jalankan dari laptop Anda:

```bash
scp /Users/macbookair/Magang-Istana/.env.droplet YOUR_USER@YOUR_DROPLET_IP:/opt/ista-ai/.env.droplet
```

Jika pakai user `root`:

```bash
scp /Users/macbookair/Magang-Istana/.env.droplet root@YOUR_DROPLET_IP:/opt/ista-ai/.env.droplet
```

## 5. Jalankan Stack Produksi

```bash
cd /opt/ista-ai
docker compose --env-file .env.droplet -f docker-compose.production.yml up -d --build
```

## 6. Jalankan Migrasi

```bash
cd /opt/ista-ai
docker compose --env-file .env.droplet -f docker-compose.production.yml exec -T laravel php artisan migrate --force
docker compose --env-file .env.droplet -f docker-compose.production.yml restart laravel horizon scheduler
```

## 7. Cek Status Container

```bash
cd /opt/ista-ai
docker compose --env-file .env.droplet -f docker-compose.production.yml ps
```

Semua service yang diharapkan:

- `caddy`
- `mysql`
- `redis`
- `python-ai`
- `laravel`
- `horizon`

## 8. Smoke Test

```bash
curl -I https://ista-ai.app/up
```

Buka:

```text
https://ista-ai.app/chat
```

Lalu uji:

- login
- chat pendek
- chat yang butuh web search
- upload satu dokumen kecil

## 9. Jika Ada Error, Cek Log Ini

Laravel:

```bash
docker compose --env-file .env.droplet -f docker-compose.production.yml logs -f laravel
```

Python AI:

```bash
docker compose --env-file .env.droplet -f docker-compose.production.yml logs -f python-ai
```

Queue / Horizon:

```bash
docker compose --env-file .env.droplet -f docker-compose.production.yml logs -f horizon
```

Reverse proxy:

```bash
docker compose --env-file .env.droplet -f docker-compose.production.yml logs -f caddy
```

## 10. Setelah Demo Selesai

Update berikutnya cukup:

```bash
cd /opt/ista-ai
git pull origin main
docker compose --env-file .env.droplet -f docker-compose.production.yml up -d --build
docker compose --env-file .env.droplet -f docker-compose.production.yml exec -T laravel php artisan migrate --force
docker compose --env-file .env.droplet -f docker-compose.production.yml restart laravel horizon scheduler
```

## Catatan

- `.env.droplet` sudah memakai `APP_DOMAIN=ista-ai.app`
- API key AI diambil dari konfigurasi lokal saat ini
- password database dan token internal server dibuat baru khusus file deploy ini
- jika nanti ingin pakai `www` juga, tambahkan record DNS `www` ke IP droplet atau pakai `CNAME www -> @`
- Seluruh command compose production memakai `--env-file .env.droplet` agar secret dan variable image tag terbaca saat interpolation.
- Jangan gunakan `ONLYOFFICE_DOCUMENTSERVER_TAG=latest`; gunakan tag eksplisit seperti `9.3.1.2`.
