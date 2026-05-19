<?php

namespace App\Livewire\Admin;

use App\Models\User;
use App\Services\Admin\AdminMetricsService;
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

    public string $role = '';

    /**
     * @var array<string, array<int, string>>
     */
    protected $queryString = [
        'search' => ['except' => ''],
        'status' => ['except' => ''],
        'role' => ['except' => ''],
    ];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function updatingRole(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'status', 'role']);
        $this->resetPage();
    }

    public function render(AdminMetricsService $metrics)
    {
        $users = $metrics->userPresenceListing(
            [
                'status' => $this->normalizedStatus(),
                'role' => $this->normalizedRole(),
                'search' => $this->search,
            ],
            self::USERS_PER_PAGE,
            null,
            $this->getPage(),
        );

        return view('livewire.admin.admin-users', [
            'users' => $users,
            'usersPerPage' => self::USERS_PER_PAGE,
            'presenceSummary' => $metrics->userPresenceSummary(),
            'statusOptions' => [
                'online' => 'Online',
                'idle' => 'Idle',
                'offline' => 'Offline',
            ],
            'roleOptions' => [
                User::ROLE_USER => 'User',
                User::ROLE_ADMIN => 'Admin',
                User::ROLE_SUPER_ADMIN => 'Super Admin',
            ],
        ]);
    }

    private function normalizedStatus(): ?string
    {
        return in_array($this->status, ['online', 'idle', 'offline'], true) ? $this->status : null;
    }

    private function normalizedRole(): ?string
    {
        return in_array($this->role, User::ROLES, true) ? $this->role : null;
    }
}
