<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AIConfigAudit extends Model
{
    protected $table = 'ai_config_audits';

    public const ACTION_CREATED = 'created';

    public const ACTION_UPDATED = 'updated';

    public const ACTION_TESTED = 'tested';

    public const ACTION_ACTIVATED = 'activated';

    public const ACTION_ARCHIVED = 'archived';

    public const ACTION_ROLLED_BACK = 'rolled_back';

    protected $fillable = [
        'user_id',
        'auditable_type',
        'auditable_id',
        'action',
        'before_snapshot',
        'after_snapshot',
        'reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'before_snapshot' => 'array',
            'after_snapshot' => 'array',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
