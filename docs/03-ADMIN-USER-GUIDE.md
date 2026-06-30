# Admin User Guide

Panduan ini untuk admin/operator ISTA AI.

## Login Admin

Admin memakai jalur terpisah:

```text
/admin/login
```

Proteksi admin:

- rate limit route login;
- lockout progresif setelah beberapa percobaan gagal;
- forced password change bila akun ditandai wajib ganti password;
- 2FA TOTP wajib;
- trusted device 30 hari bila dipilih;
- absolute session lifetime admin;
- audit log untuk aksi admin penting.

## Dashboard

Halaman `/admin` menampilkan KPI operasional dari `AdminMetricsService`, seperti
aktivitas AI, dokumen, memo, dan status terkait.

## Usage

Halaman `/admin/usage` membaca `ai_usage_events` untuk melihat:

- fitur yang dipakai;
- model/provider;
- status berhasil/gagal;
- latensi;
- metadata aman seperti penggunaan dokumen, web search, knowledge, atau Prompy.

Isi prompt dan isi dokumen tidak ditampilkan sebagai log mentah.

## Errors

Halaman `/admin/errors` membantu triage event AI gagal. Gunakan halaman ini untuk
melihat pola error provider, dokumen, atau export tanpa membuka data rahasia di log publik.

## Documents

Halaman `/admin/documents` menampilkan dokumen user dan status pipeline ingest/preview.
Admin dapat memantau dokumen yang masih processing, ready, atau error.

## Users

Halaman `/admin/users` dipakai untuk memantau dan mengelola user, termasuk status
aktif/nonaktif dan presence. Akun nonaktif tidak bisa lanjut memakai aplikasi lewat
sesi lama karena middleware `active` memutus akses.

## Knowledge

Halaman `/admin/knowledge` dipakai untuk mengelola knowledge base internal:

1. Buat/kelola source.
2. Upload dokumen knowledge.
3. Tunggu pipeline ingest.
4. Aktifkan dokumen knowledge yang sudah ready.
5. Archive atau hapus dokumen bila tidak dipakai.

Knowledge memakai scope internal global dan dapat membantu jawaban general chat.

## Admin Accounts

Halaman `/admin/accounts` hanya untuk super-admin. Fungsinya mengelola akun admin,
disable account, role, dan audit perubahan akun. Semua aksi penting dicatat di
`admin_account_audits`.

## Membuat Admin Awal

Template env Laravel menyediakan:

```text
INITIAL_ADMIN_EMAIL=
INITIAL_ADMIN_PASSWORD=
INITIAL_SUPER_ADMIN_EMAIL=
INITIAL_SUPER_ADMIN_PASSWORD=
```

Nilai ini dipakai oleh command bootstrap akun admin jika operator menjalankannya.
Gunakan password sementara yang kuat, lalu paksa admin mengganti password pada login awal.

## Operasional Harian

- Cek `/admin/usage` untuk lonjakan pemakaian atau error.
- Cek `/admin/documents` untuk pipeline dokumen yang stuck.
- Cek `/admin/knowledge` setelah upload knowledge baru.
- Cek log Docker bila error berulang.
- Jangan menyalin isi prompt/dokumen privat ke issue publik.
