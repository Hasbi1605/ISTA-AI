# Issue 239 - Admin Users Redesign

## Tujuan
Mengubah tampilan `/admin/users` agar mengikuti referensi screenshot: halaman ringkas, read-only, dengan KPI presence di atas filter dan tabel user yang lebih rapi.

## Scope
- Tambahkan KPI dinamis untuk total user, online, idle, dan offline.
- Rapikan hero/header halaman Users agar konsisten dengan desain admin overview.
- Ubah filter menjadi panel lebar dengan search/status/role dan reset di kanan.
- Ubah tabel user agar memakai avatar inisial, badge status/role, densitas seperti referensi, dan pagination 15 user per halaman.
- Rapikan kolom total agar conv/doc/memo tidak bertumpuk secara acak.

## Risiko
- Perubahan CSS admin harus tetap terisolasi agar tidak mengganggu halaman monitoring lain.
- Query KPI presence harus tetap read-only dan tidak membuka isi percakapan/dokumen/memo.

## Verifikasi
- Jalankan build Vite.
- Jalankan test admin monitoring dan unit service terkait presence.
