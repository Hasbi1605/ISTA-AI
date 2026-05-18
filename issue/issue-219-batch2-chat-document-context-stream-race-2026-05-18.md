# Batch 2 Chat Document Context and Stream Conversation Guard

## Latar Belakang
Audit Batch 2 menemukan dua risiko pada flow chat:

- Jika user message menyimpan `document_ids`, tetapi dokumen tersebut kemudian tidak lagi `ready`, backend mengirim request AI tanpa konteks dokumen dan otomatis jatuh ke mode general/web. User bisa mengira AI membaca dokumen, padahal dokumen dilewati.
- Event `done` dari SSE membawa `streamedMessageId`, tetapi Livewire `refreshPendingChatState()` belum menerima conversation id stream. Saat user pindah conversation sebelum stream selesai, refresh/preserve state berpotensi dipasangkan ke conversation aktif yang berbeda.

## Tujuan
- Menolak flow AI saat user memang meminta konteks dokumen tetapi tidak ada dokumen siap pakai.
- Menampilkan error chat yang jelas, bukan jawaban general/web.
- Menjaga preserve bubble streaming hanya terjadi untuk conversation tempat stream berasal.
- Tetap mempertahankan behavior normal untuk chat tanpa dokumen atau dokumen ready.

## Ruang Lingkup
- Resolusi konteks dokumen chat di Laravel.
- SSE stream controller dan background job fallback.
- Livewire `ChatIndex::refreshPendingChatState()`.
- Frontend `chat-page.js` untuk mengirim `streamConversationId`.
- Test feature chat dan build frontend.

## Di Luar Scope
- Perubahan Google Drive shortcut.
- Hardening parse document id size.
- Perubahan Python AI/RAG.
- Refactor besar UI chat.

## Area / File Terkait
- `laravel/app/Services/ChatOrchestrationService.php`
- `laravel/app/Http/Controllers/Chat/ChatStreamController.php`
- `laravel/app/Jobs/GenerateChatResponse.php`
- `laravel/app/Livewire/Chat/ChatIndex.php`
- `laravel/resources/js/chat-page.js`
- `laravel/tests/Feature/Chat/ChatStreamTest.php`
- `laravel/tests/Feature/Chat/ChatUiTest.php`

## Risiko
- Stream dan job fallback harus sama-sama menulis satu error message idempotent.
- Chat tanpa dokumen tidak boleh ikut terblokir.
- Dokumen sebagian ready tetap boleh memakai subset ready, tetapi perlu tahu apakah user memilih dokumen yang tidak tersedia.
- Perubahan signature method Livewire harus kompatibel dengan polling lama yang tidak mengirim parameter baru.

## Langkah Implementasi
1. Perluas hasil `getActiveDocumentContext()` agar membawa `requested_ids`, `unavailable_ids`, dan flag `has_unavailable`.
2. Tambahkan helper service untuk menentukan apakah konteks dokumen wajib dihentikan dan pesan error user-facing.
3. Di `ChatStreamController`, sebelum memanggil AI, simpan error dan kirim SSE `error/done` jika semua dokumen yang diminta tidak tersedia.
4. Di `GenerateChatResponse`, lakukan guard yang sama agar fallback job tidak menghasilkan jawaban general/web.
5. Tambahkan parameter opsional `streamConversationId` di `refreshPendingChatState()` dan gunakan untuk preserve/sync hanya jika sama dengan conversation aktif.
6. Ubah `chat-page.js` agar mengirim conversation id stream ke Livewire.
7. Tambahkan/ubah test regresi untuk dokumen unavailable dan stream race.

## Rencana Test
- `cd laravel && ./vendor/bin/pint --test ...`
- `cd laravel && php artisan test --filter='ChatStreamTest|ChatUiTest|OnlyOfficeCallbackTest|MemoPolicyTest'`
- `cd laravel && npm run build`
- Deploy production dan smoke check `/up`.

## Kriteria Selesai
- Chat dengan selected document yang sudah tidak ready tidak memanggil AI general/web.
- Error assistant tersimpan dan conversation disentuh agar UI polling melihatnya.
- Stream completion dari conversation inactive tidak mem-preserve bubble di conversation aktif.
- Test targeted dan build frontend lulus.
- Production berhasil deploy dan container sehat.
