# Child 3: Admin Design System dan Layout Shell

Parent: https://github.com/Hasbi1605/ISTA-AI/issues/209
GitHub Issue: https://github.com/Hasbi1605/ISTA-AI/issues/216

## Latar Belakang
Dashboard admin harus konsisten dengan halaman ISTA AI yang sudah ada, bukan terasa seperti template admin generik. Sebelum membuat halaman monitoring lengkap, perlu disiapkan layout shell dan komponen dasar admin yang mengikuti visual system existing.

## Tujuan
- Membuat layout admin yang konsisten dengan ISTA AI.
- Menyediakan sidebar admin dan komponen UI dasar.
- Menjaga dark mode, typography, warna, radius, button, dan table pattern tetap selaras.
- Memastikan halaman admin operasional, padat, dan mudah discan.

## Ruang Lingkup
- Admin layout/shell.
- Sidebar admin.
- Header/topbar admin.
- Komponen KPI card, table, filter, status badge, tabs, empty state, loading state.
- Responsive behavior.
- Navigation guard hanya muncul untuk admin/super_admin.

## Di Luar Scope
- Query metrik dashboard lengkap.
- Event tracking.
- Knowledge base logic.
- AI Configuration logic.

## Area / File Terkait
- `laravel/resources/views/layouts/app.blade.php`
- `laravel/resources/views/livewire/layout/navigation.blade.php`
- `laravel/resources/views/livewire/admin/*.blade.php`
- `laravel/resources/css/app.css`
- `laravel/app/Livewire/Admin/*`
- `laravel/tests/Feature/*`

## Risiko
- Desain admin terlalu berbeda dari halaman chat/dashboard existing.
- Nested cards dan spacing berlebihan membuat dashboard sulit discan.
- Dark mode tidak konsisten.
- Admin nav tidak boleh tampil untuk user biasa.

## Langkah Implementasi
1. Audit kelas dan pattern visual existing pada dashboard, chat, profile, memo.
2. Buat admin layout shell dengan sidebar.
3. Buat placeholder halaman admin overview.
4. Buat komponen reusable untuk KPI, table, filter, badge, loading, empty state.
5. Tambah responsive rules untuk desktop/mobile.
6. Tambah admin nav hanya untuk role admin/super_admin.
7. Tambah test render dan akses.

## Rencana Test
```bash
cd laravel && php artisan test --filter='Admin|Dashboard'
```

Verifikasi manual:
- Admin layout terlihat konsisten dengan ISTA AI.
- Dark mode tidak rusak.
- Mobile tidak overlap.
- Chat/dashboard publik tidak berubah.

## Kriteria Selesai
- Layout admin tersedia.
- Komponen dasar siap dipakai child dashboard lain.
- Admin nav hanya muncul untuk role yang tepat.
- Tidak ada regresi visual besar pada halaman existing.
