<?php

use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    /**
     * Send an email verification notification to the user.
     */
    public function sendVerification(): void
    {
        if (Auth::user()->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);

            return;
        }

        Auth::user()->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }

    public string $verification_code_input = '';

    public function verifyOtp(): void
    {
        $this->validate([
            'verification_code_input' => ['required', 'digits:6'],
        ], [
            'verification_code_input.required' => 'Masukkan kode OTP 6 digit dari email.',
            'verification_code_input.digits' => 'Kode OTP harus 6 digit angka.',
        ]);

        $user = Auth::user();

        if (! $user || ! $user->verification_code) {
            throw ValidationException::withMessages([
                'verification_code_input' => 'Kode OTP tidak tersedia. Klik Kirim ulang OTP untuk mendapatkan kode baru.',
            ]);
        }

        if ($user->verification_code_expires_at && now()->greaterThan($user->verification_code_expires_at)) {
            throw ValidationException::withMessages([
                'verification_code_input' => 'Kode OTP sudah kedaluwarsa. Klik Kirim ulang OTP untuk mendapatkan kode akun terbaru.',
            ]);
        }

        if (! hash_equals((string) $user->verification_code, hash('sha256', $this->verification_code_input))) {
            throw ValidationException::withMessages([
                'verification_code_input' => 'Kode OTP tidak valid. Pastikan memakai kode terbaru karena kirim ulang membatalkan kode lama.',
            ]);
        }

        if (! $user->hasVerifiedEmail()) {
            $user->forceFill([
                'email_verified_at' => now(),
                'verification_code' => null,
                'verification_code_expires_at' => null,
            ])->save();
        }

        $this->redirectIntended(default: route('dashboard', absolute: false).'?verified=1', navigate: true);
    }

    /**
     * Log the current user out of the application.
     */
    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect('/', navigate: true);
    }
}; ?>

<div>
    <div class="mb-4 text-sm text-gray-600 dark:text-gray-400">
        Kami mengirimkan kode OTP 6 digit ke email Anda. Masukkan kode tersebut untuk mengaktifkan akun. Jika Anda meminta kode baru, kode lama otomatis tidak berlaku.
    </div>

    @if (session('status') == 'verification-link-sent')
        <div class="mb-4 font-medium text-sm text-green-600 dark:text-green-400">
            Kode OTP baru sudah dikirim ke email Anda. Gunakan kode terbaru tersebut.
        </div>
    @endif

    <form wire:submit="verifyOtp" class="space-y-3">
        <div>
            <label for="verification_code_input" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Kode OTP email</label>
            <input id="verification_code_input" wire:model="verification_code_input" inputmode="numeric" autocomplete="one-time-code" maxlength="6" class="mt-1 block w-full rounded-md border-gray-300 text-center text-lg tracking-[0.35em] shadow-sm focus:border-ista-primary focus:ring-ista-primary dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" />
            @error('verification_code_input') <p class="mt-2 text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
        </div>
        <x-primary-button>Verifikasi OTP</x-primary-button>
    </form>

    <div class="mt-4 flex items-center justify-between">
        <x-primary-button wire:click="sendVerification">
            Kirim ulang OTP
        </x-primary-button>

        <button wire:click="logout" type="submit" class="underline text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 dark:focus:ring-offset-gray-800">
            Keluar / kembali login
        </button>
    </div>
</div>
