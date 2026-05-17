# SSE Final Reference Unification

## Latar Belakang
Typewriter SSE sudah memakai bubble yang sama dengan jawaban final, tetapi live SSE masih punya jalur renderer tambahan: metadata `sources` dipecah menjadi kartu `RUJUKAN` di bawah bubble. Setelah refresh, final/persisted renderer menampilkan footer `Dokumen rujukan:` atau `Rujukan:` sebagai bagian dari isi bubble karena konten itu memang disimpan di database.

Koreksi dari QA manual: sumber kebenaran UX adalah tampilan final setelah refresh. Jadi live SSE harus mengikuti final renderer, bukan memecah rujukan menjadi kartu terpisah.

## Tujuan
- Streaming SSE dan final state tetap memakai satu bubble live tanpa flicker.
- `final-content` SSE diketik apa adanya agar sama dengan isi message yang tersimpan.
- Footer rujukan markdown tetap berada di bubble live, sama seperti final setelah refresh.
- Kartu `RUJUKAN` live tidak muncul sebagai renderer kedua.
- Typewriter tidak reset penuh ketika final text hanya berbeda pada whitespace atau lebih pendek sedikit dari buffer yang sudah diketik.

## Ruang Lingkup
- `laravel/resources/js/chat-page.js`
- Test kontrak UI chat di `laravel/tests/Feature/Chat/ChatUiTest.php`

## Di Luar Scope
- Tidak mengubah kualitas jawaban AI, retrieval, atau prompt.
- Tidak mengubah format penyimpanan message di database.
- Tidak menambah migrasi untuk menyimpan sources secara terstruktur.

## Risiko
- Menghapus kartu live berarti metadata `sources` SSE hanya dipakai sebagai metadata internal, bukan renderer UI.
- Perubahan typewriter harus tetap menjaga fallback polling non-SSE.

## Langkah Implementasi
1. Pastikan `queueFinalStreamingText()` mengirim `final-content` mentah ke typewriter tanpa strip footer.
2. Hapus jalur reveal kartu `RUJUKAN` live setelah SSE selesai/persisted.
3. Hapus template kartu source live dari streaming bubble.
4. Pertahankan perilaku `queueAssistantTypewriterFinalText()` agar tidak reset pada final text yang hanya trim/lebih pendek dari buffer.
5. Tambahkan test kontrak UI untuk memastikan live tidak memecah footer rujukan menjadi kartu.

## Rencana Verifikasi
- `cd laravel && php artisan test --filter=ChatUiTest`
- `cd laravel && npm run build`
- `cd laravel && git diff --check`

## Kriteria Selesai
- SSE tidak menampilkan kartu `RUJUKAN` terpisah di bawah bubble.
- Footer `Dokumen rujukan:`/`Rujukan:` dari `final-content` tetap muncul di bubble live.
- Final-content tidak membuat typewriter terasa reset/terpotong untuk perubahan kecil.
- Verifikasi relevan lulus atau kegagalan dijelaskan.

## Follow-up: Race Polling vs SSE

### Temuan
Perbaikan referensi belum menyelesaikan typewriter 2x karena ada race berbeda:

- `wire:poll.3s="refreshPendingChatState"` tetap aktif selama pending conversation.
- SSE menyimpan assistant message ke database sebelum typewriter browser selesai mengetik.
- Jika polling berjalan di sela itu, Livewire memanggil `refreshPendingChatState()` tanpa `alreadyStreamedMessageId`.
- Backend menganggap jawaban selesai lewat fallback/background dan mengisi `$newMessageId`.
- Blade lalu merender assistant final dengan `assistantTypewriter`, sehingga user melihat typewriter live SSE lalu typewriter final output.

### Fix Lanjutan
1. Tambahkan marker backend bahwa conversation aktif sedang menunggu SSE live.
2. Saat polling biasa melihat completion untuk conversation yang sedang SSE, jangan set `$newMessageId`, jangan render bubble persisted, dan jangan dispatch `assistant-message-persisted` non-preserve untuk conversation itu.
3. Saat SSE `done` datang dengan `message-id`, preserve bubble live walaupun polling sebelumnya sudah menghapus conversation dari pending list.
4. Jika stream error/gagal, frontend melepas marker backend agar polling fallback bisa menampilkan error/jawaban final.

## Follow-up: Final-content Harus Masuk Live Sebelum Done

### Temuan
QA manual menunjukkan kasus timing baru:

- `final-content` SSE sudah membawa footer `Dokumen rujukan:` yang sama dengan isi DB.
- Frontend sebelumnya hanya menyimpan `streamState.finalText` saat event `final-content`.
- Footer baru dikirim ke typewriter saat event `done`.
- Di sela `final-content`/`message-id` sampai `done`, UI sudah bisa terlihat selesai dan action buttons sudah muncul, tetapi footer rujukan belum terlihat di bubble live.
- Setelah refresh, persisted renderer menampilkan footer tersebut, sehingga live dan final tetap terasa tidak sama.

### Fix Lanjutan
1. Saat event `final-content` diterima untuk conversation aktif, langsung panggil `queueFinalStreamingText()`.
2. Tandai `streamState.finalQueued` supaya event `done` tidak mengantrekan final text yang sama dua kali.
3. Pertahankan event `done` sebagai fallback bila `final-content` belum sempat di-queue.

## Follow-up: Layout Live SSE Harus Sama Dengan Final

### Temuan
QA visual berikutnya menunjukkan konten live SSE sudah benar, tetapi layout setelah typewriter masih terasa berbeda dari final setelah refresh:

- Wrapper streaming memakai spacing berbeda (`gap-4 px-2`) dari wrapper final (`gap-2 sm:gap-4 px-0 sm:px-8`).
- Parent live belum memakai `min-w-0` dan container action yang sama dengan final.
- Header live belum punya timestamp seperti message final.
- Action buttons bisa muncul sebelum render markdown final dan scroll benar-benar stabil.
- Scroll hanya bergantung pada `MutationObserver`, padahal tinggi bubble berubah terus selama typewriter dan saat markdown akhir dirender ulang.

### Fix Lanjutan
1. Samakan struktur Blade live SSE dengan struktur assistant final: avatar, header, bubble, container action, max-width, dan spacing.
2. Tambahkan label waktu live berbasis zona `Asia/Jakarta` agar header live tidak berubah bentuk setelah refresh.
3. Tahan action buttons sampai typewriter dan final layout settle.
4. Tambahkan `ResizeObserver` untuk bubble streaming supaya scroll mengikuti perubahan tinggi bubble sampai posisi akhir stabil.
5. Tambahkan final settle phase: render markdown final, tunggu frame layout, scroll ulang, lalu refresh/preserve Livewire state.
6. Kirim timestamp persisted message dari SSE/Livewire (`message-created-at`/`createdAt`) supaya header live memakai waktu yang sama dengan final setelah refresh.
7. Bersihkan stale warning begitu chunk atau `final-content` valid muncul, agar warning lama tidak tertinggal di bawah bubble final live.
