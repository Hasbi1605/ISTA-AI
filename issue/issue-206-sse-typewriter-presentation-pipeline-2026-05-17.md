# Penyatuan Presentasi Jawaban SSE ke Typewriter

## Latar Belakang
Output chat biasa dan web search terasa kurang mulus dibanding chat dengan dokumen. Penyebab utamanya ada di frontend: jalur SSE streaming dan jalur Blade typewriter hidup berdampingan. Saat SSE selesai, `final-content` dapat langsung menimpa teks live dan `refreshPendingChatState()` segera mengganti bubble streaming dengan message persisted.

## Tujuan
- Jawaban SSE chat biasa, web search, dan dokumen tetap tampil sebagai typewriter sampai selesai.
- `final-content` dipakai sebagai rekonsiliasi, bukan direct replace.
- Refresh Livewire ditunda sampai typewriter selesai.
- Rujukan web/dokumen pada streaming bubble baru tampil setelah body jawaban selesai diketik.
- Loading phase lebih responsif ketika chunk pertama datang.

## Ruang Lingkup
- Perubahan di frontend chat SSE pipeline (`resources/js/chat-page.js`).
- Perubahan kecil pada Blade streaming bubble agar rujukan dikontrol oleh state `sourcesVisible`.
- Test kontrak frontend via Feature test yang sudah membaca `chat-page.js` dan Blade output.

## Di Luar Scope
- Tidak mengubah kontrak backend SSE.
- Tidak menghapus footer rujukan yang tersimpan di database.
- Tidak refactor penuh Blade inline typewriter menjadi helper Alpine tunggal.
- Tidak mengubah kualitas konten AI atau retrieval.

## Area / File Terkait
- `laravel/resources/js/chat-page.js`
- `laravel/resources/views/livewire/chat/partials/chat-messages.blade.php`
- `laravel/tests/Feature/Chat/ChatUiTest.php`

## Risiko
- Polling Livewire masih dapat menjadi fallback bila koneksi SSE putus; perubahan harus tetap kompatibel.
- Rujukan yang tersimpan di final message tetap ada sebagai markdown setelah refresh persisted message.
- Perubahan timing harus tidak membuat pending conversation macet.

## Langkah Implementasi
1. Tambahkan state untuk menahan visibility rujukan streaming.
2. Ubah `final-content` agar masuk ke queue typewriter berdasarkan suffix yang belum tampil.
3. Ubah event `done` agar refresh Livewire dijalankan setelah typewriter settle.
4. Tampilkan rujukan streaming setelah queue typewriter selesai.
5. Jadikan loading phase event-driven saat chunk pertama datang dan kurangi delay fixed phase.
6. Tambahkan assertion test untuk kontrak JS/Blade.

## Rencana Test
- `php artisan test --filter=ChatUiTest`
- `npm run build`
- `vendor/bin/pint tests/Feature/Chat/ChatUiTest.php`
- `git diff --check`

## Kriteria Selesai
- Tidak ada direct replacement `streamingText = streamingFinalText`.
- Refresh pending dipanggil lewat callback setelah typewriter selesai.
- Rujukan streaming memakai guard `sourcesVisible`.
- Test dan build relevan lulus.
