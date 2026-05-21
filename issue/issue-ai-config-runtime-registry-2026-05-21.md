# Rombak AI Config Runtime Registry

## Latar Belakang
Halaman AI Configuration saat ini hanya mengelola draft prompt/model sederhana. Runtime utama masih membaca `python-ai/config/ai_config.yaml`, sedangkan tabel Laravel belum di-bootstrap dari konfigurasi existing sehingga halaman admin menampilkan banyak status kosong. Struktur model config juga hanya mendukung satu primary dan satu fallback, padahal runtime sekarang memakai ordered cascade banyak model, embedding, search, rerank, HyDE, memo, dan knowledge internal.

## Tujuan
- Menampilkan seluruh konfigurasi AI existing sebagai baseline yang bisa diaudit admin.
- Membuat super admin bisa mengatur urutan model/fallback tanpa menghapus daftar model yang sudah ada.
- Membuat prompt existing tampil dan bisa dibuat draft/diaktifkan untuk kebutuhan berikutnya.
- Menjaga secret tetap aman: UI memakai alias env/credential, bukan raw API key.
- Menjaga sistem tidak rusak: YAML/env tetap fallback bila DB runtime belum enabled atau konfigurasi DB belum lengkap.

## Ruang Lingkup
- Backend Laravel registry/catalog untuk model route, prompt default, credential status, dan snapshot runtime.
- Resolver runtime yang tetap kompatibel dengan `runtime_config` lama namun mendukung ordered model chain dan prompt per mode.
- Livewire admin AI config dirombak agar menampilkan model route, prompt, retrieval, credential alias, dan audit.
- Python AI membaca runtime payload baru untuk chat/RAG/knowledge/memo/summarization/top-k sejauh aman tanpa mengubah fallback YAML.
- Prompt memo generation, knowledge internal, dan HyDE dipindahkan ke YAML/config loader agar baseline prompt runtime konsisten dan tetap punya fallback aman.
- Test Laravel dan Python untuk backward compatibility dan runtime DB.

## Di Luar Scope
- Menyimpan raw API key dari UI.
- Menghapus `python-ai/config/ai_config.yaml`.
- Mengubah provider/model aktif default saat `AI_CONFIG_DB_ENABLED=false`.
- Deploy production atau merge PR.

## Area / File Terkait
- `laravel/app/Services/AI/*`
- `laravel/app/Livewire/Admin/AdminAIConfig.php`
- `laravel/resources/views/livewire/admin/admin-ai-config.blade.php`
- `laravel/database/migrations/*ai_configuration*`
- `laravel/config/services.php`
- `python-ai/app/llm_manager.py`
- `python-ai/app/chat_api.py`
- `python-ai/app/services/*`
- `python-ai/config/ai_config.yaml`
- Test admin Laravel dan test prompt/runtime Python.

## Risiko
- Perbedaan source of truth antara YAML dan DB dapat membuat admin salah melihat konfigurasi aktif.
- Top-k/runtime prompt yang salah diterapkan bisa mengubah perilaku RAG.
- Route knowledge internal diputuskan di Python, sehingga payload dari Laravel harus tetap aman dan tidak memaksa mode yang salah.
- Audit tidak boleh menyimpan secret.

## Langkah Implementasi
1. Tambahkan katalog default di Laravel yang merepresentasikan konfigurasi YAML saat ini.
2. Tambahkan service snapshot/runtime untuk membangun baseline dan payload DB secara backward-compatible.
3. Perluas model config agar bisa menyimpan ordered fallback chain di metadata tanpa memutus kolom lama.
4. Seed/ensure default prompt profiles dari prompt existing saat halaman admin dibuka atau service dipakai.
5. Rombak UI agar fokus pada route model, prompt existing, retrieval summary, credential alias/status, dan audit; hapus playground.
6. Perluas Python agar menerima `runtime_config.chat_models`, `runtime_config.prompts`, dan `runtime_config.retrieval_top_k`, namun tetap fallback YAML jika tidak ada.
7. Pindahkan prompt memo generation, knowledge internal, dan HyDE ke `python-ai/config/ai_config.yaml` serta expose getter di `config_loader.py`.
8. Tambahkan test Laravel/Python untuk katalog, route chain, prompt default, prompt YAML operasional, dan RAG top-k runtime.

## Rencana Test
- Laravel targeted: `php artisan test --filter=AIConfigurationTest`
- Laravel layout/access targeted bila UI berubah: `php artisan test --filter=AdminLayoutTest`
- Python targeted: `pytest tests/test_llm_streaming.py tests/test_chat_api_concurrency.py tests/test_prompt_contracts.py`
- Jika perubahan menyentuh runtime luas, jalankan subset tambahan yang relevan.

## Kriteria Selesai
- Halaman admin menampilkan baseline model/prompt existing meskipun DB runtime masih fallback env.
- Super admin bisa menyimpan draft route model berurutan dan mengaktifkannya.
- Runtime DB tetap hanya aktif saat `AI_CONFIG_DB_ENABLED=true`.
- Secret tidak tersimpan di DB/audit dan tidak ditampilkan raw di UI.
- Test relevan lulus atau kegagalan dijelaskan jelas.
