# Tambah Loading State Saat Pindah History Memo

## Latar Belakang
Sidebar history pada tab chat sudah menampilkan spinner ketika user membuka percakapan lain, sehingga pada jaringan lambat user mendapat feedback bahwa aplikasi sedang memuat. Sidebar history pada tab memo masih memanggil `loadMemo` langsung lewat Livewire tanpa state loading lokal, sehingga perpindahan memo dapat terasa seperti macet.

## Tujuan
Menambahkan indikator loading pada item history memo yang sedang dipilih, mengikuti pola sidebar chat.

## Ruang Lingkup
- Tambahkan state loading di Alpine component `memoHistory`.
- Ubah klik item history memo agar memanggil `loadMemo` lewat handler Alpine yang dapat menunggu promise Livewire.
- Tampilkan spinner kecil pada item memo yang sedang dimuat.
- Pastikan active state dan pembukaan section history tetap berjalan seperti sebelumnya.
- Tambahkan test markup/handler agar perilaku UI tidak hilang pada refactor berikutnya.

## Di Luar Scope
- Tidak mengubah flow generate memo.
- Tidak mengubah grouping history memo.
- Tidak mengubah desain sidebar secara besar.
- Tidak mengubah mekanisme delete memo.

## Area / File Terkait
- `laravel/resources/js/chat-page.js`
- `laravel/resources/views/livewire/memos/partials/memo-history-sidebar.blade.php`
- `laravel/tests/Feature/Memos/MemoWorkspaceTest.php`

## Risiko
- Spinner bisa tidak hilang jika promise gagal atau request lama selesai setelah request baru.
- Active state bisa bergeser terlalu cepat jika klik memo gagal.
- Tombol delete memo perlu tetap bisa diakses dan tidak ikut memicu load memo.

## Langkah Implementasi
1. Tambahkan `loadingMemoId` dan helper `isLoadingMemo` pada `memoHistory`.
2. Tambahkan method `loadMemo($wire, id)` yang set loading, memanggil `$wire.loadMemo(id)`, sinkron active memo, dan membersihkan loading di `finally`.
3. Ubah button history memo dari `wire:click` langsung menjadi `@click`.
4. Render spinner kecil di sisi ikon dokumen saat memo sedang dimuat.
5. Tambahkan assertion test untuk handler loading dan markup spinner.

## Rencana Test
- Jalankan `php artisan test tests/Feature/Memos/MemoWorkspaceTest.php`.
- Jalankan `npm run build` untuk memastikan asset frontend valid.
- Jalankan `git diff --check`.

## Kriteria Selesai
- Klik history memo menampilkan loading indicator pada item yang dipilih.
- Loading indicator hilang setelah `loadMemo` selesai atau gagal.
- Sidebar memo tetap membuka section yang memuat memo aktif.
- Test relevan dan build frontend berhasil.
