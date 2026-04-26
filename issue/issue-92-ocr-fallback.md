# Issue #92: OCR/Scanned PDF Fallback Tanpa Python Service

## Tujuan
Mengimplementasikan fallback OCR/scanned PDF menggunakan vision model via GitHub Models agar decommission Python tidak menurunkan kemampuan membaca PDF scan.

## Scope
- Deteksi PDF digital vs PDF scan
- Untuk PDF digital, tetap ekstrak teks langsung dengan parser Laravel/PHP
- Untuk PDF scan, render halaman PDF menjadi PNG/JPEG
- Kirim image page ke `openai/gpt-4.1` atau `openai/gpt-4o` via GitHub Models untuk OCR vision
- Minta output teks terstruktur beserta metadata halaman
- Simpan hasil OCR sebagai chunk dokumen untuk pipeline RAG Laravel
- Jika GitHub Models vision gagal/limit, fallback ke Gemini/free-tier jika tersedia
- Jika provider vision tidak tersedia, evaluasi fallback lokal gratis seperti Tesseract/system OCR
- Integrasikan fallback OCR ke pipeline ingest Laravel
- Pastikan OCR hanya berjalan saat parsing normal tidak menghasilkan teks memadai

## Acceptance Criteria
- PDF scan fixture bisa diproses tanpa menjalankan service Python
- Jalur utama OCR memakai `openai/gpt-4.1` atau `openai/gpt-4o` via GitHub Models jika token tersedia
- Fallback OCR tersedia untuk kasus GitHub Models vision gagal/limit: Gemini/free-tier jika tersedia atau Tesseract/system OCR lokal
- Hasil OCR disimpan sebagai chunk dokumen yang bisa dipakai RAG
- Metadata halaman/source tetap cukup untuk rendering `[SOURCES:...]`
- Error OCR jelas di UI/log jika semua fallback gagal
- Ada test untuk PDF digital, PDF scan success, provider vision failure, dan OCR fallback

## Implementation Steps

### Step 1: Add OCR Configuration to ai.php
- Add `vision_cascade` config with GPT-4.1 and GPT-4o nodes via GitHub Models
- Add Gemini as fallback
- Add Tesseract/system OCR as last fallback

### Step 2: Create PdfScannerDetector
- Detect if PDF contains extractable text or is scanned
- Return boolean indicating if PDF is scanned

### Step 3: Create PdfToImageRenderer
- Render PDF pages to PNG/JPEG images
- Support configurable DPI and image quality for OCR

### Step 4: Create VisionOcrService
- Use vision models (GPT-4.1, GPT-4o) to extract text from images
- Support cascade fallback between providers
- Return structured text with page metadata

### Step 5: Create TesseractOcrService (Fallback)
- System OCR fallback using Tesseract
- Document OS dependencies

### Step 6: Create OcrOrchestrator
- Orchestrate OCR pipeline:
  1. Detect if PDF is scanned
  2. If scanned, render pages to images
  3. Try vision cascade (GPT-4.1, GPT-4o, Gemini)
  4. Fallback to Tesseract if vision fails
- Return extracted text with page metadata

### Step 7: Modify ProcessDocument Job
- Catch "no text" exception from PdfParser
- Trigger OCR fallback via OcrOrchestrator
- Integrate OCR results into chunk pipeline

### Step 8: Write Tests
- Test PDF digital detection
- Test PDF scan detection
- Test OCR success flow
- Test OCR cascade fallback
- Test error handling

## Files to Create
- `laravel/app/Services/Document/Parsing/PdfScannerDetector.php`
- `laravel/app/Services/Document/Parsing/PdfToImageRenderer.php`
- `laravel/app/Services/Document/Ocr/VisionOcrService.php`
- `laravel/app/Services/Document/Ocr/TesseractOcrService.php`
- `laravel/app/Services/Document/Ocr/OcrOrchestrator.php`

## Files to Modify
- `laravel/config/ai.php` - Add vision_cascade config
- `laravel/app/Jobs/ProcessDocument.php` - Integrate OCR fallback
- `laravel/app/Services/Document/Parsing/PdfParser.php` - Detect scanned PDF

## Risk & Considerations
- Rendering PDF to images may be resource-intensive for large PDFs
- Vision API costs may be higher than text extraction
- Need to limit page count or use sampling for large documents
- User isolation must be maintained (OCR results must not leak across users)
