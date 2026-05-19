<?php

namespace App\Services\Admin;

use App\Models\AdminAccountAudit;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Memo;
use App\Models\User;
use App\Services\DocumentLifecycleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdminUserManagementService
{
    public function __construct(
        private readonly AdminAccountAuditService $audit,
        private readonly DocumentLifecycleService $documentLifecycle,
    ) {
    }

    /**
     * Delete a regular user account and clean user-owned document artifacts.
     */
    public function deleteRegularUser(User $actor, User $target, ?Request $request = null): bool
    {
        $this->guardActorIsSuperAdmin($actor);
        $this->guardTargetIsRegularUser($target);

        $metadata = [
            'conversation_count' => Conversation::query()->where('user_id', $target->id)->count(),
            'document_count' => Document::query()->where('user_id', $target->id)->count(),
            'memo_count' => Memo::query()->where('user_id', $target->id)->count(),
        ];

        Document::query()
            ->where('user_id', $target->id)
            ->orderBy('id')
            ->get()
            ->each(fn (Document $document) => $this->documentLifecycle->deleteDocument($document));

        return DB::transaction(function () use ($actor, $target, $metadata, $request) {
            $before = $this->audit->snapshot($target);

            $this->audit->record(
                AdminAccountAudit::ACTION_REGULAR_USER_DELETED,
                actor: $actor,
                target: $target,
                before: $before,
                metadata: $metadata,
                request: $request,
            );

            DB::table('sessions')->where('user_id', $target->id)->delete();

            return (bool) $target->delete();
        });
    }

    private function guardActorIsSuperAdmin(User $actor): void
    {
        if (! $actor->isSuperAdmin() || ! $actor->isActive()) {
            Log::warning('Non super admin attempted regular user deletion', [
                'actor_id' => $actor->id,
                'role' => $actor->role,
            ]);

            abort(403, 'Hanya super admin aktif yang dapat menghapus akun user.');
        }
    }

    private function guardTargetIsRegularUser(User $target): void
    {
        if ($target->role !== User::ROLE_USER) {
            Log::warning('Regular user deletion attempted on admin-family target', [
                'target_id' => $target->id,
                'role' => $target->role,
            ]);

            abort(404, 'Akun target bukan user regular.');
        }
    }
}
