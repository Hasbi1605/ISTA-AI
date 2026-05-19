# Deploy ISTA AI Hybrid ke DigitalOcean

Panduan ini menyiapkan **1 droplet** untuk stack hybrid:

- Laravel sebagai UI publik
- Python FastAPI sebagai AI service internal
- MySQL + Redis di host yang sama
- Caddy sebagai reverse proxy publik + TLS otomatis

## Rekomendasi Droplet

- Ubuntu 24.04 LTS
- 4 GB RAM / 2 vCPU
- 80 GB SSD
- Backup mingguan opsional

Jika traffic masih kecil, 2 GB / 1 vCPU masih bisa dipakai untuk demo, tetapi 4 GB lebih aman untuk upload dokumen, queue, OCR, dan RAG.

## 1. Siapkan DNS

1. Arahkan domain atau subdomain ke IP droplet.
2. Jika memakai Cloudflare, paling aman mulai dari mode **DNS only** dulu sampai TLS origin aktif.
3. Gunakan hostname yang akan dipakai di `APP_DOMAIN`, misalnya:
   - `ista-ai.app`
   - `www.ista-ai.app`

## 2. Install Docker di Droplet

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

## 3. Buka Firewall

```bash
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
```

## 4. Clone Repo

```bash
cd /opt
sudo mkdir -p ista-ai
sudo chown $USER:$USER ista-ai
git clone https://github.com/Hasbi1605/ISTA-AI.git /opt/ista-ai
cd /opt/ista-ai
git checkout main
git pull origin main
```

## 5. Siapkan Environment Produksi

```bash
cp deploy/digitalocean.env.example .env.droplet
```

Lalu isi `.env.droplet`:

- `APP_DOMAIN` dengan domain final
- `APP_URL` dengan `https://domain-kamu`
- `APP_KEY` dengan key Laravel valid
- `AI_SERVICE_TOKEN` dengan secret internal yang sama untuk Laravel dan Python, bukan nilai default
- `DB_PASSWORD` / `MYSQL_PASSWORD` / `MYSQL_ROOT_PASSWORD`
- seluruh API key AI (`GITHUB_TOKEN`, `GROQ_API_KEY`, `GEMINI_API_KEY`, `LANGSEARCH_API_KEY`)
- `ONLYOFFICE_JWT_SECRET` untuk JWT editor/callback
- `ONLYOFFICE_SIGNED_URL_SECRET` untuk HMAC signed URL file memo, gunakan secret berbeda dari `APP_KEY`
- `ONLYOFFICE_SIGNED_URL_TTL_MINUTES` untuk durasi signed URL memo, disarankan 15-30
- `ONLYOFFICE_DOCUMENTSERVER_TAG` untuk pin versi OnlyOffice, jangan pakai `latest`
- `TRUSTED_PROXIES` untuk sumber proxy internal yang dipercaya Laravel, default Docker/Caddy: `127.0.0.1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16`

Contoh generator cepat:

```bash
openssl rand -hex 32
openssl rand -base64 32
```

Gunakan hasil `openssl rand -base64 32` sebagai isi setelah prefix `base64:` untuk `APP_KEY`.

## 6. Build dan Jalankan Service

```bash
docker compose --env-file .env.droplet -f docker-compose.production.yml up -d --build
```

Cek status:

```bash
docker compose --env-file .env.droplet -f docker-compose.production.yml ps
```

## 7. Jalankan Migrasi

```bash
docker compose --env-file .env.droplet -f docker-compose.production.yml exec -T laravel php artisan migrate --force
```

Jika Anda butuh akun awal atau data dummy, jalankan seed secara terpisah sesuai kebutuhan aplikasi.

## 8. Restart Aplikasi Setelah Migrasi Pertama

```bash
docker compose --env-file .env.droplet -f docker-compose.production.yml restart laravel horizon scheduler
```

## 9. Verifikasi Deploy

Smoke check:

```bash
curl -I https://DOMAIN-KAMU/up
```

Buka:

```text
https://DOMAIN-KAMU/chat
```

Lalu uji:

- login
- chat pendek
- web search
- upload 1 dokumen kecil

## 10. Melihat Log

Laravel web:

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

Reverse proxy publik:

```bash
docker compose --env-file .env.droplet -f docker-compose.production.yml logs -f caddy
```

Semua service:

```bash
docker compose --env-file .env.droplet -f docker-compose.production.yml logs -f
```

## 11. Update Rilis Berikutnya

```bash
cd /opt/ista-ai
git pull origin main
docker compose --env-file .env.droplet -f docker-compose.production.yml up -d --build
docker compose --env-file .env.droplet -f docker-compose.production.yml exec -T laravel php artisan migrate --force
docker compose --env-file .env.droplet -f docker-compose.production.yml restart laravel horizon scheduler
```

## 12. Backup Minimum

- aktifkan backup droplet mingguan jika budget cukup
- export `.env.droplet` ke tempat aman
- backup volume MySQL secara berkala
- backup volume `chroma_data` jika indeks dokumen ingin dipertahankan

## Catatan Operasional

- Service Python tidak diexpose ke publik; hanya Laravel yang menerima trafik internet.
- Log model, web search, dan error runtime sekarang dilihat via `docker compose logs`, bukan terminal Python lokal seperti saat development manual.
- Compose produksi ini diposisikan untuk **single droplet**. Jika nanti dipindah ke server mentor, file yang sama bisa menjadi baseline sebelum dipisah ke service yang lebih formal.
- Panduan konfigurasi rinci ada di `docs/production-config-guide.md`.
- Checklist maintenance RAM/disk/log ada di `docs/production-maintenance.md`.
- Catatan data flow dan privacy ada di `docs/data-flow-privacy.md`.
