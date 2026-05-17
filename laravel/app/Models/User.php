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
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
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
