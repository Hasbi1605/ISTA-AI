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
    <button @click="open = ! open" class="flex items-center gap-2 rounded-full p-1 transition hover:bg-stone-100 focus:outline-none">
        <div class="flex h-8 w-8 items-center justify-center rounded-full bg-[#8b0836] text-sm font-bold text-amber-400">
            {{ substr(auth()->user()->name, 0, 1) }}
        </div>
        <div class="hidden items-center gap-1 pr-2 sm:flex">
            <span class="text-sm font-medium text-stone-600" x-data="{{ json_encode(['name' => auth()->user()->name]) }}" x-text="name" x-on:profile-updated.window="name = $event.detail.name"></span>
            <svg class="h-4 w-4 text-stone-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
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
         class="absolute right-0 z-50 mt-2 w-48 origin-top-right rounded-xl border border-stone-100 bg-white py-1 shadow-lg ring-1 ring-black ring-opacity-5"
         style="display: none;">
        
        <div class="block border-b border-stone-100 px-4 py-2 sm:hidden">
            <p class="truncate text-sm font-medium text-stone-800">{{ auth()->user()->name }}</p>
            <p class="truncate text-xs text-stone-500">{{ auth()->user()->email }}</p>
        </div>

        <a href="{{ route('profile') }}" class="block px-4 py-2 text-sm text-stone-700 transition hover:bg-stone-50 hover:text-[#8b0836]">
            Profil
        </a>
        
        <button wire:click="logout" class="block w-full px-4 py-2 text-left text-sm text-stone-700 transition hover:bg-stone-50 hover:text-[#8b0836]">
            Keluar
        </button>
    </div>
    @else
    <button @click="open = ! open" class="flex items-center gap-2 rounded-full p-1 transition hover:bg-stone-100 focus:outline-none">
        <div class="flex h-8 w-8 items-center justify-center rounded-full bg-stone-300 text-sm font-bold text-stone-600">
            G
        </div>
        <div class="hidden items-center gap-1 pr-2 sm:flex">
            <span class="text-sm font-medium text-stone-600">Guest</span>
            <svg class="h-4 w-4 text-stone-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
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
         class="absolute right-0 z-50 mt-2 w-48 origin-top-right rounded-xl border border-stone-100 bg-white py-1 shadow-lg ring-1 ring-black ring-opacity-5"
         style="display: none;">
        
        <a href="{{ route('login') }}" class="block px-4 py-2 text-sm text-stone-700 transition hover:bg-stone-50 hover:text-[#8b0836]">
            Login
        </a>
    </div>
    @endauth
</div>
