# ISTA AI Configuration Documentation

## Overview

File konfigurasi terpusat untuk menyederhanakan pergantian nilai operasional AI tanpa mengubah logika inti.

## File Konfigurasi

### Python AI Service
- **Lokasi**: `python-ai/config/ai_config.yaml`
- **Modul Loader**: `python-ai/app/config_loader.py`

### Laravel Backend
- **Lokasi**: `laravel/config/ai.php`
- **Catatan**: File ini hanya untuk koneksi service AI dari Laravel ke Python. Persona dan prompt aktif dibaca dari `python-ai/config/ai_config.yaml -> prompts.*`.

## Struktur Konfigurasi

### 1. Global Settings
```yaml
global:
  timeout: 30              # Timeout umum dalam detik
  connect_timeout: 10      # Timeout koneksi
  read_timeout: 120        # Timeout read
  retry_attempts: 2        # Jumlah percobaan ulang
  retry_delay_ms: 400      # Delay antar retry (ms)
```

### 2. Lanes - Chat
```yaml
lanes:
  chat:
    models:
      - label: "GPT-5 Chat (Primary)"
        provider: "litellm"
        model_name: "openai/gpt-5-chat"
        api_key_env: "GITHUB_TOKEN"
        base_url: "https://models.inference.ai.azure.com"
```
- Urutan menentukan prioritas fallback
- Model pertama = primary, lanjut ke bawah jika gagal

### 3. Lanes - Reasoning
```yaml
lanes:
  reasoning:
    model: null  # Default null = lane tidak aktif
```
- Aktifkan dengan mengisi objek model
- Contoh aktivasi:
```yaml
model:
  label: "DeepSeek R1"
  provider: "litellm"
  model_name: "deepseek/deepseek-reasoner"
  api_key_env: "DEEPSEEK_API_KEY"
```

### 4. Lanes - Embedding
```yaml
lanes:
  embedding:
    models:
      - name: "GitHub Models (OpenAI Large) - Primary"
        provider: "github"
        model: "text-embedding-3-large"
        api_key_env: "GITHUB_TOKEN"
        tpm_limit: 500000
        dimensions: 3072
```

### 5. Retrieval - Search
```yaml
retrieval:
  search:
    enabled: true
    api_url: "https://api.langsearch.com/v1/web-search"
    timeout: 10
    cache_ttl: 300
    default_count: 5
    default_freshness: "oneWeek"
```

### 6. Retrieval - Semantic Rerank
```yaml
retrieval:
  semantic_rerank:
    enabled: true
    api_url: "https://api.langsearch.com/v1/rerank"
    model: "langsearch-reranker-v1"
    timeout: 8
```

### 7. Prompt Aktif
```yaml
prompts:
  system:
    default: |
      Anda adalah ISTA AI, asisten kerja internal untuk pegawai Istana Kepresidenan Yogyakarta.
```

### 8. Chunking
```yaml
chunking:
  token_chunk_size: 1500
  token_chunk_overlap: 150
  aggressive_batch_size: 200
  batch_delay_seconds: 0.5
  embedding_timeout: 30
```

### 9. Integrations - SMTP Gmail
```yaml
integrations:
  smtp_gmail:
    host: "smtp.gmail.com"
    port: 587
    encryption: "tls"
    username: "terry.delvon0805@gmail.com"
    password_env: "MAIL_PASSWORD"  # Dari environment variable
```

## Cara Menggunakan di Kode Python

```python
from app.config_loader import (
    get_config,
    get_chat_models,
    get_embedding_models,
    get_search_config,
    get_reasoning_model,
)

# Ambil semua config
config = get_config()

# Ambil chat models
chat_models = get_chat_models()
for model in chat_models:
    api_key = os.getenv(model["api_key_env"])
    # ...

# Cek reasoning aktif
reasoning = get_reasoning_model()
if reasoning:
    # Gunakan model reasoning
    pass

# Ambil search config
search = get_search_config()
timeout = search.get("timeout", 10)
```

## Cara Menggunakan di Laravel

```php
use Illuminate\Support\Facades\Config;

// Ambil nilai dari config
$timeout = config('ai.global.timeout');
$url = config('ai.lanes.chat.url');

// Atau langsung dari env
$aiUrl = config('services.ai_service.url');
```

## Referensi Model Tersedia

Berikut daftar lengkap model yang tersedia untuk digunakan. Gunakan Ctrl+F untuk mencari model yang diinginkan.

### Chat Models
- ai21-labs/ai21-jamba-1.5-large
- microsoft/phi-4-multimodal-instruct
- microsoft/phi-4-mini-instruct
- openai/o4-mini
- openai/o3
- openai/o1-mini
- openai/gpt-5-nano
- openai/gpt-5-chat
- openai/gpt-4o-mini
- microsoft/phi-4-reasoning
- microsoft/phi-4-mini-reasoning
- openai/o3-mini
- openai/o1-preview
- openai/gpt-5-mini
- openai/gpt-5
- openai/gpt-4o
- openai/gpt-4.1-nano
- openai/gpt-4.1
- openai/gpt-4.1-mini
- meta/meta-llama-3.1-8b-instruct
- meta/meta-llama-3.1-405b-instruct
- microsoft/mai-ds-r1
- meta/llama-3.3-70b-instruct
- meta/llama-3.2-90b-vision-instruct
- meta/llama-3.2-11b-vision-instruct
- meta/llama-4-scout-17b-16e-instruct
- meta/llama-4-maverick-17b-128e-instruct-fp8
- cohere/cohere-command-r-plus-08-2024
- cohere/cohere-command-r-08-2024
- cohere/cohere-command-a
- mistral-ai/mistral-small-2503
- mistral-ai/codestral-2501
- mistral-ai/mistral-medium-2505
- mistral-ai/ministral-3b
- deepseek/deepseek-v3-0324
- deepseek/deepseek-r1-0528
- deepseek/deepseek-r1
- xai/grok-3-mini
- xai/grok-3

### Embedding Models
- openai/text-embedding-3-small
- openai/text-embedding-3-large

### Reasoning Models
- openai/o1-mini
- openai/o1-preview
- openai/o3-mini
- openai/o3
- openai/o4-mini
- microsoft/phi-4-reasoning
- microsoft/phi-4-mini-reasoning
- deepseek/deepseek-r1
- deepseek/deepseek-r1-0528
- deepseek/deepseek-v3-0324
- microsoft/mai-ds-r1

## Catatan Keamanan

1. **Password tidak di-hardcode** - Selalu gunakan env variable (`MAIL_PASSWORD`, `AI_SERVICE_TOKEN`, dll)
2. **API Keys** - Disimpan di environment, bukan di file config
3. **File `.env`** - Jangan-commit ke repository

## Fallback Strategy

- Urutan model di config menentukan prioritas fallback
- Jika model pertama gagal, sistem otomatis coba model berikutnya
- Embedding menggunakan cascading 4-tier (2M TPM total capacity)
- Search dan Rerank memiliki backup API keys

## Troubleshooting

### Jika model tidak mau loading:
1. Cek `api_key_env` sesuai dengan nama environment variable
2. Pastikan API key ada di file `.env`
3. Cek log untuk error message

### Jika search/rerank tidak works:
1. Cek `enabled: true`
2. Cek API key terinstall
3. Cek network/firewall

## Untuk Junior Programmer

1. **Mengganti model chat**: Edit `lanes.chat.models` - ubah `model_name` atau `api_key_env`
2. **Mengganti embedding**: Edit `lanes.embedding.models`
3. **Mengganti search provider**: Edit `retrieval.search.api_url`
4. **Mengganti SMTP**: Edit `integrations.smtp_gmail` + update `.env`

Semua perubahan tidak perlu ubah logika kode!

---

## Cara Mengganti Prompt AI

### Lokasi File Prompt

Semua prompt AI disimpan di file `python-ai/config/ai_config.yaml` di bagian `prompts:`.
Key legacy `system.default_prompt` sudah deprecated dan tidak dipakai lagi.

### Struktur Prompt

```yaml
prompts:
  system:
    default: "Anda adalah ISTA AI..."

  rag:
    document: |
      Template prompt RAG...
    no_answer: |
      Prompt saat jawaban tidak ditemukan pada dokumen aktif

  web_search:
    context: |
      Template context web...

    assertive_instruction: |
      Template instruction...

  summarization:
    single: |
      Template single...
    partial: |
      Template partial...
    final: |
      Template final...

  fallback:
    document_not_found: "Prompt saat jawaban tidak ada di dokumen aktif"
    document_error: "Prompt saat konteks dokumen gagal dibaca"
```

### Cara Mengganti Prompt

#### 1. Ganti System Prompt (Identitas AI)

```yaml
prompts:
  system:
    default: "Anda adalah asisten virtual istana yang siap membantu..."
```

#### 2. Ganti RAG Prompt (Chat dengan Dokumen)

Ganti atau tambahkan instruksi khusus untuk respons saat chatting dengan dokumen:

```yaml
prompts:
  rag:
    document: |
      Anda adalah asisten AI yang membantu menjawab dari dokumen.
      
      DOKUMEN:
      {context_str}
      {web_section}
      
      PERTANYAAN: {question}
      
     Tulis jawaban dengan format markdown jika perlu.
      Jawaban:
```

Template variables yang tersedia:
- `{context_str}` - Isi dokumen dari RAG
- `{web_section}` - Konteks hasil pencarian web (jika ada)
- `{question}` - Pertanyaan user

#### 2a. Ganti Prompt Saat Jawaban Tidak Ada di Dokumen

```yaml
prompts:
  rag:
    no_answer: |
      Saya belum menemukan jawaban yang diminta pada dokumen yang sedang aktif.
      Jika Anda berkenan, saya bisa membantu melanjutkan dengan web search atau pengetahuan umum.
```

#### 3. Ganti Web Search Context

```yaml
prompts:
  web_search:
    context: |
      INFO TERBARU DARI WEB:
      Tanggal: {current_date}
      Tahun: {current_year}
      
      {results}
      
      Gunakan info di atas untuk menjawab pertanyaan terkini.
```

Template variables:
- `{current_date}` - Tanggal hari ini
- `{current_year}` - Tahun saat ini
- `{results}` - Hasil pencarian web yang sudah diformat

#### 4. Ganti Assertive Instruction

```yaml
prompts:
  web_search:
    assertive_instruction: |
      Tambahan instruksi:
      - Prioritaskan fakta dari web
      - Sertakan sumber jika memungkinkan
      - Jawaban singkat dan padat
```

#### 5. Ganti Summarization Prompts

```yaml
prompts:
  summarization:
    single: |
      Ringkaskan dokumen berikut dalam Bahasa Indonesia maksimal 500 kata:
      {document}
      
      Ringkasan:
    
    partial: |
      Ringkasan bagian {part_number} dari {total_parts}:
      {batch}
      
      Ringkasan singkat (maksimal 100 kata):
    
    final: |
      Gabungkan ringkasan berikut menjadi satu:
      {combined_summaries}
      
      Ringkasan final:
```

Template variables:
- `{document}` - Isi dokumen lengkap (single)
- `{batch}` - Bagian dokumen (partial)
- `{part_number}` - Nomor bagian (1, 2, 3, ...)
- `{total_parts}` - Total jumlah bagian
- `{combined_summaries}` - Semua ringkasan bagian digabungkan (final)

### Contoh Lengkap Mengganti Prompt

#### Contoh 1: Ubah Personality AI

**Sebelum:**
```yaml
prompts:
  system:
    default: "Prompt lama persona ISTA AI"
```

**Sesudah:**
```yaml
prompts:
  system:
    default: "Anda adalah ISTA AI, asisten kerja internal untuk pegawai Istana Kepresidenan Yogyakarta."
```

#### Contoh 2: Ubah Cara RAG Merespons

**Sebelum:**
```yaml
prompts:
  rag:
    document: |
      Prompt RAG lama yang masih generik.
```

**Sesudah:**
```yaml
prompts:
  rag:
    document: |
      Anda adalah ISTA AI, asisten kerja internal untuk pegawai Istana Kepresidenan Yogyakarta.
      Utamakan informasi yang tertulis eksplisit pada dokumen aktif.
      Jangan menebak detail yang tidak tertulis.
      Jangan membuat daftar sumber di akhir jawaban.
```

### Fallback Mechanism

Jika prompt di config kosong atau key tidak ditemukan, sistem akan menggunakan default dari `config_loader.py`:

1. Cek config file (`ai_config.yaml`)
2. Jika kosong, gunakan `DEFAULT_PROMPTS` di `config_loader.py`
3. Ada logging warning saat fallback aktif (bisa dilihat di terminal)

### Testing Perubahan Prompt

1. Edit prompt di `ai_config.yaml`
2. Restart server Python:
   ```bash
   cd python-ai
   # restart aplikasi
   ```
3. Test dengan chatting atau upload dokumen
4. Cek terminal untuk melihat log warning (jika ada)

### Catatan Penting

1. **Restart Required** - Perubahan prompt butuh restart aplikasi
2. **YAML Format** - Perhatikan indentasi dan format YAML
3. **Template Variables** - Gunakan variabel seperti `{context_str}`, `{question}`, dll
4. **Multi-line** - Gunakan `|` untuk prompt multi-baris
5. **Fallback** - Jika ada ошибка, sistem akan otomatis gunakan default
