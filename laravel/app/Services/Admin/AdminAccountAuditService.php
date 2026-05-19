<?php

namespace App\Services\Admin;

use App\Models\AdminAccountAudit;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminAccountAuditService
{
    /**
     * Record an admin audit log entry. Never persists secrets such as passwords.
     */
    public function record(
        string $action,
        ?User $actor = null,
        ?User $target = null,
        ?array $before = null,
        ?array $after = null,
        ?array $metadata = null,
        ?Request $request = null,
    ): ?AdminAccountAudit {
        $request ??= request();

        try {
            return AdminAccountAudit::create([
                'actor_id' => $actor?->getKey(),
                'target_user_id' => $target?->getKey(),
                'action' => $action,
                'ip_address' => $request?->ip(),
                'user_agent' => substr((string) ($request?->userAgent() ?? ''), 0, 512) ?: null,
                'before_snapshot' => $before ? $this->sanitize($before) : null,
                'after_snapshot' => $after ? $this->sanitize($after) : null,
                'metadata' => $metadata ? $this->sanitize($metadata) : null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to write admin account audit', [
                'action' => $action,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Strip sensitive keys from snapshots/metadata.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sanitize(array $payload): array
    {
        $sensitive = ['password', 'remember_token', 'verification_code', 'token'];

        return collect($payload)
            ->reject(fn ($value, $key) => in_array(strtolower((string) $key), $sensitive, true))
            ->all();
    }

    /**
     * Build a snapshot of an admin user with audit-safe fields.
     *
     * @return array<string, mixed>
     */
    public function snapshot(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'is_active' => (bool) $user->is_active,
            'force_password_change' => (bool) $user->force_password_change,
        ];
    }
}
