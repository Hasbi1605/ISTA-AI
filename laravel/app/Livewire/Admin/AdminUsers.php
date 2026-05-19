<?php

namespace App\Livewire\Admin;

use App\Models\User;
use App\Services\Admin\AdminMetricsService;
use App\Services\Admin\AdminUserManagementService;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.admin', ['title' => 'Users', 'heading' => 'Users'])]
class AdminUsers extends Component
{
    use WithPagination;

    private const USERS_PER_PAGE = 15;

    public string $search = '';

    public string $status = '';

    public ?string $flashMessage = null;

    /**
     * @var array<string, array<int, string>>
     */
    protected $queryString = [
        'search' => ['except' => ''],
        'status' => ['except' => ''],
    ];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'status']);
        $this->resetPage();
    }

    public function deleteUser(int $userId, AdminUserManagementService $service): void
    {
        $target = User::query()->findOrFail($userId);

        $service->deleteRegularUser(auth()->user(), $target, request());

        $this->flashMessage = sprintf('Akun "%s" berhasil dihapus.', $target->email);
        $this->resetPage();
    }

    public function clearFlash(): void
    {
        $this->flashMessage = null;
    }

    public function render(AdminMetricsService $metrics)
    {
        $users = $metrics->userPresenceListing(
            [
                'status' => $this->normalizedStatus(),
                'role' => User::ROLE_USER,
                'search' => $this->search,
            ],
            self::USERS_PER_PAGE,
            null,
            $this->getPage(),
        );

        return view('livewire.admin.admin-users', [
            'users' => $users,
            'usersPerPage' => self::USERS_PER_PAGE,
            'presenceSummary' => $metrics->userPresenceSummary(role: User::ROLE_USER),
            'statusOptions' => [
                'online' => 'Online',
                'idle' => 'Idle',
                'offline' => 'Offline',
            ],
            'canDeleteUsers' => auth()->user()?->isSuperAdmin() && auth()->user()?->isActive(),
        ]);
    }

    private function normalizedStatus(): ?string
    {
        return in_array($this->status, ['online', 'idle', 'offline'], true) ? $this->status : null;
    }

}
