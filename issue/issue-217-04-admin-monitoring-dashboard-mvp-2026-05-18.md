# Child 4: Admin Monitoring Dashboard MVP

Parent: https://github.com/Hasbi1605/ISTA-AI/issues/209
GitHub Issue: https://github.com/Hasbi1605/ISTA-AI/issues/210

## Latar Belakang
Setelah fondasi role/presence (#214), event tracking (#213), dan layout admin (#216) tersedia, sistem membutuhkan dashboard monitoring read-only untuk memantau aktivitas ISTA AI tanpa mengubah perilaku fitur existing.

## Dependensi
- PR #240 (codex/issue-214-admin-foundation-role-presence) — role, middleware admin, presence.
- PR #241 (codex/issue-216-admin-design-system-layout-shell) — layout admin, komponen UI.
- PR #242 (codex/issue-213-ai-usage-event-tracking) — `ai_usage_events`, `AIUsageEventService`.

Branch ini dibuat di atas PR #241 dan di-merge dengan PR #242 agar semua tabel/middleware tersedia.

## Tujuan
- Menampilkan overview KPI operasional (user, AI request, dokumen, memo).
- Menampilkan status user `online` / `idle` / `offline` berdasarkan `last_seen_at`.
- Menampilkan event AI per fitur, status, dan rentang tanggal.
- Menampilkan error AI terbaru beserta `request_id` untuk korelasi log.
- Menampilkan dokumen user dengan status `pending` / `processing` / `ready` / `failed`.
- Memberi admin data operasional untuk evaluasi sistem dan laporan magang.

## Ruang Lingkup
Route baru di group `auth+verified+admin`:
- `GET /admin` — overview KPI + grafik 7/14/30 hari + distribusi fitur + recent events + recent errors.
- `GET /admin/users` — daftar user dengan presence dan agregasi event/conversation/document/memo per user.
- `GET /admin/usage` — event AI dengan filter feature/status/date range, distribusi fitur, KPI ringkas.
- `GET /admin/errors` — event status `error|blocked` dengan filter feature/date/request id, distribusi error.
- `GET /admin/documents` — daftar dokumen dengan filter search/status, distribusi mime, total ukuran.

Komponen Livewire baru:
- `App\Livewire\Admin\AdminDashboard`
- `App\Livewire\Admin\AdminUsers`
- `App\Livewire\Admin\AdminUsage`
- `App\Livewire\Admin\AdminErrors`
- `App\Livewire\Admin\AdminDocuments`

Service baru:
- `App\Services\Admin\AdminMetricsService` — agregasi KPI, time series, distribusi fitur, listing user/event/error/dokumen, presence calculator.

Sidebar admin (`x-admin.sidebar`) ditambah menu Users / Usage / Errors / Documents (admin) dan AI Configuration (super admin tetap).

## Di Luar Scope
- Menampilkan isi prompt, jawaban, atau isi dokumen user.
- Mengubah perilaku chat, RAG, memo, atau Drive (semua read-only).
- Knowledge base internal (#211, #217), AI Configuration form (#212), ISTURA (#215).
- Realtime WebSocket. Refresh dilakukan via tombol Refresh / interaksi filter Livewire.
- Export CSV pada MVP ini.

## Area / File
File baru:
- `laravel/app/Services/Admin/AdminMetricsService.php`
- `laravel/app/Livewire/Admin/AdminDashboard.php`
- `laravel/app/Livewire/Admin/AdminUsers.php`
- `laravel/app/Livewire/Admin/AdminUsage.php`
- `laravel/app/Livewire/Admin/AdminErrors.php`
- `laravel/app/Livewire/Admin/AdminDocuments.php`
- `laravel/resources/views/livewire/admin/admin-dashboard.blade.php`
- `laravel/resources/views/livewire/admin/admin-users.blade.php`
- `laravel/resources/views/livewire/admin/admin-usage.blade.php`
- `laravel/resources/views/livewire/admin/admin-errors.blade.php`
- `laravel/resources/views/livewire/admin/admin-documents.blade.php`
- `laravel/tests/Feature/Admin/AdminMonitoringDashboardTest.php`
- `laravel/tests/Unit/Services/Admin/AdminMetricsServiceTest.php`

File yang diubah:
- `laravel/routes/web.php` — pasang route Livewire untuk overview/users/usage/errors/documents.
- `laravel/resources/views/components/admin/sidebar.blade.php` — tambah menu monitoring.
- `laravel/resources/views/admin/overview.blade.php` dihapus karena diganti komponen Livewire.

## Risiko & Mitigasi
- Query agregasi berat: dibatasi `RECENT_ROWS_LIMIT = 100`, default range 7 hari, max 90 hari. Index `created_at`, `feature`, `status`, `user_id` sudah ada di migrasi #213/#214.
- Privacy: hanya kolom whitelist (nama/email/role/timestamps/feature/status/latency/error_code/request_id) yang ditampilkan. `metadata` dan isi prompt tidak ditampilkan.
- Event incomplete pre-history: query toleran terhadap `latency_ms`/`error_code`/`request_id` null.
- Pagination ringan: limit hard cap pada listing, tidak ada offset paging dulu agar tetap aman dan sederhana.

## Langkah Implementasi
1. Buat `AdminMetricsService` dengan agregasi: KPI overview, daily time series, feature distribution, recent events, recent errors, user listing dengan presence, document listing dengan status counts.
2. Buat 5 komponen Livewire dengan filter ringan via `wire:model.live`.
3. Buat blade Livewire menggunakan komponen `x-admin.kpi-card`, `x-admin.section`, `x-admin.table`, `x-admin.badge`, `x-admin.empty-state`, `x-admin.filter`, `x-admin.loading`.
4. Tambah route Livewire pada group admin.
5. Tambah menu sidebar.
6. Tambah test unit `AdminMetricsServiceTest` (KPI agregasi & presence calculator).
7. Tambah test feature `AdminMonitoringDashboardTest` (akses, filter, privacy, sidebar).
8. Jalankan `php artisan test --filter='Admin|AIUsage'`.

## Rencana Test
```bash
cd laravel && php artisan test --filter='Admin|AIUsage'
```

Skenario minimal:
- Admin dapat akses ke 5 halaman, user biasa dapat 403, guest redirect login.
- KPI overview dihitung benar dari fixture (events, users, documents, memos).
- Filter feature/status/date pada `/admin/usage` mempersempit hasil.
- Halaman `/admin/users` menampilkan badge online/idle/offline sesuai `last_seen_at`.
- Halaman `/admin/errors` hanya menampilkan event status error/blocked.
- Halaman `/admin/documents` menampilkan status counts.
- View tidak menampilkan isi prompt/jawaban/dokumen (assert konten metadata sensitif tidak bocor).
- Sidebar menampilkan menu monitoring untuk admin.

## Kriteria Selesai
- 5 halaman dashboard tersedia, semuanya read-only.
- Query dibatasi (limit + range) dan memakai index yang ada.
- Test relevan lulus.
- Tidak ada regresi pada `Admin|AIUsage` test existing dari PR #240/#241/#242.
