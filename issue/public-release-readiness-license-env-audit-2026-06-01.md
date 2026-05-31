# Public Release Readiness, License, and Env Leak Audit

## Latar Belakang

ISTA AI akan dipublikasikan dan direview sebagai proyek open source. Repo perlu siap untuk pembaca eksternal: lisensi jelas, README rapi, panduan kontribusi/security ada, dan secret hygiene aman sebelum dibagikan.

## Tujuan

- Menambahkan lisensi open source di root repo.
- Merapikan README agar menjelaskan nilai OSS, arsitektur, setup, test, dan batasan privasi.
- Menambahkan panduan kontribusi dan pelaporan security dasar.
- Memperkuat `.gitignore` agar file env, database lokal, credential, dan backup tidak mudah ter-commit.
- Mengecek apakah file `.env` nyata atau secret lain sudah tracked atau pernah masuk history.

## Ruang Lingkup

- Dokumentasi root repo dan metadata keamanan dasar.
- File ignore untuk secret/data lokal.
- Script benchmark yang masih menunjuk path `.env` lokal absolut.
- Audit statis aman terhadap file env tracked, ignored local env, dan history path sensitif.

## Di Luar Scope

- Refactor arsitektur Laravel/Python.
- Perubahan behavior aplikasi utama.
- Commit, push, atau update metadata GitHub remote tanpa instruksi lanjutan.
- Rotasi secret production, karena nilai secret tidak boleh dicetak atau dimanipulasi sembarangan.

## Area / File Terkait

- `README.md`
- `LICENSE`
- `SECURITY.md`
- `CONTRIBUTING.md`
- `.gitignore`
- `benchmarks/manual_embedding_limit.py`
- `benchmarks/manual_github_models.py`
- `benchmarks/manual_limit_4o.py`

## Risiko

- README bisa terlalu spesifik tentang konteks internal; perlu ditulis sebagai reference implementation tanpa data internal.
- File `.env` lokal berisi secret nyata; walau ignored, tetap perlu rotasi jika pernah dibagikan di luar mesin ini.
- Secret scanner sederhana dapat menghasilkan false positive pada placeholder, config key name, atau test fixture.

## Langkah Implementasi

1. Audit file env tracked, ignored, dan path sensitif di git history.
2. Tambahkan MIT `LICENSE`.
3. Rewrite README root agar siap untuk OSS/program application.
4. Tambahkan `SECURITY.md` dan `CONTRIBUTING.md`.
5. Perkuat `.gitignore` untuk env, database, credential, dan output lokal.
6. Hapus hardcoded absolute `.env` path dari benchmark script.
7. Jalankan secret scan ulang dan validasi syntax/checksum ringan.

## Rencana Test

- `git diff --check`
- `python3 -m py_compile` untuk benchmark script yang diubah.
- Secret/path scan ulang dengan redaksi nilai.
- Tidak menjalankan test Laravel/Python penuh karena perubahan utama berupa dokumentasi, ignore rules, dan benchmark helper non-runtime.

## Kriteria Selesai

- License ada di root repo.
- README siap dibaca reviewer OSS.
- Secret reporting dan kontribusi punya panduan dasar.
- `.env` nyata tetap ignored dan tidak tracked.
- Tidak ada secret nyata terdeteksi pada tracked env files saat scan redacted.
- Risiko secret lokal diringkas tanpa membocorkan nilai.
