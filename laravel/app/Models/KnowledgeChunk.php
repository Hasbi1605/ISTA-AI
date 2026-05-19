<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $knowledge_document_id
 * @property int $chunk_count
 * @property int $successful_chunks
 * @property int $failed_chunks
 * @property string|null $embedding_provider
 * @property array<string, mixed>|null $summary
 * @property \Illuminate\Support\Carbon|null $last_synced_at
 */
class KnowledgeChunk extends Model
{
    use HasFactory;

    protected $fillable = [
        'knowledge_document_id',
        'chunk_count',
        'successful_chunks',
        'failed_chunks',
        'embedding_provider',
        'summary',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'chunk_count' => 'integer',
            'successful_chunks' => 'integer',
            'failed_chunks' => 'integer',
            'summary' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class, 'knowledge_document_id');
    }
}
