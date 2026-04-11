<?php

use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    public string $password = '';

    /**
     * Delete the currently authenticated user.
     */
    public function deleteUser(Logout $logout): void
    {
        $this->validate([
            'password' => ['required', 'string', 'current_password'],
        ]);

        tap(Auth::user(), $logout(...))->delete();

        $this->redirect('/', navigate: true);
    }
}; ?>

<section class="space-y-6">
    <header>
        <h2 class="text-xl font-bold text-[#8b0836]">
            Hapus Akun
        </h2>

        <p class="mt-1 text-sm text-stone-600 font-medium">
            Setelah akun Anda dihapus, semua sumber daya dan data di dalamnya akan dihapus secara permanen. Sebelum menghapus akun Anda, harap unduh data atau informasi yang ingin Anda simpan.
        </p>
    </header>

    <button
        x-data=""
        x-on:click.prevent="$dispatch('open-modal', 'confirm-user-deletion')"
        class="inline-flex items-center justify-center rounded-2xl border border-rose-200 bg-rose-50 px-6 py-2.5 text-[15px] font-semibold text-rose-700 shadow-sm transition-all hover:bg-rose-100 hover:text-rose-800 hover:border-rose-300 focus:outline-none focus:ring-2 focus:ring-rose-500 focus:ring-offset-2"
    >
        Hapus Akun
    </button>

    <x-modal name="confirm-user-deletion" :show="$errors->isNotEmpty()" focusable>
        <form wire:submit="deleteUser" class="p-6 bg-[#fafaf9] rounded-2xl border border-white/40">

            <h2 class="text-lg font-bold text-stone-900">
                Apakah Anda yakin ingin menghapus akun ini?
            </h2>

            <p class="mt-1 text-sm text-stone-600 font-medium">
                Setelah akun Anda dihapus, semua sumber daya dan data di dalamnya akan dihapus secara permanen. Masukkan kata sandi Anda untuk mengonfirmasi bahwa Anda ingin menghapus akun secara permanen.
            </p>

            <div class="mt-6 group space-y-2">
                <label for="password" class="ml-1 cursor-text text-[13px] font-bold text-stone-600 transition-all duration-300 group-focus-within:translate-x-1 group-focus-within:text-rose-900">Kata Sandi</label>
                <div class="relative transform transition-transform duration-300 group-focus-within:scale-[1.01]">
                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-5">
                        <svg class="h-5 w-5 text-stone-500 transition-colors duration-300 group-focus-within:text-rose-700" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 00-2 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                        </svg>
                    </div>
                    <input wire:model="password" id="password" name="password" type="password" class="ista-input" placeholder="Kata Sandi" />
                </div>
                @if ($errors->has('password'))
                    <p class="ml-1 mt-1 animate-pulse text-[12px] font-bold text-rose-600">{{ $errors->first('password') }}</p>
                @endif
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <button type="button" x-on:click="$dispatch('close')" class="rounded-xl border border-stone-200 bg-white px-4 py-2 text-sm font-semibold text-stone-600 transition hover:bg-stone-50 hover:text-stone-900 focus:outline-none">
                    Batal
                </button>

                <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-rose-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-rose-700 focus:outline-none focus:ring-2 focus:ring-rose-500 focus:ring-offset-2">
                    Hapus Akun
                </button>
            </div>
        </form>
    </x-modal>
</section>
