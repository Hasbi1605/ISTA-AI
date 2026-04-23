<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Service Configuration
    |--------------------------------------------------------------------------
    |
    | Konfigurasi terpusat untuk layanan AI. Ubah nilai di sini untuk
    | pergantian operasional tanpa mengubah logika inti.
    |
    */

    'global' => [
        'timeout' => env('AI_TIMEOUT', 30),
        'connect_timeout' => env('AI_CONNECT_TIMEOUT', 10),
        'read_timeout' => env('AI_READ_TIMEOUT', 120),
        'retry_attempts' => env('AI_RETRY_ATTEMPTS', 2),
        'retry_delay_ms' => env('AI_RETRY_DELAY_MS', 400),
    ],

    'lanes' => [
        'chat' => [
            'url' => env('AI_SERVICE_URL', 'http://localhost:8001'),
            'token' => env('AI_SERVICE_TOKEN'),
        ],
    ],

    'laravel_ai' => [
        'model' => env('AI_MODEL', 'gpt-4o-mini'),
        'api_key' => env('OPENAI_API_KEY'),
        'web_search' => [
            'enabled' => env('AI_WEB_SEARCH_ENABLED', true),
            'provider' => env('AI_WEB_SEARCH_PROVIDER', 'ddg'),
        ],
        'document_process_enabled' => env('AI_DOCUMENT_PROCESS_ENABLED', false),
        'document_summarize_enabled' => env('AI_DOCUMENT_SUMMARIZE_ENABLED', false),
        'document_delete_enabled' => env('AI_DOCUMENT_DELETE_ENABLED', true),
        'document_retrieval_enabled' => env('AI_DOCUMENT_RETRIEVAL_ENABLED', false),
        'use_provider_file_search' => env('AI_USE_PROVIDER_FILE_SEARCH', false),
    ],

    'rag' => [
        'top_k' => env('RAG_TOP_K', 5),
        'chunk_size' => env('RAG_CHUNK_SIZE', 1000),
        'chunk_overlap' => env('RAG_CHUNK_OVERLAP', 100),
        'embedding_model' => env('RAG_EMBEDDING_MODEL', 'text-embedding-3-small'),
    ],

    'prompts' => [
        'rag' => <<<'PROMPT'
Anda adalah asisten AI yang menjawab berdasarkan dokumen yang diberikan.

Jika menjawab berdasarkan dokumen, gunakan informasi dari konteks di bawah ini. 
Jangan membuat informasi yang tidak ada di dokumen.

KONTEKS DOKUMEN:
{context_str}
{web_section}

Pertanyaan: {question}

JAWABAN:
PROMPT
        ,
        'no_answer' => 'Saya belum menemukan informasi tersebut pada dokumen yang sedang aktif. Jika Anda berkenan, saya bisa melanjutkan dengan web search atau pengetahuan umum.',
        'document_error' => 'Saya belum bisa membaca konteks dari dokumen yang dipilih saat ini. Jika Anda berkenan, saya bisa melanjutkan dengan web search atau pengetahuan umum.',
    ],

];
