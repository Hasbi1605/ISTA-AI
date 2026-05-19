<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AIPromptProfile extends Model
{
    protected $table = 'ai_prompt_profiles';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    public const FEATURE_CHAT = 'chat';

    public const FEATURE_DOCUMENT_RAG = 'document_rag';

    public const FEATURE_WEB_SEARCH = 'web_search';

    public const FEATURE_MEMO_GENERATION = 'memo_generation';

    public const FEATURE_KNOWLEDGE_INTERNAL = 'knowledge_internal';

    public const FEATURES = [
        self::FEATURE_CHAT,
        self::FEATURE_DOCUMENT_RAG,
        self::FEATURE_WEB_SEARCH,
        self::FEATURE_MEMO_GENERATION,
        self::FEATURE_KNOWLEDGE_INTERNAL,
    ];

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_ACTIVE,
        self::STATUS_ARCHIVED,
    ];

    protected $fillable = [
        'feature',
        'name',
        'system_prompt',
        'status',
        'version',
        'parent_id',
        'created_by',
        'activated_by',
        'activated_at',
        'archived_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'activated_at' => 'datetime',
            'archived_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function activator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}
