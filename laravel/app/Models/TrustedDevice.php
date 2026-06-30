<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrustedDevice extends Model
{
    protected $fillable = ['user_id', 'device_hash', 'device_name', 'trusted_until'];

    protected function casts(): array
    {
        return [
            'trusted_until' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->trusted_until->isPast();
    }
}
