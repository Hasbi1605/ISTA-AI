<?php

namespace App\Models;

use App\Mail\VerificationCodeMail;
use App\Notifications\CustomResetPassword;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Mail;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_USER = 'user';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_SUPER_ADMIN = 'super_admin';

    public const ROLES = [
        self::ROLE_USER,
        self::ROLE_ADMIN,
        self::ROLE_SUPER_ADMIN,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'verification_code',
        'verification_code_expires_at',
        'role',
        'last_seen_at',
        'last_active_feature',
        'is_active',
        'disabled_at',
        'disabled_by',
        'disabled_reason',
        'force_password_change',
        'last_admin_login_at',
        'last_admin_login_ip',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_seen_at' => 'datetime',
            'is_active' => 'boolean',
            'disabled_at' => 'datetime',
            'force_password_change' => 'boolean',
            'last_admin_login_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Determine whether the user has the admin role.
     */
    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /**
     * Determine whether the user has the super admin role.
     */
    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    /**
     * Admin or super admin can access general admin routes.
     */
    public function canAccessAdmin(): bool
    {
        return ($this->isAdmin() || $this->isSuperAdmin()) && $this->isActive();
    }

    /**
     * Determine whether the user account is considered active.
     */
    public function isActive(): bool
    {
        return (bool) ($this->is_active ?? true);
    }

    /**
     * Determine whether the user is part of the admin family (admin or super admin).
     */
    public function isAdminFamily(): bool
    {
        return $this->isAdmin() || $this->isSuperAdmin();
    }

    /**
     * Determine whether the user has completed two-factor authentication setup.
     */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    /**
     * Audit log entries where this user is the actor.
     */
    public function adminAuditActions()
    {
        return $this->hasMany(AdminAccountAudit::class, 'actor_id');
    }

    /**
     * Audit log entries where this user is the target.
     */
    public function adminAuditTargets()
    {
        return $this->hasMany(AdminAccountAudit::class, 'target_user_id');
    }

    /**
     * Trusted devices that may skip the 2FA challenge.
     */
    public function trustedDevices()
    {
        return $this->hasMany(TrustedDevice::class);
    }

    /**
     * Get the conversations for the user.
     */
    public function conversations()
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * Send an account email verification OTP for an existing user.
     *
     * New registrations use the cache-backed PendingRegistrationWorkflowService
     * until the account is created and marked verified.
     *
     * @return void
     */
    public function sendEmailVerificationNotification()
    {
        $plainCode = (string) random_int(100000, 999999);
        $ttlMinutes = max(1, (int) config('auth.otp_registration.ttl_minutes', 60));

        $this->update([
            'verification_code' => hash('sha256', $plainCode),
            'verification_code_expires_at' => now()->addMinutes($ttlMinutes),
        ]);

        Mail::to($this->email)->send(new VerificationCodeMail($plainCode));
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new CustomResetPassword($token));
    }
}
