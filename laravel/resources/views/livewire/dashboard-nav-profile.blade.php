<?php

use App\Livewire\Actions\Logout;
use Livewire\Volt\Component;

new class extends Component {
    public function logout(Logout $logout): void
    {
        $logout();
        $this->redirect('/', navigate: true);
    }
}; ?>

<div class="relative" x-data="{ open: false }" @click.outside="open = false" @close.stop="open = false">
    @auth
    <button @click="open = ! open" class="flex items-center gap-2 rounded-full p-1 transition hover:bg-stone-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-ista-primary/50 dark:hover:bg-gray-800" aria-label="Buka menu profil" :aria-expanded="open ? 'true' : 'false'" aria-haspopup="menu" aria-controls="dashboard-profile-menu">
        <div class="flex h-8 w-8 items-center justify-center rounded-full bg-ista-primary text-sm font-bold text-amber-400">
            {{ substr(auth()->user()->name, 0, 1) }}
        </div>
        <div class="hidden items-center gap-1 pr-2 sm:flex">
            <span class="text-sm font-medium text-stone-600 dark:text-gray-200" x-data="{{ json_encode(['name' => auth()->user()->name]) }}" x-text="name" x-on:profile-updated.window="name = $event.detail.name"></span>
            <svg class="h-4 w-4 text-stone-500 dark:text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>
        </div>
    </button>

         <div x-show="open"
          x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         x-transition:leave="transition ease-in duration-75"
         x-transition:leave-start="opacity-100 scale-100"
         x-transition:leave-end="opacity-0 scale-95"
            id="dashboard-profile-menu"
            class="absolute right-0 z-50 mt-2 w-48 origin-top-right rounded-xl border border-stone-100 bg-white py-1 shadow-lg ring-1 ring-black ring-opacity-5 dark:border-gray-800 dark:bg-gray-900" role="menu"
          style="display: none;">
        
        <div class="block border-b border-stone-100 px-4 py-2 dark:border-gray-800 sm:hidden">
            <p class="truncate text-sm font-medium text-stone-800 dark:text-gray-100">{{ auth()->user()->name }}</p>
            <p class="truncate text-xs text-stone-500 dark:text-gray-400">{{ auth()->user()->email }}</p>
        </div>

        @if (auth()->user() && method_exists(auth()->user(), 'canAccessAdmin') && auth()->user()->canAccessAdmin())
            <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-2 px-4 py-2 text-sm font-medium text-ista-primary transition hover:bg-stone-50 dark:text-amber-300 dark:hover:bg-gray-800" role="menuitem">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                </svg>
                Dashboard Admin
            </a>
        @endif

        <a href="{{ route('profile') }}" class="block px-4 py-2 text-sm text-stone-700 transition hover:bg-stone-50 hover:text-ista-primary dark:text-gray-200 dark:hover:bg-gray-800 dark:hover:text-amber-300" role="menuitem">
            Profil
        </a>

        <button wire:click="logout" class="block w-full border-t border-stone-100 px-4 py-2 text-left text-sm text-stone-700 transition hover:bg-stone-50 hover:text-ista-primary dark:border-gray-800 dark:text-gray-200 dark:hover:bg-gray-800 dark:hover:text-amber-300" role="menuitem">
            Keluar
        </button>
    </div>
    @else
    <a href="{{ route('login') }}" aria-label="Masuk ke akun Anda" class="inline-flex items-center rounded-full bg-ista-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-ista-dark focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ista-primary">
        Masuk
    </a>
    @endauth
</div>
