# Security and Privacy

Dokumen ini merangkum proteksi dan risiko privasi ISTA AI. Detail alur data
lebih lengkap ada di `docs/data-flow-privacy.md`.

## Prinsip Utama

- Data produksi tidak disimpan di repo.
- File dokumen disimpan private, bukan public disk.
- Akses dokumen/memo memakai auth, policy, signed URL, dan server-side authorization.
- Python AI hanya menerima request internal dengan bearer token.
- Prompt, isi dokumen, dan secret tidak boleh ditulis ke log.
- Keputusan penggunaan provider eksternal perlu disetujui pemilik data.

## Proteksi User dan Dokumen

- Route chat, dokumen, dan memo berada di belakang auth + active + verified.
- Akun nonaktif diputus dari route terproteksi.
- Dokumen owner-scoped.
- Preview HTML memakai jalur khusus dan sanitizer/sandbox sesuai implementasi.
- Delete dokumen ikut membersihkan vector berdasarkan `filename` dan `user_id`.

## Proteksi Admin

- Admin memakai `/admin/login` terpisah.
- Login gagal memakai pesan generik dan lockout progresif.
- Admin dapat dipaksa mengganti password sebelum masuk panel.
- 2FA TOTP digunakan untuk route admin utama.
- Trusted device disimpan dengan token hashed.
- Sesi admin punya absolute lifetime.
- Aksi akun admin diaudit di `admin_account_audits`.

## Proteksi Python AI

- Semua endpoint internal penting memakai bearer token.
- Token diverifikasi constant-time.
- Health/ready endpoint membantu memastikan token/path runtime siap.
- Chroma tidak dipublish sebagai port publik di compose production.

## OnlyOffice

- OnlyOffice berjalan self-hosted sebagai document server.
- File memo dibuka melalui signed URL sementara.
- Callback memakai JWT.
- URL internal/publik OnlyOffice perlu disesuaikan saat migrasi domain/server.

## Provider Eksternal

Tergantung fitur yang dipakai, data berikut dapat dikirim ke provider AI/search:

- prompt user dan history chat aktif;
- chunk dokumen relevan untuk RAG;
- teks chunk dokumen untuk embedding;
- instruksi memo;
- query web search;
- ide, instruksi revisi, dokumen acuan, dan reference image Prompy.

Provider dikontrol oleh env secret dan `python-ai/config/ai_config.yaml`.

## Risiko yang Perlu Disepakati

- Dokumen rahasia bisa ikut terkirim ke provider eksternal saat RAG, embedding, summary, memo, atau Prompy.
- Fallback model dapat memindahkan pemrosesan dari provider utama ke provider cadangan.
- Chroma menyimpan representasi/chunk dokumen dan perlu diperlakukan sebagai data sensitif.
- Retensi data belum menggantikan kebijakan arsip resmi.

## Catatan Demo dan Handover

Untuk demo, paling aman memakai data dummy atau data yang sudah disetujui. Mentor
atau pemilik data perlu mengetahui provider AI/search yang aktif karena sebagian
fitur dapat mengirim prompt, chunk dokumen, reference image, atau query web ke
provider eksternal.

Pada deployment private, registrasi mandiri tetap ditutup dan akun admin disiapkan
dengan 2FA. Jika data production perlu dipertahankan, backup database, storage,
dan Chroma perlu dipahami sebagai satu paket karena ketiganya saling melengkapi
saat restore.
