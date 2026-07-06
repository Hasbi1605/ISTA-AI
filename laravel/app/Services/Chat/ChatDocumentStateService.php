<?php

namespace App\Services\Chat;

use App\Models\Document;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class ChatDocumentStateService
{
    private const CACHE_TTL_SECONDS = 4;

    /**
     * @return array{documents: Collection<int, Document>, has_documents_in_progress: bool}
     */
    public function loadAvailableDocuments(int $userId, bool $forceRefresh = false): array
    {
        if ($userId <= 0) {
            return [
                'documents' => collect(),
                'has_documents_in_progress' => false,
            ];
        }

        $cacheKey = $this->cacheKey($userId);

        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        /** @var array{documents: Collection<int, Document>, has_documents_in_progress: bool} $state */
        $state = Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, fn () => $this->fetchAvailableDocuments($userId));

        return $state;
    }

    public function invalidateAvailableDocumentsCache(int $userId): void
    {
        if ($userId > 0) {
            Cache::forget($this->cacheKey($userId));
        }
    }

    /**
     * @return array{documents: Collection<int, Document>, has_documents_in_progress: bool}
     */
    private function fetchAvailableDocuments(int $userId): array
    {
        $documents = Document::query()
            ->where('user_id', $userId)
            ->select([
                'id',
                'user_id',
                'original_name',
                'status',
                'file_size_bytes',
                'mime_type',
                'created_at',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return [
            'documents' => $documents,
            'has_documents_in_progress' => $documents->contains(function (Document $document) {
                return in_array($document->status, ['pending', 'processing'], true);
            }),
        ];
    }

    /**
     * @return array<int, int>
     */
    public function readyDocumentIds(int $userId): array
    {
        return Document::where('user_id', $userId)
            ->where('status', 'ready')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->toArray();
    }

    /**
     * @param  array<int, int|string>  $selectedDocuments
     * @return array<int, int>
     */
    public function toggleDocument(array $selectedDocuments, int|string $documentId): array
    {
        $documentId = (int) $documentId;
        $selectedIds = $this->normalizeDocumentIds($selectedDocuments);

        if (in_array($documentId, $selectedIds, true)) {
            return array_values(array_filter($selectedIds, fn (int $id) => $id !== $documentId));
        }

        $selectedIds[] = $documentId;

        return array_values(array_unique($selectedIds));
    }

    /**
     * @param  array<int, int|string>  $documentIds
     * @param  array<int, int|string>|int  $addedDocumentIds
     * @return array<int, int>
     */
    public function addDocumentIds(array $documentIds, array|int $addedDocumentIds): array
    {
        return array_values(array_unique(array_merge(
            $this->normalizeDocumentIds($documentIds),
            $this->normalizeDocumentIds((array) $addedDocumentIds),
        )));
    }

    /**
     * @return array<int, int>
     */
    public function selectAllReadyDocuments(int $userId): array
    {
        return $this->readyDocumentIds($userId);
    }

    /**
     * @param  array<int, int|string>  $selectedDocuments
     * @param  array<int, int|string>  $readyDocumentIds
     * @return array<int, int>
     */
    public function toggleSelectAllDocuments(array $selectedDocuments, array $readyDocumentIds): array
    {
        $readyIds = $this->normalizeDocumentIds($readyDocumentIds);
        $selectedIds = $this->normalizeDocumentIds($selectedDocuments);

        sort($readyIds);
        sort($selectedIds);

        if (! empty($readyIds) && $selectedIds === $readyIds) {
            return [];
        }

        return $readyIds;
    }

    /**
     * @param  array<int, int|string>  $selectedDocuments
     * @param  array<int, int|string>  $availableDocumentIds
     * @return array<int, int>
     */
    public function filterSelectedDocuments(array $selectedDocuments, array $availableDocumentIds): array
    {
        $availableMap = array_flip($this->normalizeDocumentIds($availableDocumentIds));

        return array_values(array_filter(
            $this->normalizeDocumentIds($selectedDocuments),
            fn (int $id) => isset($availableMap[$id]),
        ));
    }

    /**
     * @param  array<int, int|string>  $selectedDocuments
     * @param  array<int, int|string>  $readyDocumentIds
     * @return array<int, int>
     */
    public function addSelectedDocumentsToChat(array $selectedDocuments, array $readyDocumentIds): array
    {
        return $this->filterSelectedDocuments($selectedDocuments, $readyDocumentIds);
    }

    /**
     * @param  array<int, int|string>  $documentIds
     * @param  array<int, int|string>|int  $removedDocumentIds
     * @return array<int, int>
     */
    public function removeDocumentIds(array $documentIds, array|int $removedDocumentIds): array
    {
        $removedIds = $this->normalizeDocumentIds((array) $removedDocumentIds);

        return array_values(array_filter(
            $this->normalizeDocumentIds($documentIds),
            fn (int $id) => ! in_array($id, $removedIds, true),
        ));
    }

    /**
     * @param  array<int, int|string>  $documentIds
     * @return array<int, int>
     */
    private function normalizeDocumentIds(array $documentIds): array
    {
        return array_values(array_map('intval', $documentIds));
    }

    private function cacheKey(int $userId): string
    {
        return "ista:chat:documents:{$userId}";
    }
}
