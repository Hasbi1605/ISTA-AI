<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Presentation extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    public const STATUS_ERROR = 'error';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PROCESSING,
        self::STATUS_READY,
        self::STATUS_ERROR,
    ];

    protected $fillable = [
        'user_id',
        'title',
        'status',
        'visual_template',
        'configuration',
        'outline',
        'source_document_ids',
        'pptx_path',
        'pdf_path',
        'error_message',
        'generated_at',
        'current_version_id',
    ];

    protected function casts(): array
    {
        return [
            'configuration' => 'array',
            'outline' => 'array',
            'source_document_ids' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    public function isOwnedBy(?User $user): bool
    {
        return $user !== null && (int) $user->id === (int) $this->user_id;
    }

    /**
     * Saring daftar id dokumen sumber menjadi hanya dokumen milik user dengan
     * status "ready". Dipakai sebagai single guard sebelum dokumen dipakai
     * untuk generate presentasi (fail-closed: id asing/belum ready dibuang).
     *
     * @param  iterable<mixed>  $documentIds
     * @return list<int>
     */
    public static function sanitizeSourceDocumentIds(int $userId, iterable $documentIds): array
    {
        $requested = collect($documentIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        if ($requested->isEmpty()) {
            return [];
        }

        return Document::query()
            ->where('user_id', $userId)
            ->where('status', 'ready')
            ->whereIn('id', $requested->all())
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * True jika seluruh id dokumen yang diminta valid (milik user & ready).
     *
     * @param  iterable<mixed>  $documentIds
     */
    public static function sourceDocumentsOwnedAndReady(int $userId, iterable $documentIds): bool
    {
        $requested = collect($documentIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        $allowed = self::sanitizeSourceDocumentIds($userId, $requested);

        return $requested->count() === count($allowed);
    }
}
