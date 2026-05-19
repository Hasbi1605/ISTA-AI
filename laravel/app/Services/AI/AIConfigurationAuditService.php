<?php

namespace App\Services\AI;

use App\Models\AIConfigAudit;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AIConfigurationAuditService
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        ?User $actor,
        Model $auditable,
        string $action,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        array $metadata = [],
    ): AIConfigAudit {
        return AIConfigAudit::create([
            'user_id' => $actor?->id,
            'auditable_type' => $auditable->getMorphClass(),
            'auditable_id' => $auditable->getKey(),
            'action' => $action,
            'before_snapshot' => $before,
            'after_snapshot' => $after,
            'reason' => $reason !== null ? mb_substr(trim($reason), 0, 500) : null,
            'metadata' => $metadata !== [] ? $metadata : null,
        ]);
    }
}
