# =====================================================================
# Stage 1: Builder
# Tahap ini mengkompilasi semua Python wheel dari requirements.txt.
# Build tools (gcc, g++, dll) HANYA ada di stage ini, tidak di image final.
# =====================================================================
FROM python:3.11-slim AS builder

WORKDIR /wheels

# Install build tools (hanya untuk kompilasi, tidak akan ada di image final)
RUN apt-get update && apt-get install -y --no-install-recommends \
    build-essential \
    curl \
    libmagic-dev \
    && rm -rf /var/lib/apt/lists/*

# Copy dan kompilasi semua dependency jadi wheel files
COPY requirements.txt .
RUN pip wheel --no-cache-dir --wheel-dir=/wheels -r requirements.txt


# =====================================================================
# Stage 2: Runtime (image final yang dijalankan di server)
# Image ini TIDAK mengandung compiler/build tools → lebih kecil & aman.
# =====================================================================
FROM python:3.11-slim

WORKDIR /app

# Install hanya runtime dependencies (bukan build tools)
# - libmagic1: untuk python-magic (deteksi tipe file)
# - poppler-utils: untuk pdf2image (konversi PDF ke gambar)
# - tesseract-ocr: untuk OCR dokumen
# - curl: untuk health check
RUN apt-get update && apt-get install -y --no-install-recommends \
    libmagic1 \
    poppler-utils \
    tesseract-ocr \
    curl \
    && rm -rf /var/lib/apt/lists/*

# Salin wheel files dari Builder stage
COPY --from=builder /wheels /wheels
COPY requirements.txt .

# Install semua package dari wheel yang sudah dikompilasi (lebih cepat, tidak perlu internet)
RUN pip install --no-cache-dir --no-index --find-links=/wheels -r requirements.txt \
    # Hapus wheel files setelah install untuk hemat disk
    && rm -rf /wheels

# Salin source code aplikasi
COPY . .

# Expose FastAPI port
EXPOSE 8001

# =====================================================================
# Jalankan dengan 2 uvicorn workers untuk concurrency ringan:
# - --workers 2: 2 proses paralel = lebih responsif saat ada request bersamaan
# - --timeout-keep-alive 75: koneksi streaming SSE tidak di-close terlalu cepat
# =====================================================================
CMD ["uvicorn", "app.main:app", \
     "--host", "0.0.0.0", \
     "--port", "8001", \
     "--workers", "2", \
     "--timeout-keep-alive", "75"]
