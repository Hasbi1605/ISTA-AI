# Security Policy

## Supported Versions

Security fix saat ini ditangani di branch `main`. Jika nanti ada release
versioning, bagian ini perlu diperbarui dengan daftar versi yang masih disupport.

## Reporting a Vulnerability

Jangan membuka public issue untuk vulnerability yang bisa mengekspos secret,
dokumen privat, data user, token, atau detail infrastructure.

Untuk saat ini, laporkan security concern ke maintainer repository melalui profil
GitHub yang terhubung dengan repo ini. Sertakan:

- ringkasan issue;
- komponen atau path terdampak;
- langkah reproduksi aman memakai data lokal/test;
- impact dan saran mitigasi bila diketahui.

Jangan menyertakan API key asli, service account, dokumen production, database
export, session cookie, private key, atau data user privat.

## Secret Handling

ISTA AI memakai environment variable untuk provider key, token internal,
password database, secret editor dokumen, email provider, dan kredensial deploy.

Jangan commit:

- file `.env` asli;
- dump database atau file SQLite lokal;
- data Chroma/vector;
- dokumen production;
- service-account JSON;
- private key, certificate, atau deploy credential;
- file berisi token/API key/password.

Jika secret pernah ter-commit atau dibagikan keluar mesin terpercaya, revoke dan
rotate segera. Menghapus file dari commit terbaru saja tidak cukup bila secret
sudah masuk git history atau remote.

## Local Security Checks

Check dasar sebelum publish branch:

```bash
git status --short --ignored .env.droplet laravel/.env laravel/.env.backup laravel/.env.production python-ai/.env
git ls-files | rg -i '(^|/)(\.env|.*secret.*|.*credential.*|.*token.*|.*key.*|.*pem|.*p12|.*pfx|.*sql|.*sqlite|.*db)$'
git diff --check
```

Gunakan secret scanner seperti Gitleaks atau TruffleHog bila tersedia, terutama
sebelum repo dibuka lebih luas atau diserahkan untuk review eksternal.

## Data Privacy

Sebelum memakai data nyata, baca:

- [docs/09-SECURITY-PRIVACY.md](docs/09-SECURITY-PRIVACY.md)
- [docs/data-flow-privacy.md](docs/data-flow-privacy.md)

Provider AI/search eksternal dapat menerima prompt, chat history, chunk dokumen,
embedding input, reference image Prompy, atau query web sesuai fitur yang dipakai.
Pastikan pemilik data menyetujui klasifikasi dokumen dan provider aktif.
