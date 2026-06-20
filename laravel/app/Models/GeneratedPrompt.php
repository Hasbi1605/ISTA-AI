<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Riwayat paket prompt Prompy Studio (epic #218, child #263).
 *
 * Milik user (private). Menyimpan ide asli, paket prompt hasil generate, dan
 * metadata reference image privat. Tidak pernah memanggil platform eksternal.
 */
class GeneratedPrompt extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'platform',
        'platform_label',
        'prompt_type',
        'prompt_type_label',
        'title',
        'idea',
        'package',
        'source_document_ids',
        'contains_internal_context',
        'reference_image_path',
        'reference_image_mime',
        'reference_image_size_bytes',
        'model_label',
    ];

    protected function casts(): array
    {
        return [
            'package' => 'array',
            'source_document_ids' => 'array',
            'contains_internal_context' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOwnedBy(?User $user): bool
    {
        return $user !== null && (int) $user->id === (int) $this->user_id;
    }

    /**
     * @return array{main_prompt: string, variants: list<string>, negative_prompt: string, recommended_settings: array<string, string>, notes_id: string}
     */
    public function normalizedPackage(): array
    {
        $package = is_array($this->package) ? $this->package : [];

        return [
            'main_prompt' => (string) ($package['main_prompt'] ?? ''),
            'variants' => array_values(array_filter(
                is_array($package['variants'] ?? null) ? $package['variants'] : [],
                fn ($v) => is_string($v) && trim($v) !== '',
            )),
            'negative_prompt' => (string) ($package['negative_prompt'] ?? ''),
            'recommended_settings' => is_array($package['recommended_settings'] ?? null)
                ? $package['recommended_settings']
                : [],
            'notes_id' => (string) ($package['notes_id'] ?? ''),
        ];
    }
}
