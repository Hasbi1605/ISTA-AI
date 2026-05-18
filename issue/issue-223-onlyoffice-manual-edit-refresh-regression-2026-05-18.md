# Fix OnlyOffice Manual Edit Refresh Regression

## Latar Belakang
Setelah hardening token dan cache config OnlyOffice, edit manual memo bisa terlihat masih ada selama sesi browser aktif, tetapi hilang setelah refresh. Download sudah aman karena tombol download memanggil force-save terlebih dahulu.

## Gejala
- User mengedit memo manual di OnlyOffice.
- User pindah ke history memo lain lalu kembali, editan masih terlihat.
- Setelah refresh halaman dan membuka memo yang sama, muncul warning versi berubah, editan hilang, dan editor bisa masuk keadaan tidak bisa diedit.

## Dugaan Akar Masalah
- Navigasi history memo belum mensinkronkan editor aktif sebelum mengganti memo.
- Callback status `4` tidak lagi memutar document key untuk sesi berikutnya.
- `document.key` yang terlalu lama dipakai ulang membuat OnlyOffice membuka state sesi/cache lama alih-alih versi storage yang sudah final.

## Tujuan
- Sebelum pindah history, memo aktif harus disimpan via force-save.
- Sesi editor berikutnya harus mendapat document key baru setelah status `2` final save atau status `4` close/no-changes.
- Refresh/close tab mendapat best-effort force-save agar perubahan manual tidak bergantung penuh pada callback close.
- Tambah test regresi agar perubahan ini terbaca sebagai perilaku sengaja dan aman.

## Ruang Lingkup
- `MemoWorkspace` Livewire.
- `MemoDocumentKey`.
- `OnlyOfficeCallbackController`.
- Alpine memo history/workspace JS.
- Test feature memo callback/workspace.

## Di Luar Scope
- Mengubah renderer memo Python.
- Mengubah model versi memo.
- Refactor besar integrasi OnlyOffice.
- Deploy production.

## Rencana Implementasi
1. Tambahkan rotasi key editor di `MemoDocumentKey::invalidateEditorKey()` agar key baru berbeda walau file belum berubah.
2. Restore invalidasi/rotasi key pada callback status `2` dan `4`; tetap pertahankan key pada status `6`.
3. Tambahkan opsi sync di `MemoWorkspace::loadMemo()` dan `switchMemoVersion()` untuk force-save memo aktif sebelum berpindah.
4. Tambahkan wait sebelum history navigation di JS dan best-effort force-save saat `pagehide`.
5. Update test callback key lifecycle dan tambah test Livewire untuk sync sebelum history switch.

## Risiko
- Force-save saat pindah history bisa menambah jeda bila OnlyOffice lambat.
- Jika OnlyOffice gagal menyimpan, navigasi history akan ditahan agar perubahan manual tidak hilang diam-diam.
- Rotasi key setelah close membuat sesi baru benar-benar fresh; ini sengaja untuk menghindari cache stale Document Server.

## Verifikasi
- Laravel feature tests untuk memo workspace, memo policy, dan OnlyOffice callback.
- Build frontend Vite untuk memastikan perubahan JS valid.
