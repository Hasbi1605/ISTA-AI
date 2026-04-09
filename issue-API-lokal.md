## Plan: Integrasi API Lokal ISTA AI

Gunakan API lokal Indonesia secara selektif untuk memperkuat ISTA AI sebagai asisten Istana Kepresidenan Yogyakarta. Prioritaskan API yang menambah akurasi, konteks lokal, dan informasi aktual, bukan sekadar fitur hiburan.

**Steps**

1. Tetapkan scope integrasi awal ke 5 domain utama: cuaca/bencana, wilayah & lokasi, bahasa/KBBI, berita, dan konteks kalender nasional. Ini adalah baseline paling bernilai untuk use case operasional dan pertanyaan umum.
2. Petakan setiap domain ke satu atau dua API yang paling stabil dan minim autentikasi. Hindari menambah banyak API dengan fungsi tumpang tindih; pilih satu sumber utama dan satu cadangan jika diperlukan.
3. Rancang layer tool/service di backend agar ISTA AI memanggil API hanya saat intent pengguna relevan. Jangan masukkan semua API ke prompt utama.
4. Terapkan caching untuk data yang jarang berubah atau sering dipakai, seperti wilayah, kode pos, hari libur nasional, dan referensi bahasa/KBBI.
5. Tambahkan guardrail untuk sumber data: gunakan API publik sebagai pelengkap, tetapi jawaban sensitif atau resmi tetap harus menyebut sumber dan batasannya.
6. Siapkan fallback jika API gagal, lambat, atau memerlukan kunci. ISTA AI tetap harus memberi jawaban yang aman, singkat, dan jujur tentang keterbatasan data.
7. Setelah fase awal stabil, barulah pertimbangkan API tambahan untuk konteks budaya/yogyakarta, misalnya batik, bahasa daerah, dan data historis yang relevan.

**Recommended API priorities**

- Cuaca/Bencana: BMKG Weather, Data BMKG, Info Gempa & Cuaca.
- Wilayah/Lokasi: API Wilayah Indonesia, Kode Pos, Places API Indonesia, alamat/GeoJSON wilayah.
- Bahasa/KBBI: KBBI API, KBBI Complete, New KBBI API, Bahasa Daerah.
- Berita: API Berita Indonesia, Berita Indo API, CNN Indonesia, The Lazy Media API.
- Kalender nasional: Dayoff API.
- Konteks istana/budaya opsional: Batik Indonesia, Pahlawan Nasional Indonesia.

**Implementation notes**

- Buat satu service layer per domain, bukan per API.
- Pilih endpoint yang paling mudah di-maintain dan paling sedikit autentikasi.
- Simpan configuration API key, base URL, timeout, dan retry di config/env.
- Log error dan latency agar API yang tidak stabil cepat diketahui.
- Siapkan dokumentasi intent kapan API dipanggil dan kapan tidak.

**Non-goals**

- Tidak mengintegrasikan semua API dari daftar.
- Tidak membangun sistem yang terlalu bergantung pada sumber non-resmi jika ada alternatif resmi yang cukup.
- Tidak mengubah arsitektur chat inti di tahap ini.
