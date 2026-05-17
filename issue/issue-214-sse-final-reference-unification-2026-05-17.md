# SSE Final Reference Unification

## Latar Belakang
Typewriter SSE sudah memakai bubble yang sama dengan jawaban final, tetapi pada web search final text masih menambahkan footer `Rujukan:` ke dalam bubble sementara source cards SSE juga tampil di bawahnya. Saat `final-content` sedikit berbeda dari teks yang sudah diketik, helper typewriter juga bisa melakukan reset yang terlihat seperti jawaban terpotong.

## Tujuan
- Streaming SSE dan final state tetap memakai satu bubble live tanpa flicker.
- Footer rujukan markdown dari `final-content` tidak tampil ganda ketika source cards SSE tersedia.
- Source cards SSE dideduplikasi sebelum ditampilkan.
- Typewriter tidak reset penuh ketika final text hanya berbeda pada whitespace atau lebih pendek sedikit dari buffer yang sudah diketik.

## Ruang Lingkup
- `laravel/resources/js/chat-page.js`
- Test kontrak UI chat di `laravel/tests/Feature/Chat/ChatUiTest.php`

## Di Luar Scope
- Tidak mengubah kualitas jawaban AI, retrieval, atau prompt.
- Tidak mengubah format penyimpanan message di database.
- Tidak menambah migrasi untuk menyimpan sources secara terstruktur.

## Risiko
- Regex strip footer harus hanya aktif saat source cards tersedia agar jawaban lama tanpa metadata tetap menampilkan rujukan.
- Dedupe URL tidak boleh menghapus dokumen berbeda yang punya nama sama secara tidak sengaja.
- Perubahan typewriter harus tetap menjaga fallback polling non-SSE.

## Langkah Implementasi
1. Tambahkan normalizer source SSE dengan dedupe berdasarkan URL atau filename.
2. Tambahkan helper untuk menghapus footer rujukan dari final text hanya saat source cards tersedia.
3. Ubah `queueFinalStreamingText()` agar memakai final text yang sudah dinormalisasi.
4. Perhalus `queueAssistantTypewriterFinalText()` agar tidak reset pada final text yang hanya trim/lebih pendek dari buffer.
5. Tambahkan test kontrak UI untuk memastikan helper normalisasi tersedia dan dipakai.

## Rencana Verifikasi
- `cd laravel && php artisan test --filter=ChatUiTest`
- `cd laravel && npm run build`
- `cd laravel && git diff --check`

## Kriteria Selesai
- SSE web search tidak menampilkan `Rujukan:` di bubble sekaligus source cards di bawahnya.
- Source cards tidak duplikat untuk URL/dokumen yang sama.
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
