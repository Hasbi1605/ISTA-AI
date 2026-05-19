# Admin Knowledge dan AI Configuration UI Polish

## Tujuan
Memperbaiki halaman `/admin/knowledge` dan `/admin/ai-config` agar lebih konsisten dengan tab admin lain, lebih rapi dalam dark mode, dan lebih jelas sebagai workflow operasional:

- Knowledge tampil sebagai pipeline dokumen internal, bukan sekadar form upload.
- AI Configuration tampil sebagai control panel aman dengan alur draft, preview, activate, dan audit.

## Scope
- Rapikan layout, spacing, table, empty state, status chip, form control, dan focus ring.
- Tambahkan selector fitur tunggal pada AI Configuration dan sinkronkan form prompt/model/playground.
- Tampilkan status runtime AI Configuration secara jelas saat masih fallback env.
- Pindahkan upload Knowledge ke drawer/modal agar halaman utama fokus pada daftar dokumen.
- Tambahkan pipeline status dan chunk count pada tabel Knowledge.
- Pakai warna icon file dari komponen dokumen yang sudah ada.

## Non-Scope
- Tidak mengubah business logic retrieval, processing, model runtime, atau secret handling.
- Tidak menyalakan `AI_CONFIG_DB_ENABLED` di production.
- Tidak mengubah struktur database.

## Risiko
- Perubahan Blade bisa memengaruhi test rendering admin.
- Upload Livewire harus tetap bisa dipakai meski form dipindah ke modal.
- Query Knowledge harus eager-load chunk agar tidak menambah N+1.

## Verifikasi
- `php artisan test --filter='AdminKnowledgeManagementTest|AdminLayoutTest|AdminAccessTest|AIConfiguration'`
- `npm run build`
- Jika perlu, render halaman lokal untuk cek visual light/dark.
