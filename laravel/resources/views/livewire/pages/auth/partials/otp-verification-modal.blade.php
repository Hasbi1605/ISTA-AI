<div class="fixed inset-0" style="z-index: 9998; background-color: rgba(0, 0, 0, 0.88); backdrop-filter: blur(8px);"></div>

<div class="fixed inset-0 z-[9999] flex items-center justify-center p-4">
    <div class="ista-glass-card relative w-full max-w-md p-8 animate-enter-1 border-white/95 bg-white/80 shadow-[0_0_0_1px_rgba(255,255,255,0.65),0_0_110px_rgba(255,255,255,0.4),0_30px_70px_-15px_rgba(0,0,0,0.58)] hover:translate-y-0 hover:shadow-[0_0_0_1px_rgba(255,255,255,0.65),0_0_110px_rgba(255,255,255,0.4),0_30px_70px_-15px_rgba(0,0,0,0.58)]" style="background: linear-gradient(150deg, rgba(255, 255, 255, 0.94), rgba(255, 255, 255, 0.78)); backdrop-filter: blur(22px) saturate(135%);">
        <button wire:click="cancelVerification" class="absolute right-4 top-4 text-stone-500 transition-colors hover:text-stone-700" type="button" aria-label="Tutup popup verifikasi">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>

        <h2 class="mb-2 text-center text-2xl font-bold text-stone-900">Verifikasi Email</h2>
        <p class="mb-6 text-center text-sm text-stone-600">Kami mengirimkan kode 6 digit ke email Anda. Masukkan kode untuk menyelesaikan pendaftaran.</p>

        @if($otp_status)
            <p class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-center text-xs font-medium text-emerald-700">{{ $otp_status }}</p>
        @endif

        <form wire:submit="verifyOtp">
            <div
                x-data="{
                    otp: ['', '', '', '', '', ''],
                    focusNext(index) {
                        if (this.otp[index] && index < 5) {
                            document.getElementById('otp_' + (index + 1)).focus();
                        }
                        $wire.set('verification_code_input', this.otp.join(''));
                    },
                    handleBackspace(event, index) {
                        if ((event.key === 'Backspace' || event.key === 'Delete') && !this.otp[index] && index > 0) {
                            const prevInput = document.getElementById('otp_' + (index - 1));
                            if (prevInput) {
                                prevInput.focus();
                                setTimeout(() => prevInput.select(), 10);
                            }
                        }
                    },
                    handlePaste(event) {
                        const pastedData = event.clipboardData.getData('text').slice(0, 6);
                        if (/^\d+$/.test(pastedData)) {
                            for (let i = 0; i < pastedData.length; i++) {
                                this.otp[i] = pastedData[i];
                            }
                            $wire.set('verification_code_input', this.otp.join(''));
                            const focusIndex = pastedData.length > 5 ? 5 : pastedData.length;
                            document.getElementById('otp_' + focusIndex).focus();
                        }
                    }
                }"
            >
                <label class="mb-2 block text-center text-sm font-medium text-stone-700">Kode Verifikasi</label>
                <div class="mb-2 flex justify-center gap-2" @paste.prevent="handlePaste">
                    <template x-for="(digit, index) in otp" :key="index">
                        <input
                            type="text"
                            maxlength="1"
                            x-model="otp[index]"
                            :id="'otp_' + index"
                            @input="focusNext(index)"
                            @keydown="handleBackspace($event, index)"
                            class="h-14 w-12 rounded-xl border border-stone-300 bg-white text-center text-2xl font-bold text-stone-800 shadow-inner transition-all focus:border-ista-primary focus:outline-none focus:ring-4 focus:ring-ista-primary/10"
                            required
                            autofocus
                        >
                    </template>
                </div>
                @error('verification_code_input')
                    <span class="mt-1 block text-center text-xs text-rose-600">{{ $message }}</span>
                @enderror
            </div>

            <div class="mt-6 pt-2">
                <button type="submit" class="ista-login-button group w-full" wire:loading.attr="disabled" wire:target="verifyOtp">
                    <span class="relative z-10 flex items-center justify-center gap-2 text-[15px] font-semibold text-white transition-all duration-500 ease-out">
                        <span wire:loading.remove wire:target="verifyOtp">Verifikasi & Login</span>
                        <span wire:loading.flex wire:target="verifyOtp" class="items-center justify-center gap-2">
                            <svg class="h-4 w-4 animate-spin text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            Memproses...
                        </span>
                        <svg wire:loading.remove wire:target="verifyOtp" class="-ml-4 h-4 w-4 opacity-0 transition-all duration-500 group-hover:ml-0 group-hover:opacity-100" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                        </svg>
                    </span>
                    <div class="absolute inset-0 z-0 -translate-x-[150%] skew-x-12 bg-gradient-to-r from-transparent via-white/40 to-transparent transition-transform duration-1000 ease-in-out group-hover:translate-x-[150%]"></div>
                </button>

                <button
                    type="button"
                    wire:click="resendOtp"
                    wire:loading.attr="disabled"
                    wire:target="resendOtp"
                    class="mt-3 w-full rounded-xl border border-stone-300 bg-white px-4 py-3 text-sm font-semibold text-stone-700 transition-colors hover:border-stone-400 hover:text-stone-900 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    <span wire:loading.remove wire:target="resendOtp">Kirim Ulang OTP</span>
                    <span wire:loading wire:target="resendOtp">Mengirim ulang...</span>
                </button>
            </div>
        </form>
    </div>
</div>
