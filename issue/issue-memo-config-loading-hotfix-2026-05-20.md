# Issue: Memo configuration loading label hotfix

## Tujuan
- Menghilangkan loading ganda pada tombol konfigurasi memo.
- Menjaga label loading sesuai konteks:
  - Memo baru menampilkan "Memproses...".
  - Revisi dari konfigurasi menampilkan "Menyimpan perubahan...".

## Scope
- Markup tombol konfigurasi memo di workspace memo.
- Test render tombol agar label loading tidak tercampur antar konteks.

## Di luar scope
- Mengubah alur generate memo, force-save OnlyOffice, revisi chat, atau struktur data memo.
- Redesign panel konfigurasi memo.

## Risiko
- State loading menjadi kosong jika markup tombol tidak lagi cocok dengan Livewire atau Alpine.

## Implementasi
1. Pisahkan label loading tombol berdasarkan konteks memo baru atau memo aktif.
2. Tambahkan test render untuk memastikan memo baru hanya membawa label proses dan memo aktif hanya membawa label simpan.
3. Jalankan test Laravel memo, build frontend, dan verifikasi sebelum merge/deploy.

## Verifikasi
- `php artisan test --filter=MemoWorkspaceTest`
- `npm run build`
- `git diff --check`
- Full test akhir sebelum merge.

## Hasil Verifikasi Lokal
- `php artisan test --filter=MemoWorkspaceTest` lulus: 32 tests, 242 assertions.
- `npm run build` lulus.
- `npm audit --audit-level=high` lulus: 0 vulnerabilities.
- `git diff --check` lulus.
- `php -d memory_limit=-1 vendor/bin/phpunit` lulus: 556 tests, 2422 assertions.
- `cd python-ai && source venv/bin/activate && pytest` lulus: 367 tests.
