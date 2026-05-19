<?php

namespace App\Console\Commands;

use App\Models\AdminAccountAudit;
use App\Models\User;
use App\Services\Admin\AdminAccountAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class BootstrapAdminAccounts extends Command
{
    protected $signature = 'admin:bootstrap-accounts
        {--force-reset-password : Reset the temporary password for existing bootstrap accounts}';

    protected $description = 'Bootstrap initial admin & super admin accounts from environment variables.';

    public function handle(AdminAccountAuditService $audit): int
    {
        $config = [
            [
                'role' => User::ROLE_SUPER_ADMIN,
                'email' => env('INITIAL_SUPER_ADMIN_EMAIL'),
                'password' => env('INITIAL_SUPER_ADMIN_PASSWORD'),
                'label' => 'super admin',
                'name' => 'Super Admin ISTA',
            ],
            [
                'role' => User::ROLE_ADMIN,
                'email' => env('INITIAL_ADMIN_EMAIL'),
                'password' => env('INITIAL_ADMIN_PASSWORD'),
                'label' => 'admin',
                'name' => 'Admin ISTA',
            ],
        ];

        $missing = [];
        foreach ($config as $entry) {
            if (empty($entry['email']) || empty($entry['password'])) {
                $missing[] = $entry['label'];
            }
        }

        if (! empty($missing)) {
            $this->error('Tidak dapat melakukan bootstrap. Env credential tidak lengkap untuk: '
                .implode(', ', $missing).'.');
            $this->line('Set INITIAL_ADMIN_EMAIL, INITIAL_ADMIN_PASSWORD, INITIAL_SUPER_ADMIN_EMAIL, dan INITIAL_SUPER_ADMIN_PASSWORD pada .env.');

            return self::FAILURE;
        }

        foreach ($config as $entry) {
            $email = strtolower(trim((string) $entry['email']));
            $password = (string) $entry['password'];
            $label = $entry['label'];
            $role = $entry['role'];

            if (strlen($password) < 8) {
                $this->error(sprintf('Password awal untuk %s minimal 8 karakter.', $label));

                return self::FAILURE;
            }

            DB::transaction(function () use ($email, $password, $role, $label, $entry, $audit) {
                $user = User::query()->where('email', $email)->first();

                if (! $user) {
                    // Use forceFill+save instead of create() because email_verified_at
                    // is not in $fillable on the User model.
                    $user = (new User)->forceFill([
                        'name' => $entry['name'],
                        'email' => $email,
                        'password' => Hash::make($password),
                        'email_verified_at' => now(),
                        'role' => $role,
                        'is_active' => true,
                        'force_password_change' => true,
                    ]);
                    $user->save();

                    $audit->record(
                        AdminAccountAudit::ACTION_CREATED,
                        actor: null,
                        target: $user,
                        after: $audit->snapshot($user),
                        metadata: ['source' => 'bootstrap'],
                    );

                    $this->info(sprintf('Akun %s dibuat: %s.', $label, $email));

                    return;
                }

                $before = $audit->snapshot($user);
                $changed = false;

                if ($user->role !== $role) {
                    $user->role = $role;
                    $changed = true;
                }

                if (! (bool) $user->is_active) {
                    $user->is_active = true;
                    $user->disabled_at = null;
                    $user->disabled_by = null;
                    $user->disabled_reason = null;
                    $changed = true;
                }

                if ($this->option('force-reset-password')) {
                    $user->password = Hash::make($password);
                    $user->force_password_change = true;
                    $changed = true;
                }

                if ($changed) {
                    $user->save();

                    $audit->record(
                        AdminAccountAudit::ACTION_UPDATED,
                        actor: null,
                        target: $user,
                        before: $before,
                        after: $audit->snapshot($user),
                        metadata: ['source' => 'bootstrap'],
                    );

                    $this->info(sprintf('Akun %s diperbarui: %s.', $label, $email));
                } else {
                    $this->line(sprintf('Akun %s sudah konsisten: %s.', $label, $email));
                }
            });
        }

        $this->newLine();
        $this->info('Bootstrap akun admin selesai. Pastikan password awal segera diganti setelah login.');

        return self::SUCCESS;
    }
}
