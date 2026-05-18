<x-layouts.admin title="AI Configuration" heading="AI Configuration">
    <x-slot name="pageHeader">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="max-w-2xl">
                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-stone-400 dark:text-gray-500">Super Admin</p>
                <h2 class="admin-page-title mt-1">AI Configuration</h2>
                <p class="mt-2 text-sm leading-relaxed text-stone-500 dark:text-gray-400">
                    Halaman ini akan menjadi pusat konfigurasi prompt, model AI, dan parameter generasi. Implementasi penuh akan dikerjakan pada child khusus.
                </p>
            </div>
            <x-admin.badge tone="gold">
                <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>
                Akses terbatas
            </x-admin.badge>
        </div>
    </x-slot>

    <x-admin.section
        title="Placeholder"
        description="Form konfigurasi akan dibangun di sini.">
        <x-admin.empty-state
            title="Belum ada konfigurasi"
            description="Formulir prompt, pemilih model, dan parameter generasi akan tersedia pada child AI Configuration." />
    </x-admin.section>
</x-layouts.admin>
