# Admin UI Consistency, Actions, and Pagination Hotfix

Tanggal: 2026-05-19

## Tujuan

Merapikan lanjutan halaman admin production agar konsisten di tab Users, Documents, Account Management, dan pagination semua tabel. Perubahan juga menambahkan aksi hapus akun regular user oleh super admin dengan guard agar akun admin/super admin tidak bisa ikut terhapus dari tab Users.

## Scope

- Users:
  - `/admin/users` hanya menampilkan regular user.
  - Hilangkan filter/kolom role serta kolom event hari ini dan event 7 hari.
  - Tambahkan aksi delete akun user untuk super admin saja.
  - Guard backend: hanya super admin aktif yang boleh menghapus regular user; target admin/super admin harus ditolak.
- KPI:
  - Persentase di kartu admin dibulatkan ke integer: `0%`, `11%`, bukan `0.0%`.
- Account Management:
  - Tombol `Tambah Akun` dipindah dari hero ke header tabel `Daftar Akun Admin`.
- Documents:
  - Icon file mengikuti warna/type icon di sidebar dokumen chat.
  - Hilangkan kolom `Tipe` dari tabel `Dokumen Terbaru`.
  - Status chip dokumen memakai style standar yang sama dengan tabel admin lain.
  - Modal detail dokumen dibuat lebih ringkas dan fokus: status, owner, size, chunks, source, uploaded, status AI, metadata penting.
- Pagination:
  - Buat pagination admin standar berbahasa Indonesia.
  - Terapkan ke tabel Users, Usage, Errors, Documents, dan Account Management.

## Risiko

- Delete akun user menyentuh data berelasi. Untuk dokumen, cleanup harus melewati `DocumentLifecycleService` agar file, preview, dan vector ikut dibersihkan sebelum user dihapus.
- Perubahan Blade/CSS harus tetap aman di dark mode dan tidak memunculkan scrollbar horizontal.
- Pagination custom harus tetap kompatibel dengan Livewire paginator.

## Rencana Implementasi

1. Tambah service method untuk delete regular user dengan audit dan cleanup dokumen.
2. Update Livewire Users agar listing dan summary selalu regular-user only, serta expose aksi delete untuk super admin.
3. Update Blade/CSS Users, Documents, Accounts, dan pagination shared.
4. Update format persentase admin ke integer di tab terkait.
5. Tambahkan/update test untuk guard delete, regular-only users, documents table, modal documents, dan pagination Indonesia.
6. Jalankan test Laravel relevan, build asset, lalu full verification sebelum merge/deploy.
7. Deploy ke production dan QA end-to-end. Jika ada mismatch, patch-deploy-QA ulang.

## Verifikasi

- Targeted Laravel tests:
  - `php artisan test tests/Feature/Admin/AdminMonitoringDashboardTest.php tests/Feature/Admin/AdminAccountManagementTest.php tests/Unit/Services/Admin/AdminMetricsServiceTest.php`
- Build frontend:
  - `npm run build`
- Full Laravel test sebelum merge/deploy:
  - `php artisan test`
- Full Python test sebelum merge/deploy:
  - `cd python-ai && source venv/bin/activate && pytest`
- Production QA:
  - Health check.
  - Smoke auth/admin routes.
  - Screenshot/check UI admin untuk Users, Documents, Account Management, pagination, dan modal detail dokumen.
  - Cek log container setelah deploy.
