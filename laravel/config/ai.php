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
        'document_process_enabled' => env('AI_DOCUMENT_PROCESS_ENABLED', true),
        'document_summarize_enabled' => env('AI_DOCUMENT_SUMMARIZE_ENABLED', true),
        'document_delete_enabled' => env('AI_DOCUMENT_DELETE_ENABLED', true),
        'document_retrieval_enabled' => env('AI_DOCUMENT_RETRIEVAL_ENABLED', true),
        'use_provider_file_search' => env('AI_USE_PROVIDER_FILE_SEARCH', true),
    ],

    'cascade' => [
        'enabled' => env('AI_CASCADE_ENABLED', true),
        'nodes' => [
            [
                'label' => 'GPT-4.1 (Primary)',
                'provider' => 'openai',
                'model' => 'gpt-4.1',
                'api_key' => env('GITHUB_TOKEN'),
                'base_url' => 'https://models.inference.ai.azure.com',
            ],
            [
                'label' => 'GPT-4.1 (Backup)',
                'provider' => 'openai',
                'model' => 'gpt-4.1',
                'api_key' => env('GITHUB_TOKEN_2'),
                'base_url' => 'https://models.inference.ai.azure.com',
            ],
            [
                'label' => 'GPT-4o (Primary)',
                'provider' => 'openai',
                'model' => 'gpt-4o',
                'api_key' => env('GITHUB_TOKEN'),
                'base_url' => 'https://models.inference.ai.azure.com',
            ],
            [
                'label' => 'GPT-4o (Backup)',
                'provider' => 'openai',
                'model' => 'gpt-4o',
                'api_key' => env('GITHUB_TOKEN_2'),
                'base_url' => 'https://models.inference.ai.azure.com',
            ],
            [
                'label' => 'Llama 3.3 70B (Groq)',
                'provider' => 'openai', 
                'model' => 'llama-3.3-70b-versatile',
                'api_key' => env('GROQ_API_KEY'),
                'base_url' => 'https://api.groq.com/openai/v1',
            ],
            [
                'label' => 'Gemini 3 Flash',
                'provider' => 'gemini',
                'model' => 'gemini-3-flash-preview',
                'api_key' => env('GEMINI_API_KEY'),
            ],
        ],
    ],

    'embedding_cascade' => [
        'enabled' => env('AI_EMBEDDING_CASCADE_ENABLED', true),
        'nodes' => [
            [
                'label' => 'Text Embedding 3 Large (Primary)',
                'provider' => 'openai',
                'model' => 'text-embedding-3-large',
                'dimensions' => 3072,
                'api_key' => env('GITHUB_TOKEN'),
                'base_url' => 'https://models.inference.ai.azure.com',
            ],
            [
                'label' => 'Text Embedding 3 Large (Backup)',
                'provider' => 'openai',
                'model' => 'text-embedding-3-large',
                'dimensions' => 3072,
                'api_key' => env('GITHUB_TOKEN_2'),
                'base_url' => 'https://models.inference.ai.azure.com',
            ],
            [
                'label' => 'Text Embedding 3 Small (Primary)',
                'provider' => 'openai',
                'model' => 'text-embedding-3-small',
                'dimensions' => 1536,
                'api_key' => env('GITHUB_TOKEN'),
                'base_url' => 'https://models.inference.ai.azure.com',
            ],
            [
                'label' => 'Text Embedding 3 Small (Backup)',
                'provider' => 'openai',
                'model' => 'text-embedding-3-small',
                'dimensions' => 1536,
                'api_key' => env('GITHUB_TOKEN_2'),
                'base_url' => 'https://models.inference.ai.azure.com',
            ],
        ],
    ],

    'rag' => [
        'top_k' => env('RAG_TOP_K', 5),
        'chunk_size' => env('RAG_CHUNK_SIZE', 1000),
        'chunk_overlap' => env('RAG_CHUNK_OVERLAP', 100),
        'embedding_model' => env('RAG_EMBEDDING_MODEL', 'text-embedding-3-small'),
        'embedding_dimensions' => env('RAG_EMBEDDING_DIMENSIONS', 1536),
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
