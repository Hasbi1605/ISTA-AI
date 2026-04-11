<?php
$file = '/Users/macbookair/Magang-Istana/laravel/resources/views/livewire/pages/auth/login.blade.php';
$content = file_get_contents($file);

// 1. Replace use statements to include Password Facade
$content = str_replace(
    'use Illuminate\Support\Facades\Session;',
    "use Illuminate\Support\Facades\Password;\nuse Illuminate\Support\Facades\Session;",
    $content
);

// 2. Replace the variables and toggleRegister with the new view logic
$oldLogic = <<<HTML
    public bool \$isRegister = false;

    // Register fields
    public string \$name = '';
    public string \$register_email = '';
    public string \$register_password = '';
    public string \$password_confirmation = '';

    public function toggleRegister(): void
    {
        \$this->isRegister = !\$this->isRegister;
        \$this->resetErrorBag();
    }
HTML;

$newLogic = <<<HTML
    public string \$view = 'login';

    // Register fields
    public string \$name = '';
    public string \$register_email = '';
    public string \$register_password = '';
    public string \$password_confirmation = '';

    // Forgot Password fields
    public string \$forgot_email = '';
    public ?string \$forgot_status = null;

    public function setView(string \$view): void
    {
        \$this->view = \$view;
        \$this->resetErrorBag();
        \$this->forgot_status = null;
    }

    public function toggleRegister(): void
    {
        \$this->setView(\$this->view === 'register' ? 'login' : 'register');
    }

    public function sendPasswordResetLink(): void
    {
        \$this->validate([
            'forgot_email' => ['required', 'email'],
        ], [], [
            'forgot_email' => 'email',
        ]);

        \$status = Password::broker()->sendResetLink(
            ['email' => \$this->forgot_email]
        );

        if (\$status == Password::RESET_LINK_SENT) {
            \$this->forgot_status = __(\$status);
            \$this->forgot_email = '';
        } else {
            \$this->addError('forgot_email', __(\$status));
        }
    }
HTML;
$content = str_replace($oldLogic, $newLogic, $content);

// 3. Replace @if(!$isRegister)
$content = str_replace('@if(!$isRegister)', "@if(\$view === 'login')", $content);

// 4. Change Lupa kata sandi link
$oldLink = <<<HTML
                            @if (Route::has('password.request'))
                                <a class="text-[13px] font-bold text-[#8b0836] transition-colors hover:text-[#d4af37]" href="{{ route('password.request') }}" wire:navigate>Lupa kata sandi?</a>
                            @endif
HTML;
$newLink = <<<HTML
                            @if (Route::has('password.request'))
                                <button type="button" wire:click="setView('forgot-password')" class="text-[13px] font-bold text-[#8b0836] transition-colors hover:text-[#d4af37]">Lupa kata sandi?</button>
                            @endif
HTML;
$content = str_replace($oldLink, $newLink, $content);

// 5. Replace @else with @elseif($view === 'register')
$content = str_replace('@else', "@elseif(\$view === 'register')", $content);

// 6. Add forgot password form before @endif
$oldEndif = <<<HTML
                </form>
                @endif
HTML;

$forgotForm = <<<HTML
                </form>
                @elseif(\$view === 'forgot-password')
                <form wire:submit="sendPasswordResetLink" class="space-y-6">
                    <div class="mb-4 text-[13px] font-medium text-stone-600">
                        Lupa kata sandi Anda? Tidak masalah. Beri tahu kami alamat email Anda dan kami akan mengirimi Anda tautan pengaturan ulang kata sandi melalui email yang memungkinkan Anda memilih yang baru.
                    </div>

                    @if (\$forgot_status)
                        <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700">
                            {{ \$forgot_status }}
                        </div>
                    @endif

                    <div class="animate-enter-2 group space-y-2">
                        <label for="forgot_email" class="ml-1 cursor-text text-[13px] font-bold text-stone-600 transition-all duration-300 group-focus-within:translate-x-1 group-focus-within:text-rose-900">Alamat Email</label>
                        <div class="relative transform transition-transform duration-300 group-focus-within:scale-[1.01]">
                            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-5">
                                <svg class="h-5 w-5 text-stone-500 transition-colors duration-300 group-focus-within:text-rose-700" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                </svg>
                            </div>
                            <input wire:model="forgot_email" id="forgot_email" class="ista-input" type="email" name="forgot_email" placeholder="email@contoh.com" required autofocus />
                        </div>
                        @if (\$errors->has('forgot_email'))
                            <p class="ml-1 mt-1 animate-pulse text-[12px] font-bold text-rose-600">{{ \$errors->first('forgot_email') }}</p>
                        @endif
                    </div>

                    <div class="animate-enter-4">
                        <button type="submit" class="ista-login-button group">
                            <span class="relative z-10 flex items-center justify-center gap-2 text-[15px] font-semibold text-white transition-all duration-500 ease-out">
                                Kirim Tautan Reset Kata Sandi
                                <svg class="-ml-4 h-4 w-4 opacity-0 transition-all duration-500 group-hover:ml-0 group-hover:opacity-100" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                                </svg>
                            </span>
                            <div class="absolute inset-0 z-0 -translate-x-[150%] skew-x-12 bg-gradient-to-r from-transparent via-white/40 to-transparent transition-transform duration-1000 ease-in-out group-hover:translate-x-[150%]"></div>
                        </button>

                        <div class="mt-4 text-center">
                            <button type="button" wire:click="setView('login')" class="text-[13px] font-bold text-[#8b0836] transition-colors hover:text-[#d4af37]">Kembali ke Login</button>
                        </div>
                    </div>
                </form>
                @endif
HTML;
$content = preg_replace('/<\/form>\s*@endif/', $forgotForm, $content, 1);

file_put_contents($file, $content);
?>
