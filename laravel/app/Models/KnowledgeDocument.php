<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $knowledge_source_id
 * @property int|null $uploaded_by_id
 * @property string $title
 * @property string $original_name
 * @property string $filename
 * @property string $file_path
 * @property string|null $mime_type
 * @property int|null $file_size_bytes
 * @property string|null $checksum_sha256
 * @property string $scope
 * @property string $audience
 * @property string $status
 * @property string|null $processing_claim_token
 * @property string $vector_namespace
 * @property array<string, mixed>|null $metadata
 * @property string|null $notes
 * @property Carbon|null $processed_at
 * @property Carbon|null $archived_at
 * @property Carbon|null $failed_at
 * @property string|null $error_code
 * @property string|null $error_message
 */
class KnowledgeDocument extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ERROR = 'error';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PROCESSING,
        self::STATUS_ACTIVE,
        self::STATUS_ERROR,
        self::STATUS_ARCHIVED,
    ];

    public const SCOPE_GLOBAL_INTERNAL = 'global_internal';

    public const AUDIENCE_ALL_USERS = 'all_users';

    public const VECTOR_NAMESPACE = 'knowledge';

    /**
     * Synthetic user_id stored in Chroma metadata so knowledge vectors do not
     * collide with personal documents (which use real user ids).
     */
    public const KNOWLEDGE_USER_ID = '__knowledge__';

    protected $fillable = [
        'knowledge_source_id',
        'uploaded_by_id',
        'title',
        'original_name',
        'filename',
        'file_path',
        'mime_type',
        'file_size_bytes',
        'checksum_sha256',
        'scope',
        'audience',
        'status',
        'processing_claim_token',
        'vector_namespace',
        'metadata',
        'notes',
        'processed_at',
        'archived_at',
        'failed_at',
        'error_code',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'file_size_bytes' => 'integer',
            'processed_at' => 'datetime',
            'archived_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(KnowledgeSource::class, 'knowledge_source_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    public function chunks(): HasOne
    {
        return $this->hasOne(KnowledgeChunk::class);
    }

    public function isActivatable(): bool
    {
        return in_array($this->status, [self::STATUS_ARCHIVED, self::STATUS_ERROR], true);
    }

    public function isArchivable(): bool
    {
        return in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_DRAFT, self::STATUS_ERROR], true);
    }

    public function isReprocessable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_ARCHIVED, self::STATUS_ERROR], true);
    }

    protected function formattedSize(): Attribute
    {
        return Attribute::make(
            get: function () {
                $bytes = $this->file_size_bytes;

                if ($bytes === null || $bytes < 1) {
                    return 'Ukuran tidak tersedia';
                }

                if ($bytes >= 1073741824) {
                    return number_format($bytes / 1073741824, 2).' GB';
                }

                if ($bytes >= 1048576) {
                    return number_format($bytes / 1048576, 1).' MB';
                }

                return number_format(max($bytes / 1024, 0.1), 1).' KB';
            }
        );
    }

    protected function extension(): Attribute
    {
        return Attribute::make(
            get: fn () => strtolower((string) pathinfo((string) $this->original_name, PATHINFO_EXTENSION))
        );
    }
}
