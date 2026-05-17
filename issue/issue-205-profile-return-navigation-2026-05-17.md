# Tombol Kembali Kontekstual dari Profil

## Latar Belakang
Pengguna yang membuka Pengaturan Akun dari tab Chat atau Memo masuk ke halaman profil tanpa affordance kembali yang jelas. Header hanya mengarah ke dashboard, sehingga alur balik ke pekerjaan terakhir terasa kurang langsung.

## Tujuan
Menampilkan tombol kembali yang sesuai konteks asal:
- `Kembali ke Chat` bila profil dibuka dari sidebar Chat.
- `Kembali ke Memo` bila profil dibuka dari sidebar Memo.

## Ruang Lingkup
- Tambahkan query asal yang aman pada link Pengaturan Akun di sidebar Chat dan Memo.
- Tambahkan tombol kembali kondisional pada halaman profil berdasarkan query `from=chat|memo`.
- Tambahkan test untuk memastikan link asal dan tombol kembali muncul sesuai konteks.

## Di Luar Scope
- Tidak mengubah mekanisme autentikasi, form profil, atau tab pengaturan profil.
- Tidak menambahkan penyimpanan state kompleks seperti ID chat atau memo terakhir.
- Tidak mengubah navigasi dashboard atau dropdown profil global.

## Area / File Terkait
- `laravel/resources/views/profile.blade.php`
- `laravel/resources/views/livewire/chat/partials/chat-left-sidebar.blade.php`
- `laravel/resources/views/livewire/memos/partials/memo-history-sidebar.blade.php`
- `laravel/tests/Feature/ProfileTest.php`
- `laravel/tests/Feature/Chat/ChatUiTest.php`
- `laravel/tests/Feature/Memos/MemoWorkspaceTest.php`

## Risiko
- Query `from` tidak boleh menjadi open redirect; tujuan balik harus dipetakan statis di server.
- Tombol baru harus tetap rapi pada desktop dan mobile.
- Link existing dari dashboard harus tetap tidak membawa konteks chat/memo.

## Langkah Implementasi
1. Ubah link Pengaturan Akun dari sidebar Chat menjadi `route('profile', ['from' => 'chat'])`.
2. Ubah link Pengaturan Akun dari sidebar Memo menjadi `route('profile', ['from' => 'memo'])`.
3. Di halaman profil, petakan `from=chat|memo` ke URL dan label tombol kembali statis.
4. Render tombol kembali hanya bila konteks valid.
5. Tambahkan assertion test untuk profil, sidebar chat, dan sidebar memo.

## Rencana Test
- Jalankan `php artisan test --filter=ProfileTest`.
- Jalankan `php artisan test --filter=ChatUiTest`.
- Jalankan `php artisan test --filter=MemoWorkspaceTest`.
- Jalankan formatter Pint pada file yang disentuh.
- Jalankan `git diff --check`.

## Kriteria Selesai
- Profil dari Chat menampilkan tombol `Kembali ke Chat`.
- Profil dari Memo menampilkan tombol `Kembali ke Memo`.
- Profil yang dibuka langsung tidak menampilkan tombol kontekstual palsu.
- Test relevan lulus.
