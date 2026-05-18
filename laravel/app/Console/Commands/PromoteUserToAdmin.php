<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class PromoteUserToAdmin extends Command
{
    protected $signature = 'users:promote-admin {email : Email user yang akan dijadikan admin}';

    protected $description = 'Promote a user to the admin role by email.';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            $this->error(sprintf('User dengan email "%s" tidak ditemukan.', $email));

            return self::FAILURE;
        }

        if ($user->role === User::ROLE_SUPER_ADMIN) {
            $this->warn(sprintf('User "%s" adalah super admin. Role tidak diturunkan.', $user->email));

            return self::SUCCESS;
        }

        if ($user->role === User::ROLE_ADMIN) {
            $this->info(sprintf('User "%s" sudah berperan sebagai admin.', $user->email));

            return self::SUCCESS;
        }

        $user->forceFill(['role' => User::ROLE_ADMIN])->save();

        $this->info(sprintf('User "%s" sekarang berperan sebagai admin.', $user->email));

        return self::SUCCESS;
    }
}
