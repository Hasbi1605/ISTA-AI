<x-layouts.admin title="Overview" heading="Overview">
    <x-slot name="pageHeader">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="max-w-2xl">
                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-stone-400 dark:text-gray-500">Selamat datang</p>
                <h2 class="admin-page-title mt-1">Ringkasan Operasional</h2>
                <p class="mt-2 text-sm leading-relaxed text-stone-500 dark:text-gray-400">
                    Pantau aktivitas user, performa AI, dan kesehatan platform dalam satu halaman. Layout ini akan menjadi kerangka dashboard penuh pada tahap berikutnya.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <x-admin.badge tone="primary">
                    <span class="h-1.5 w-1.5 rounded-full bg-ista-primary"></span>
                    Live shell
                </x-admin.badge>
                <x-admin.badge tone="neutral">v1 Foundation</x-admin.badge>
            </div>
        </div>
    </x-slot>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-admin.kpi-card
            label="User Aktif"
            value="—"
            tone="primary"
            description="Akan diisi pada child dashboard monitoring." />

        <x-admin.kpi-card
            label="Pesan AI / Hari"
            value="—"
            tone="gold"
            description="Pengukuran event tracking belum aktif." />

        <x-admin.kpi-card
            label="Latensi Rata-rata"
            value="—"
            tone="success"
            description="Akan tersedia setelah event tracking." />

        <x-admin.kpi-card
            label="Error AI"
            value="—"
            tone="danger"
            description="Threshold akan dikonfigurasi nanti." />
    </div>

    <div class="mt-6 grid gap-4 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-admin.section
                title="Aktivitas Terbaru"
                description="Daftar interaksi user terbaru pada modul chat dan memo. Akan tersambung pada tahap monitoring.">
                <x-slot name="actions">
                    <x-admin.filter name="range" :options="['7d' => '7 hari', '30d' => '30 hari', '90d' => '90 hari']" placeholder="Periode" />
                </x-slot>

                <x-admin.table :columns="[
                    ['key' => 'user', 'label' => 'User'],
                    ['key' => 'feature', 'label' => 'Fitur'],
                    ['key' => 'status', 'label' => 'Status'],
                    ['key' => 'time', 'label' => 'Waktu', 'align' => 'right'],
                ]">
                    <tr>
                        <td colspan="4" class="admin-table__empty">
                            <x-admin.empty-state
                                title="Belum ada aktivitas"
                                description="Data akan muncul setelah event tracking aktif." />
                        </td>
                    </tr>
                </x-admin.table>
            </x-admin.section>
        </div>

        <div class="space-y-4">
            <x-admin.section
                title="Status Sistem"
                description="Indikator kesehatan komponen utama.">
                <ul class="space-y-3 text-sm">
                    <li class="flex items-center justify-between">
                        <span class="text-stone-600 dark:text-gray-300">Laravel API</span>
                        <x-admin.badge tone="success">Online</x-admin.badge>
                    </li>
                    <li class="flex items-center justify-between">
                        <span class="text-stone-600 dark:text-gray-300">Python AI</span>
                        <x-admin.badge tone="warning">Belum dipantau</x-admin.badge>
                    </li>
                    <li class="flex items-center justify-between">
                        <span class="text-stone-600 dark:text-gray-300">Storage</span>
                        <x-admin.badge tone="info">N/A</x-admin.badge>
                    </li>
                </ul>
            </x-admin.section>

            <x-admin.section
                title="Loading State"
                description="Skeleton siap pakai untuk panel monitoring lain.">
                <x-admin.loading :rows="4" />
            </x-admin.section>
        </div>
    </div>
</x-layouts.admin>
