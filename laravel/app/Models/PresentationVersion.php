<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PresentationVersion extends Model
{
    public const STATUS_GENERATED = 'generated';

    public const STATUS_EDITED = 'edited';

    protected $fillable = [
        'presentation_id',
        'version_number',
        'label',
        'pptx_path',
        'status',
    ];

    public function presentation(): BelongsTo
    {
        return $this->belongsTo(Presentation::class);
    }
}
