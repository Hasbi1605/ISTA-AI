<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class PromoteUserToSuperAdmin extends Command
{
    protected $signature = 'users:promote-super-admin {email : Email user yang akan dijadikan super admin}';

    protected $description = 'Promote a user to the super admin role by email.';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            $this->error(sprintf('User dengan email "%s" tidak ditemukan.', $email));

            return self::FAILURE;
        }

        if ($user->role === User::ROLE_SUPER_ADMIN) {
            $this->info(sprintf('User "%s" sudah berperan sebagai super admin.', $user->email));

            return self::SUCCESS;
        }

        $user->forceFill(['role' => User::ROLE_SUPER_ADMIN])->save();

        $this->info(sprintf('User "%s" sekarang berperan sebagai super admin.', $user->email));

        return self::SUCCESS;
    }
}
