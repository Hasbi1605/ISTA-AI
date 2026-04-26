<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentChunk extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_id',
        'parent_id',
        'chunk_type',
        'parent_index',
        'child_index',
        'page_number',
        'text_content',
        'embedding',
        'embedding_model',
        'embedding_dimensions',
    ];

    protected function casts(): array
    {
        return [
            'embedding' => 'array',
            'embedding_dimensions' => 'integer',
            'chunk_type' => 'string',
            'parent_index' => 'integer',
            'child_index' => 'integer',
        ];
    }

    public function isParent(): bool
    {
        return $this->chunk_type === 'parent';
    }

    public function isChild(): bool
    {
        return $this->chunk_type === 'child';
    }

    public function parentChunk(): BelongsTo
    {
        return $this->belongsTo(DocumentChunk::class, 'parent_id');
    }

    public function childChunks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(DocumentChunk::class, 'parent_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
