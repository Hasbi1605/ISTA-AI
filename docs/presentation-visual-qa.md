# Presentasi ISTA AI — Visual QA & Safety Baseline (#225)

Dokumen ini menetapkan baseline QA visual dan keputusan keamanan/aset untuk
fitur generator presentasi (epic #218). Berlaku untuk renderer Python
(`python-ai/app/services/presentation_render.py`) dan pipeline Laravel.

## Keputusan Baseline

1. **Asset mode = `local_assets_only` (MVP).**
   Seluruh elemen visual (logo emblem, ikon, bilah aksen, header/footer) digambar
   sebagai shape vektor PPTX secara deterministik. Tidak ada pengambilan gambar
   atau ikon dari internet. Enrichment aset web ditunda ke #227.
   Konstanta acuan: `app/services/presentation_assets.py::ASSET_MODE`.

2. **Model AI hanya menyusun outline, bukan keputusan desain.**
   Renderer hanya membaca `title`, `bullets`/`points`/`content`, dan `layout`
   dari tiap item outline. Field lain (warna, font, template) diabaikan, sehingga
   model murah/penyusun outline tidak bisa "mengarang" tampilan. Kualitas visual
   sepenuhnya berasal dari design token per template.

3. **Batas konten ditegakkan di kode (bukan harapan):**
   - `BULLET_MAX_CHARS = 240` (bullet dipangkas + elipsis).
   - `MAX_BULLETS_PER_SLIDE = 7` (kelebihan dibuang).
   - `SLIDE_TITLE_MAX_CHARS = 120`, `TITLE_MAX_CHARS = 160`.
   - `MAX_CONTENT_SLIDES = 18`; `slide_count` di-clamp `3..20`.
   - Outline kosong → tetap menghasilkan cover + 1 slide fallback + closing.
   - Sisi Laravel (`PresentationGenerationService::normalizeConfiguration` /
     `buildOutline`) memangkas arahan tambahan (≤2000 char) dan poin (≤220 char,
     maksimal 12) sebelum dikirim ke renderer.

## Lima Template Visual

| Key | Label | Cover style |
|-----|-------|-------------|
| `resmi_klasik` | Resmi Klasik | band |
| `modern_minimal` | Modern Minimal | minimal |
| `executive_brief` | Executive Brief | split |
| `data_tabel` | Data & Tabel | sidebar |
| `kegiatan_dokumentasi` | Kegiatan & Dokumentasi | frame |

Tiap template memakai palette (primary/accent/background/surface/text/muted) yang
diterapkan konsisten ke cover, header, bilah aksen, dan footer.

## Checklist QA Visual (manual, per template)

Untuk tiap dari 5 template, buka PPTX hasil dan periksa:

- [ ] **Cover**: logo emblem, teks `Istana Kepresidenan Yogyakarta`, judul, subtitle,
      meta (audiens/penyusun/unit/tanggal) — tidak terpotong, kontras cukup.
- [ ] **Slide konten**: header (logo kecil + teks) di atas, judul + bilah aksen,
      bullet rapi (≤7, tidak overflow), footer (teks + nomor halaman) di bawah.
- [ ] **Slide data/tabel** (jika dipakai): angka/daftar terbaca, tidak menumpuk.
- [ ] **Closing**: "Terima Kasih", brand, penyusun/unit, footer.
- [ ] **Konsistensi**: warna primary & accent template terlihat seragam antar slide.
- [ ] **No overflow**: tidak ada teks/elemen keluar kanvas 16:9.

Workspace (Livewire):
- [ ] Mode terang & gelap rapi (form, daftar riwayat, badge status, tombol download).
- [ ] Responsif mobile/desktop, form tidak overflow.

Tanpa jaringan:
- [ ] Render berhasil dengan koneksi internet dimatikan (aset tidak diambil dari web).

## Verifikasi Otomatis

```bash
# Python (renderer + QA baseline)
cd python-ai && source venv/bin/activate && pytest -k 'presentation'

# Laravel (pipeline, file/PDF, workspace, konfigurasi/outline limits)
cd laravel && php artisan test --filter=Presentation
```

Cakupan test otomatis QA (`python-ai/tests/test_presentation_qa.py`):
- render 5 template tanpa membuka socket jaringan;
- palette primary & accent benar-benar dipakai per template;
- logo/header/footer hadir di cover/konten/closing;
- batas bullet/slide/title + truncation ditegakkan;
- field outline tak dikenal (warna/font) diabaikan (model tidak menyetir desain);
- semua shape berada dalam batas kanvas 16:9 (no structural overflow).
