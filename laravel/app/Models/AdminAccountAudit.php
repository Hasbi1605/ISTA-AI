<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminAccountAudit extends Model
{
    use HasFactory;

    public const ACTION_LOGIN_SUCCESS = 'admin_login_success';

    public const ACTION_LOGIN_FAILED = 'admin_login_failed';

    public const ACTION_LOGOUT = 'admin_logout';

    public const ACTION_CREATED = 'admin_created';

    public const ACTION_UPDATED = 'admin_updated';

    public const ACTION_ACTIVATED = 'admin_activated';

    public const ACTION_DEACTIVATED = 'admin_deactivated';

    public const ACTION_PASSWORD_RESET = 'admin_password_reset';

    public const ACTION_ROLE_CHANGED = 'admin_role_changed';

    protected $fillable = [
        'actor_id',
        'target_user_id',
        'action',
        'ip_address',
        'user_agent',
        'before_snapshot',
        'after_snapshot',
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

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }
}
