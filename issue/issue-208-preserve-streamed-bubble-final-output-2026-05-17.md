# Preserve Streamed Assistant Bubble as Final Output

## Latar Belakang
Setelah typewriter SSE selesai, frontend memanggil `refreshPendingChatState()` agar Livewire mengambil message assistant yang sudah tersimpan. Saat event `assistant-message-persisted` diterima, `chatMessages` masih mereset streaming state. Akibatnya bubble live SSE hilang dan diganti bubble persisted dari Blade, sehingga terlihat ada flicker singkat walaupun teksnya sama.

## Tujuan
- Bubble typewriter live yang pertama muncul tetap menjadi output final untuk percakapan aktif.
- Livewire tetap sinkron dengan message assistant yang tersimpan agar history chat berikutnya lengkap.
- Tidak ada duplicate bubble antara live SSE dan persisted render.
- Action buttons bisa muncul di bubble live setelah `message-id` diterima.

## Ruang Lingkup
- Tambah state Livewire untuk message assistant yang sedang dipreservasi dari streaming.
- Tambah mode preserve pada `refreshPendingChatState()`.
- Saat preserve aktif, sync message assistant ke `$messages`, tetapi Blade tidak merender bubble persisted-nya.
- Frontend mengirim flag preserve saat refresh SSE selesai dan tidak me-reset bubble live untuk ack yang sama.
- Streaming bubble mendapatkan action row setelah `streamedAssistantMessageId` tersedia.
- Tambah test kontrak backend dan frontend untuk preserve-stream flow.

## Di Luar Scope
- Tidak mengubah kontrak SSE backend utama.
- Tidak mengubah kualitas isi jawaban AI atau web search grounding.
- Tidak mengubah penyimpanan sources di database.

## Risiko
- `$messages` harus tetap berisi assistant agar history follow-up benar, meski render bubble persisted dilewati.
- Preserve state harus dibersihkan saat user mengirim pesan baru, pindah conversation, atau mulai chat baru.
- Streaming action row harus memakai HTML/typewriter yang sudah dirender browser, bukan markdown server.

## Langkah Implementasi
1. Tambahkan `$preservedStreamMessageId` di `ChatIndex`.
2. Bersihkan preserve state pada `loadConversation`, `startNewChat`, dan `sendMessage`.
3. Tambah helper backend untuk sync assistant message ke `$messages` tanpa `loadConversation()` penuh.
4. Tambah parameter preserve pada `refreshPendingChatState()` dan payload `assistant-message-persisted`.
5. Skip render persisted assistant bubble bila id sama dengan `$preservedStreamMessageId`.
6. Update JS agar refresh SSE memanggil preserve mode dan listener tidak reset bubble yang dipreservasi.
7. Tambah action row pada streaming bubble saat message sudah tersimpan.
8. Tambah/ubah test yang relevan.

## Rencana Verifikasi
- `cd laravel && php artisan test --filter=ChatUiTest`
- `cd laravel && npm run build`
- `cd laravel && vendor/bin/pint app/Livewire/Chat/ChatIndex.php resources/views/livewire/chat/partials/chat-messages.blade.php tests/Feature/Chat/ChatUiTest.php`
- `git diff --check`

## Kriteria Selesai
- Refresh setelah SSE tidak mengganti bubble live untuk message yang sama.
- Livewire state tetap berisi assistant message final untuk history.
- Tidak ada duplicate assistant bubble pada render preserve.
- Action copy/share/export tersedia pada bubble live setelah message tersimpan.
- Test dan build relevan lulus.
