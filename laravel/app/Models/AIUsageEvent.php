<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $feature
 * @property string $action
 * @property string $status
 * @property string|null $request_id
 * @property int|null $subject_id
 * @property string|null $subject_type
 * @property int|null $latency_ms
 * @property string|null $error_code
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class AIUsageEvent extends Model
{
    use HasFactory;

    protected $table = 'ai_usage_events';

    public const FEATURE_CHAT = 'chat';

    public const FEATURE_WEB_SEARCH = 'web_search';

    public const FEATURE_DOCUMENT_RAG = 'document_rag';

    public const FEATURE_DOCUMENT_UPLOAD = 'document_upload';

    public const FEATURE_DOCUMENT_PROCESSING = 'document_processing';

    public const FEATURE_MEMO_GENERATION = 'memo_generation';

    public const FEATURE_MEMO_REVISION = 'memo_revision';

    public const FEATURE_KNOWLEDGE_ADMIN = 'knowledge_admin';

    public const FEATURE_PRESENTATION_GENERATION = 'presentation_generation';

    public const FEATURE_PROMPT_GENERATION = 'prompt_generation';

    public const ACTION_STARTED = 'started';

    public const ACTION_COMPLETED = 'completed';

    public const ACTION_FAILED = 'failed';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_ERROR = 'error';

    public const STATUS_BLOCKED = 'blocked';

    protected $fillable = [
        'user_id',
        'feature',
        'action',
        'status',
        'request_id',
        'subject_id',
        'subject_type',
        'latency_ms',
        'error_code',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'latency_ms' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
