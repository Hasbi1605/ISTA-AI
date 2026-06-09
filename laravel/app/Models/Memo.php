<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Memo extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_GENERATED = 'generated';

    public const STATUS_EDITED = 'edited';

    public const STATUS_FINALIZED = 'finalized';

    public const TYPES = [
        'memo_internal' => 'Memo Internal',
        'nota_dinas' => 'Nota Dinas',
        'arahan' => 'Arahan',
    ];

    protected $fillable = [
        'user_id',
        'title',
        'memo_type',
        'file_path',
        'current_version_id',
        'status',
        'source_conversation_id',
        'source_document_ids',
        'searchable_text',
        'chat_messages',
        'configuration',
    ];

    protected function casts(): array
    {
        return [
            'source_document_ids' => 'array',
            'chat_messages' => 'array',
            'configuration' => 'array',
        ];
    }

    protected function typeLabel(): Attribute
    {
        return Attribute::make(
            get: fn () => self::TYPES[$this->memo_type] ?? $this->memo_type,
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(MemoVersion::class);
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(MemoVersion::class, 'current_version_id');
    }
}
