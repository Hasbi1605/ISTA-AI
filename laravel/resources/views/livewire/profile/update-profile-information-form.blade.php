<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component
{
    public string $name = '';
    public string $email = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($user->id)],
        ]);

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        $this->dispatch('profile-updated', name: $user->name);
    }

    /**
     * Send an email verification notification to the current user.
     */
    public function sendVerification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }
}; ?>

<section>
    <header>
        <h2 class="text-xl font-bold text-ista-primary">
            Informasi Profil
        </h2>

        <p class="mt-1 text-sm text-stone-600 font-medium">
            Perbarui informasi profil dan alamat email akun Anda.
        </p>
    </header>

    <form wire:submit="updateProfileInformation" class="mt-6 space-y-6">
        <div class="group space-y-2">
            <label for="name" class="ml-1 cursor-text text-[13px] font-bold text-stone-600 transition-all duration-300 group-focus-within:translate-x-1 group-focus-within:text-rose-900">Nama</label>
            <div class="relative transform transition-transform duration-300 group-focus-within:scale-[1.01]">
                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-5">
                    <svg class="h-5 w-5 text-stone-500 transition-colors duration-300 group-focus-within:text-rose-700" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                    </svg>
                </div>
                <input wire:model="name" id="name" name="name" type="text" class="ista-input" required autofocus autocomplete="name" />
            </div>
            @if ($errors->has('name'))
                <p class="ml-1 mt-1 animate-pulse text-[12px] font-bold text-rose-600">{{ $errors->first('name') }}</p>
            @endif
        </div>

        <div class="group space-y-2">
            <label for="email" class="ml-1 cursor-text text-[13px] font-bold text-stone-600 transition-all duration-300 group-focus-within:translate-x-1 group-focus-within:text-rose-900">Email</label>
            <div class="relative transform transition-transform duration-300 group-focus-within:scale-[1.01]">
                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-5">
                    <svg class="h-5 w-5 text-stone-500 transition-colors duration-300 group-focus-within:text-rose-700" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                    </svg>
                </div>
                <input wire:model="email" id="email" name="email" type="email" class="ista-input" required autocomplete="username" />
            </div>
            @if ($errors->has('email'))
                <p class="ml-1 mt-1 animate-pulse text-[12px] font-bold text-rose-600">{{ $errors->first('email') }}</p>
            @endif

            @if (auth()->user() instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! auth()->user()->hasVerifiedEmail())
                <div>
                    <p class="text-[13px] mt-2 text-stone-800 font-medium">
                        Alamat email Anda belum terverifikasi.

                        <button wire:click.prevent="sendVerification" class="underline text-sm text-ista-primary hover:text-ista-gold rounded-md focus:outline-none">
                            Klik di sini untuk mengirim ulang email verifikasi.
                        </button>
                    </p>

                    @if (session('status') === 'verification-link-sent')
                        <p class="mt-2 font-medium text-[13px] text-emerald-600">
                            Tautan verifikasi baru telah dikirim ke alamat email Anda.
                        </p>
                    @endif
                </div>
            @endif
        </div>

        <div class="flex items-center gap-4">
            <button type="submit" class="ista-login-button group w-auto px-6 py-2">
                <span class="relative z-10 flex items-center justify-center gap-2 text-[15px] font-semibold text-white transition-all duration-500 ease-out">
                    Simpan
                </span>
                <div class="absolute inset-0 z-0 -translate-x-[150%] skew-x-12 bg-gradient-to-r from-transparent via-white/40 to-transparent transition-transform duration-1000 ease-in-out group-hover:translate-x-[150%]"></div>
            </button>

            <x-action-message class="me-3 text-ista-gold text-sm font-bold" on="profile-updated">
                Tersimpan.
            </x-action-message>
        </div>
    </form>
</section>
