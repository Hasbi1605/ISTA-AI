# Issue #90: LangSearch Web Search dan Rerank Langsung dari Laravel

## Tujuan
Memakai LangSearch dari Laravel untuk web search dan rerank tanpa melewati service Python.

## Scope
1. Adapter Laravel untuk `LANGSEARCH_API_KEY` dan `LANGSEARCH_API_KEY_BACKUP`
2. Web search realtime dengan freshness/count policy setara Python
3. Rerank hasil web dan dokumen memakai LangSearch rerank
4. Fallback/error handling saat LangSearch gagal atau limit
5. Source rendering untuk web result tetap kompatibel

## Implementasi

### 1. Konfigurasi (`config/ai.php`)
Tambah config untuk LangSearch:
- `langsearch.api_key`
- `langsearch.api_key_backup`
- `langsearch.api_url` 
- `langsearch.rerank_url`
- `langsearch.rerank_model`
- `langsearch.timeout`
- `langsearch.cache_ttl`

### 2. LangSearchService (`app/Services/LangSearchService.php`)
Buat service baru dengan method:
- `search(string $query, string $freshness = 'oneWeek', int $count = 5): array`
- `rerank(string $query, array $documents, ?int $top_n = null): array`
- `buildSearchContext(array $results): string` - untuk system prompt
- `_callWithFallback()` - helper untuk retry dengan backup key

### 3. Test
- Test web search berhasil dengan API key
- Test fallback ke backup key saat primary fail
- Test rerank berfungsi
- Test graceful error handling

## Acceptance Criteria
- [x] Web search berjalan dari Laravel tanpa Python
- [x] Rerank berjalan dari Laravel untuk dokumen/web sesuai kebutuhan
- [x] Backup key/fallback error teruji
- [x] Source metadata web tetap dirender di UI

## Risiko
- API rate limit perlu monitoring
- Cache strategy perlu tuning untuk production