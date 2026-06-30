# Dokumentasi ISTA AI

Folder ini berisi dokumentasi handover dan operasional ISTA AI. Dokumen lama
yang lebih teknis tetap dipertahankan, tetapi urutan di bawah adalah jalur baca
yang direkomendasikan untuk mentor, admin/operator, dan developer lanjutan.

## Urutan Baca

1. [00-HANDOVER.md](00-HANDOVER.md) - ringkasan sistem, status, risiko, dan konteks serah-terima.
2. [01-PRODUCT-OVERVIEW.md](01-PRODUCT-OVERVIEW.md) - gambaran produk dan fitur aktif.
3. [02-STAFF-USER-GUIDE.md](02-STAFF-USER-GUIDE.md) - panduan pengguna/staf.
4. [03-ADMIN-USER-GUIDE.md](03-ADMIN-USER-GUIDE.md) - panduan admin/operator.
5. [04-ARCHITECTURE.md](04-ARCHITECTURE.md) - arsitektur ringkas Laravel + FastAPI.
6. [05-ENVIRONMENT.md](05-ENVIRONMENT.md) - environment variable dan secret penting.
7. [06-DEPLOYMENT.md](06-DEPLOYMENT.md) - setup production dan CI/CD.
8. [07-SERVER-DOMAIN-MIGRATION.md](07-SERVER-DOMAIN-MIGRATION.md) - catatan pindah server/domain.
9. [08-OPERATIONS-RUNBOOK.md](08-OPERATIONS-RUNBOOK.md) - monitoring, backup, restore, dan troubleshooting.
10. [09-SECURITY-PRIVACY.md](09-SECURITY-PRIVACY.md) - keamanan, privasi, dan alur data.
11. [10-DATABASE-SUMMARY.md](10-DATABASE-SUMMARY.md) - tabel dan relasi utama.

## Dokumen Teknis Detail

- [CODEBASE-CONTEXT.md](CODEBASE-CONTEXT.md) - peta codebase paling detail untuk developer/agent.
- [data-flow-privacy.md](data-flow-privacy.md) - catatan alur data dan provider eksternal.
- [production-config-guide.md](production-config-guide.md) - konfigurasi production.
- [production-maintenance.md](production-maintenance.md) - maintenance RAM, disk, dan log.
- [deploy-digitalocean.md](deploy-digitalocean.md) - panduan deploy single droplet.
- [github-actions-cicd.md](github-actions-cicd.md) - workflow CI/CD GitHub Actions.

## Catatan

- `PRD-ISTA-AI.md` di root adalah dokumen requirement besar.
- `AGENTS.md` adalah aturan kerja agent dan changelog teknis internal.
- File `.env` asli, database, dokumen produksi, dan data Chroma tidak boleh ditaruh di repo.
