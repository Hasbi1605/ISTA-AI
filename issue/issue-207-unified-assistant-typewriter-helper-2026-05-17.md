# Unified Assistant Typewriter Helper

## Latar Belakang
Perbaikan sebelumnya membuat SSE tidak lagi menimpa output secara instan, tetapi engine typewriter masih tersebar:
- SSE memakai method internal di `chatMessages`.
- Persisted latest message memakai inline `x-data` di Blade dan mengetik HTML hasil markdown server.

Perbedaan ini membuat feel chat biasa, web search, dokumen, dan fallback polling masih berpotensi tidak konsisten.

## Tujuan
- Membuat satu helper Alpine `assistantTypewriter` untuk semua jawaban yang sedang/baru diketik.
- SSE streaming dan persisted latest message memakai engine queue/render yang sama.
- Persisted latest message mengetik raw markdown, bukan HTML.
- Static message lama tetap server-rendered agar risiko rendah.
- Inline typewriter lama di Blade dihapus.

## Ruang Lingkup
- Tambah factory/helper typewriter bersama di `resources/js/chat-page.js`.
- Adaptasi `chatMessages` SSE agar memakai helper bersama melalui wrapper kompatibilitas.
- Tambah partial Blade kecil untuk assistant bubble typed/static.
- Ganti inline Blade typewriter lama dengan `assistantTypewriter`.
- Tambah/ubah assertion test kontrak UI.

## Di Luar Scope
- Tidak mengubah kontrak backend SSE.
- Tidak membuat migrasi database untuk menyimpan sources terstruktur.
- Tidak mengubah rendering static message lama selain memindahkan bubble ke partial.
- Tidak mengubah kualitas isi jawaban AI.

## Area / File Terkait
- `laravel/resources/js/chat-page.js`
- `laravel/resources/views/livewire/chat/partials/chat-messages.blade.php`
- `laravel/resources/views/livewire/chat/partials/assistant-answer-bubble.blade.php`
- `laravel/tests/Feature/Chat/ChatUiTest.php`

## Risiko
- Alpine helper harus tetap kompatibel dengan `chatMessages` yang sudah memiliki lifecycle `init()`.
- Raw markdown typed rendering harus tetap disanitasi di browser.
- Static rendered messages tidak boleh kehilangan action buttons.

## Langkah Implementasi
1. Tambahkan `createAssistantTypewriterState()` sebagai engine queue/render reusable.
2. Register `Alpine.data('assistantTypewriter')` untuk persisted latest message.
3. Ubah `chatMessages` agar memakai engine yang sama melalui wrapper method lama.
4. Extract assistant bubble typed/static ke partial kecil.
5. Ganti inline Blade typewriter lama dengan helper baru.
6. Perbarui test kontrak.

## Rencana Test
- `php artisan test --filter=ChatUiTest`
- `npm run build`
- `vendor/bin/pint resources/views/livewire/chat/partials/chat-messages.blade.php resources/views/livewire/chat/partials/assistant-answer-bubble.blade.php tests/Feature/Chat/ChatUiTest.php`
- `git diff --check`

## Kriteria Selesai
- `assistantTypewriter` menjadi engine typed answer untuk SSE dan persisted latest message.
- Inline `typewriterEffect()` lama tidak ada lagi di Blade.
- SSE tetap menunda refresh sampai typewriter selesai.
- Test dan build relevan lulus.
