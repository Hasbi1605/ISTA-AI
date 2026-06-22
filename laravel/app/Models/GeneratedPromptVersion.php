<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeneratedPromptVersion extends Model
{
    protected $fillable = [
        'generated_prompt_id',
        'version_number',
        'package',
        'revision_instruction',
        'reference_images',
        'model_label',
    ];

    protected function casts(): array
    {
        return [
            'package' => 'array',
            'reference_images' => 'array',
        ];
    }

    public function generatedPrompt(): BelongsTo
    {
        return $this->belongsTo(GeneratedPrompt::class);
    }

    /**
     * @return array{main_prompt: string, variants: list<string>, negative_prompt: string, recommended_settings: array<string, string>, notes_id: string}
     */
    public function normalizedPackage(): array
    {
        return GeneratedPrompt::normalizePackage($this->package);
    }
}
