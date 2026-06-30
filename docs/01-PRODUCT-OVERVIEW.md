# Product Overview ISTA AI

## Tujuan Produk

ISTA AI membantu pegawai bekerja dengan dokumen privat melalui chat AI,
retrieval dokumen, pembuatan memo, knowledge internal, dan prompt engineering
terarah. Fokus produk adalah self-hosted workflow, akses terbatas, dan jejak
operasional yang bisa diaudit.

## Pengguna

| Pengguna | Kebutuhan utama |
| --- | --- |
| Staff user | Chat atas dokumen privat, upload dokumen, generate/edit memo, membuat prompt dengan Prompy Studio |
| Admin | Memantau usage/error, mengelola user, dokumen, dan knowledge base |
| Super-admin | Mengelola akun admin dan audit admin account |

## Fitur User

- **Chat AI streaming**: jawaban mengalir token-by-token melalui SSE.
- **Document RAG**: user upload PDF/DOCX/XLSX/CSV, sistem membuat chunk dan embedding, lalu chat dapat menjawab dari dokumen aktif.
- **Internal knowledge**: knowledge base yang dikurasi admin dapat dipakai untuk jawaban umum internal.
- **Web search augmentation**: query tertentu dapat memakai LangSearch untuk konteks eksternal.
- **Memo**: user membuat draf memo DOCX, menyimpan versi, mengedit via OnlyOffice, dan export/download.
- **Prompy Studio**: user membuat paket prompt untuk platform eksternal. Reference image dan dokumen acuan dapat dipakai sebagai konteks internal.

## Fitur Admin

- Dashboard KPI.
- Usage analytics dari `ai_usage_events`.
- Error monitoring AI.
- Manajemen dokumen dan status pipeline ingest.
- Manajemen user dan presence.
- Manajemen knowledge source/document.
- Manajemen admin account khusus super-admin.
- Audit perubahan admin account.

## Batas Sistem Saat Ini

- Tidak ada Google Drive import/export aktif.
- Tidak ada generator PPTX internal aktif.
- Tidak ada akses publik untuk chat/dokumen/memo.
- Chat memakai SSE, bukan WebSocket.
- Konfigurasi AI terpusat di `python-ai/config/ai_config.yaml`, bukan database admin UI.

## Keamanan Utama

- File privat di storage non-public dan route berotorisasi.
- Python AI dilindungi bearer token internal.
- OnlyOffice memakai signed URL dan JWT.
- Admin login terpisah dengan lockout progresif, forced password change, 2FA TOTP, trusted device, session lifetime absolut, dan audit log.
- Production default menutup registrasi mandiri.
