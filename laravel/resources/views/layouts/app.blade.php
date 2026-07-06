<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      x-data="{ 
          darkMode: localStorage.getItem('theme') === 'dark'
      }" 
      x-init="$watch('darkMode', val => localStorage.setItem('theme', val ? 'dark' : 'light'))"
      :class="{ 'dark': darkMode }">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>ISTA AI</title>
        <link rel="icon" type="image/png" href="{{ asset('images/ista/logo.png') }}">

        <style>
            ::-webkit-scrollbar { width: 4px; }
            ::-webkit-scrollbar-track { background: transparent; }
            ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
            .dark ::-webkit-scrollbar-thumb { background: #334155; }
        </style>

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="app-viewport font-sans antialiased text-gray-900 bg-[#ffffff] dark:bg-[#020618] dark:text-gray-100 transition-colors duration-200">
        <x-page-loader />
        <script>
            // Inline script to prevent flash of unstyled content (FOUC)
            if (localStorage.theme === 'dark') {
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.classList.remove('dark');
            }
        </script>
        <main class="app-main-viewport w-full flex">
            {{ $slot }}
        </main>
        <livewire:documents.document-viewer />

    </body>
</html>
