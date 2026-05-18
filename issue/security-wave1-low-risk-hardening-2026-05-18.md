# Wave 1 Low-Risk Security Hardening

GitHub issue: https://github.com/Hasbi1605/ISTA-AI/issues/230

## Latar Belakang
Validasi ulang temuan security menunjukkan beberapa hardening bernilai tinggi namun berisiko rendah dapat dipisahkan dari perubahan besar seperti CSP, trusted proxy, dan secret manager. Wave 1 difokuskan pada perubahan kecil yang tidak mengubah alur utama pengguna.

## Tujuan
- Mengurangi risiko timing side-channel pada token internal Python AI.
- Mengurangi kemungkinan data sensitif ikut masuk log aplikasi.
- Membatasi proses dokumen yang menggantung terlalu lama.
- Menambahkan safety net rate limit pada callback OnlyOffice.
- Menormalisasi nama user yang dikirim ke konfigurasi OnlyOffice.

## Ruang Lingkup
- Python AI token verification menggunakan constant-time comparison.
- Default timeout subprocess dokumen diturunkan ke batas yang lebih aman.
- Logging Laravel untuk error AI dan global exception direduksi agar tidak membawa body/raw excerpt sensitif.
- Route callback OnlyOffice diberi throttle ringan.
- Nama user pada konfigurasi editor OnlyOffice dibersihkan untuk karakter berisiko.
- Test regresi ditambahkan atau diperbarui untuk area yang berubah.

## Di Luar Scope
- CSP dan sanitasi markdown image eksternal.
- Perubahan `trustProxies`.
- Migrasi `.env.droplet` ke secret manager.
- Sanitizer HTML export berbasis parser allowlist.
- Key separation signed URL OnlyOffice.
- Deploy production.

## Area / File Terkait
- `python-ai/app/api_shared.py`
- `python-ai/app/document_runner.py`
- `python-ai/tests/test_env_utils.py`
- `python-ai/tests/test_document_runner.py`
- `laravel/bootstrap/app.php`
- `laravel/app/Services/AIService.php`
- `laravel/app/Livewire/Memos/MemoWorkspace.php`
- `laravel/routes/web.php`
- Test Laravel terkait security/logging/OnlyOffice.

## Risiko
- Timeout dokumen yang lebih pendek dapat menggagalkan dokumen sangat besar lebih cepat.
- Throttle callback OnlyOffice harus cukup longgar agar tidak mengganggu save normal.
- Redaksi log dapat mengurangi detail debugging production.
- Normalisasi nama user harus tetap mengizinkan nama Indonesia yang wajar.

## Langkah Implementasi
1. Tambahkan constant-time comparison untuk `AI_SERVICE_TOKEN`.
2. Turunkan default `DOCUMENT_PROCESS_SUBPROCESS_TIMEOUT` ke batas konservatif.
3. Redaksi logging AI service dan global exception message agar tidak menyimpan payload mentah.
4. Tambahkan throttle ringan pada route OnlyOffice callback.
5. Normalisasi nama user sebelum dimasukkan ke `editorConfig.user.name`.
6. Tambahkan test regresi Laravel dan Python.
7. Jalankan test relevan dan full validation untuk area yang tersentuh.

## Rencana Test
- Python: test token valid/invalid tetap bekerja dan verify menggunakan `hmac.compare_digest`.
- Python: test default timeout subprocess memakai nilai baru dan env override tetap dihormati.
- Laravel: test route callback OnlyOffice memiliki throttle middleware.
- Laravel: test nama user OnlyOffice dibersihkan dari karakter HTML berisiko.
- Laravel: test logging AI/global exception tidak membawa raw body/token sensitif.
- Jalankan full `php artisan test`.
- Jalankan full `source venv/bin/activate && pytest`.

## Kriteria Selesai
- Semua perubahan Wave 1 terimplementasi tanpa scope melebar.
- Test baru menutup perilaku security yang berubah.
- Full test Laravel dan Python lulus.
- GitHub issue dibuat.
- Branch dipush dan PR dibuat untuk review.
