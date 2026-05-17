# Issue 209: Generate Memo Gagal Karena Submit Konfigurasi Tidak Sinkron

## Gejala
- Di tab memo, user mengisi konfigurasi memo lalu menekan `Buat memo`.
- UI menampilkan error validasi `Nomor memo wajib diisi.` atau `Isi / poin wajib harus diisi.` meskipun field terlihat sudah diisi.
- Setelah validasi gagal, field yang sebelumnya tampak berisi dapat kembali terlihat kosong karena render Livewire memakai state server lama.

## Bukti Awal
- Error berasal dari `MemoWorkspace::validateMemoConfiguration()`.
- Form konfigurasi memakai `wire:model` pada field, sedangkan tombol `Buat memo` berada di composer bawah di luar form.
- Git history menunjukkan commit `7fbb993` memindahkan tombol generate dari dalam `<form wire:submit="generateConfiguredMemo">` menjadi tombol luar form dengan `wire:click="generateConfiguredMemo"`.

## Dugaan Akar Masalah
Tombol luar form memanggil action Livewire langsung, sehingga nilai input `wire:model` yang masih deferred/pending di browser tidak selalu ikut tersinkron sebelum validasi server. Server kemudian memvalidasi state kosong/lama.

## Scope Fix Minimal
- Pertahankan tombol generate di composer bawah agar tetap mudah dijangkau.
- Kaitkan tombol tersebut kembali ke form konfigurasi menggunakan submit HTML standar (`form="..."`, `type="submit"`).
- Hindari refactor alur generate, service AI, atau template DOCX.

## Verifikasi
- Tambahkan atau update test render untuk memastikan tombol bawah men-submit form konfigurasi, bukan memanggil `wire:click` terpisah.
- Jalankan test Laravel memo yang relevan.

## Hasil
- Tombol sticky `Buat memo` tetap berada di composer bawah, tetapi sekarang men-submit `#memo-config-form`.
- Test guard ditambahkan di `MemoWorkspaceTest` agar tombol tidak kembali menjadi `wire:click` yang terpisah dari form.
- Verifikasi:
  - `php artisan test tests/Feature/Memos/MemoWorkspaceTest.php` pass, 23 test / 200 assertion.
  - `./vendor/bin/pint --test tests/Feature/Memos/MemoWorkspaceTest.php` pass.
