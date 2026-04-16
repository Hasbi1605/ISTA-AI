<style>
    /* Full screen modern animated overlay */
    #global-page-loader {
        position: fixed;
        inset: 0;
        z-index: 99999;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        background-color: #ffffff;
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
        /* Muncull sekejap mata untuk memblocking blank screen (Snap IN) */
        transition: opacity 0.05s ease-out, visibility 0.05s ease-out;
    }
    
    /* Adapt to Dark Mode natively if the `.dark` class is on html/body */
    html.dark #global-page-loader, .dark #global-page-loader {
        background-color: #020618;
    }
    
    #global-page-loader.loader-hidden {
        opacity: 0;
        visibility: hidden;
        pointer-events: none;
        /* Menghilang secara elegan memudar pelan pelan (Fade OUT) */
        transition: opacity 0.6s cubic-bezier(0.4, 0, 0.2, 1), visibility 0.6s cubic-bezier(0.4, 0, 0.2, 1);
    }
    
    /* Smooth Pulse Animation */
    @keyframes subtlePulse {
        0%, 100% { transform: scale(1); opacity: 1; }
        50% { transform: scale(1.05); opacity: 0.8; }
    }
    .loader-brand {
        animation: subtlePulse 2s ease-in-out infinite;
    }
    
    /* Gradient Spinner */
    .loader-spinner {
        position: absolute;
        inset: -12px;
        border-radius: 50%;
        border: 3px solid transparent;
        border-top-color: #4f46e5; /* ISTA Primary Base */
        border-right-color: #4f46e5;
        opacity: 0.8;
        animation: spin 1s cubic-bezier(0.68, -0.55, 0.265, 1.55) infinite;
    }
    html.dark .loader-spinner, .dark .loader-spinner {
        border-top-color: #818cf8;
        border-right-color: #818cf8;
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
</style>

<div id="global-page-loader">
    <div class="relative flex items-center justify-center mb-6">
        <!-- Spinner Ring -->
        <div class="loader-spinner"></div>
        
        <!-- Center Logo Image -->
        <div class="h-16 w-16 md:h-20 md:w-20 bg-white/50 dark:bg-black/20 rounded-full flex items-center justify-center shadow-lg backdrop-blur-md border border-gray-100 dark:border-gray-800 loader-brand">
            <img src="{{ asset('images/ista/logo.png') }}" alt="ISTA AI" class="h-10 w-10 md:h-12 md:w-12 object-contain drop-shadow-md">
        </div>
    </div>
    
    <!-- Animated Loading Text -->
    <div class="flex items-center gap-1 text-[13px] md:text-sm font-semibold text-gray-500 dark:text-gray-400 tracking-[0.15em] uppercase">
        <span>L</span><span>o</span><span>a</span><span>d</span><span>i</span><span>n</span><span>g</span>
        <span class="inline-flex gap-0.5 ml-1">
            <span class="animate-bounce [animation-delay:-0.3s] h-1 w-1 bg-current rounded-full"></span>
            <span class="animate-bounce [animation-delay:-0.15s] h-1 w-1 bg-current rounded-full"></span>
            <span class="animate-bounce h-1 w-1 bg-current rounded-full"></span>
        </span>
    </div>
</div>

<script>
    // Execute immediately to prevent flash
    const loader = document.getElementById('global-page-loader');
    
    const showLoader = () => {
        if(loader) loader.classList.remove('loader-hidden');
    };
    
    const hideLoader = () => {
        if(loader) {
            // Adds a micro-delay so the browser has time to paint the new DOM before fading out
            setTimeout(() => {
                loader.classList.add('loader-hidden');
            }, 150);
        }
    };

    // Standard page transitions
    window.addEventListener('beforeunload', showLoader);
    window.addEventListener('load', hideLoader);
    
    // Fallback for BFCache (Back/Forward navigation)
    window.addEventListener('pageshow', (e) => {
        if (e.persisted) hideLoader();
    });

    // Livewire SPA mode support (if wire:navigate is used)
    document.addEventListener('livewire:navigating', showLoader);
    document.addEventListener('livewire:navigated', hideLoader);
    
    // Safety fallback (force hide after 8 seconds to prevent users getting stuck on very slow requests or errors)
    setTimeout(hideLoader, 8000);
</script>
