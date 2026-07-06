<aside
    :class="[
        showRightSidebar ? 'opacity-100 translate-x-0' : 'opacity-0 translate-x-full pointer-events-none',
        isMobile ? 'fixed right-0 top-0 h-full w-[288px] shadow-2xl border-l border-stone-200/60 dark:border-[#1E293B]' : (showRightSidebar ? 'relative w-[288px] border-l border-stone-200/60 dark:border-[#1E293B]' : 'relative w-0 border-l border-transparent')
    ]"
    @click.stop
    class="z-50 flex-shrink-0 overflow-hidden bg-white dark:bg-gray-900 flex flex-col transform-gpu will-change-[width,transform,opacity] transition-[width,transform,opacity,border-color] duration-500 ease-[cubic-bezier(0.22,1,0.36,1)]">

    <div class="px-4 pt-5 pb-0 flex items-center justify-between">
        <span class="inline-flex items-center font-medium text-[13px] text-gray-700 dark:text-gray-200">
            <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M5 19a2 2 0 01-2-2V7a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1M5 19h14a2 2 0 002-2v-5a2 2 0 00-2-2H9a2 2 0 00-2 2v5a2 2 0 01-2 2z"/></svg>
            Semua Dokumen Saya
        </span>
        <button type="button" x-show="isMobile" @click="showRightSidebar = false" class="p-1.5 rounded-md hover:bg-gray-100 dark:hover:bg-gray-800 text-gray-500 dark:text-gray-400 transition-colors" aria-label="Tutup sidebar dokumen">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    </div>

    <div class="px-4 pt-4 space-y-2">
        <div wire:loading.flex wire:target="deleteDocument,deleteSelectedDocuments" class="items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-[11.5px] text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
            <span class="h-3.5 w-3.5 rounded-full border-2 border-current border-t-transparent animate-spin"></span>
            <span>Menghapus dokumen...</span>
        </div>

        @if (session()->has('message'))
            <div x-data="{ visible: true }" x-init="setTimeout(() => visible = false, 3500)" x-show="visible" x-transition class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-[11.5px] text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200">
                {{ session('message') }}
            </div>
        @endif

        @if (session()->has('error'))
            <div x-data="{ visible: true }" x-init="setTimeout(() => visible = false, 4000)" x-show="visible" x-transition class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-[11.5px] text-rose-800 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-200">
                {{ session('error') }}
            </div>
        @endif
    </div>

    {{-- Polling interval: 5s saat ada dokumen processing (turun dari 3s), 20s saat idle.
         Preview generation memakan 5-15 detik, poll 3s terlalu agresif.
         Rollback: ubah wire:poll.5s kembali ke wire:poll.3s --}}
    <div class="flex-1 overflow-y-auto px-4 pt-4" @if($hasDocumentsInProgress) wire:poll.5s="loadAvailableDocuments" @else wire:poll.20s="loadAvailableDocuments" @endif>
          <div class="mb-4">
              @php
                  $readyDocumentIds = $availableDocuments->where('status', 'ready')->pluck('id')->map(fn ($id) => (int) $id)->toArray();
                 $documentSelectorItems = $availableDocuments->map(fn ($doc) => [
                     'id' => (int) $doc->id,
                     'name' => (string) $doc->original_name,
                     'extension' => (string) ($doc->extension ?? strtolower(pathinfo($doc->original_name, PATHINFO_EXTENSION))),
                     'status' => (string) $doc->status,
                 ])->values();
                  $documentSelectorSignature = md5(json_encode($documentSelectorItems->pluck('status', 'id')->all()));
                  $selectedIds = array_map('intval', $selectedDocuments);
                 $selectedInAvailableCount = count(array_intersect($selectedIds, $readyDocumentIds));
                 $allDocumentsSelected = count($readyDocumentIds) > 0 && $selectedInAvailableCount === count($readyDocumentIds);
              @endphp
               <div wire:key="chat-document-selector-{{ $documentSelectorSignature }}" x-data="chatDocumentSelector({ selectedDocuments: $wire.entangle('selectedDocuments'), readyDocumentIds: @js($readyDocumentIds), availableDocuments: @js($documentSelectorItems) })">
              <div class="flex items-center flex-nowrap gap-1 mb-4 px-1 pb-3 border-b border-stone-200/60/70 dark:border-gray-800/70">
                  <button type="button" @click="toggleSelectAllDocuments()" :aria-pressed="allDocumentsSelected() ? 'true' : 'false'" class="inline-flex items-center gap-1.5 text-[#62748E] dark:text-[#90A1B9] hover:text-[#314158] dark:hover:text-white text-[11px] leading-[1.1] font-semibold px-1.5 py-1 rounded-md hover:bg-[#F1F5F9] dark:hover:bg-gray-800 transition-colors whitespace-nowrap">
                      <span x-show="allDocumentsSelected()" class="inline-flex items-center gap-1.5" style="{{ $allDocumentsSelected ? '' : 'display: none;' }}">
                          <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-ista-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                              <rect x="3" y="3" width="18" height="18" rx="4" stroke-width="2"></rect>
                              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12.5l2.8 2.8L16.8 9.3" />
                          </svg>
                           Batal
                      </span>
                      <span x-show="!allDocumentsSelected()" class="inline-flex items-center gap-1.5" style="{{ $allDocumentsSelected ? 'display: none;' : '' }}">
                          <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-[#64748B] dark:text-[#94A3B8]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                              <rect x="3" y="3" width="18" height="18" rx="4" stroke-width="2"></rect>
                          </svg>
                           Semua
                      </span>
                  </button>
                      <span x-show="selectedInAvailableCount() > 0" class="ml-auto shrink-0 text-[10.5px] font-semibold text-[#7C8DA8] dark:text-[#90A1B9]" style="{{ $selectedInAvailableCount > 0 ? '' : 'display: none;' }}">
                          <span x-text="selectedInAvailableCount()">{{ $selectedInAvailableCount }}</span> dipilih
                      </span>
                      <button type="button" x-show="selectedInAvailableCount() > 0" @click="deleteSelectedDocuments()" wire:loading.attr="disabled" wire:target="deleteSelectedDocuments" class="inline-flex h-7 w-7 shrink-0 items-center justify-center text-[#FF2056] rounded-md bg-[#FF2056]/10 hover:bg-[#FF2056]/20 transition-colors disabled:opacity-60" title="Hapus dokumen terpilih" aria-label="Hapus dokumen terpilih" style="{{ $selectedInAvailableCount > 0 ? '' : 'display: none;' }}">
                          <svg wire:loading.remove wire:target="deleteSelectedDocuments" xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                          </svg>
                          <span wire:loading.inline-flex wire:target="deleteSelectedDocuments" class="h-3 w-3 rounded-full border border-current border-t-transparent animate-spin"></span>
                      </button>
                      <button type="button" x-show="selectedInAvailableCount() > 0" @click="addSelectedDocumentsToChat().then(() => { if (isMobile) showRightSidebar = false; })" wire:loading.attr="disabled" wire:target="addSelectedDocumentsToChat" class="inline-flex h-7 shrink-0 items-center gap-1 rounded-md bg-ista-primary px-2.5 text-[10.5px] font-semibold text-white transition-all hover:bg-stone-800 whitespace-nowrap disabled:opacity-60" title="Tambahkan dokumen terpilih ke chat" aria-label="Tambahkan dokumen terpilih ke chat" style="{{ $selectedInAvailableCount > 0 ? '' : 'display: none;' }}">
                          <svg wire:loading.remove wire:target="addSelectedDocumentsToChat" xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                          </svg>
                          <span wire:loading.inline-flex wire:target="addSelectedDocumentsToChat" class="h-3 w-3 rounded-full border border-white/70 border-t-transparent animate-spin" aria-hidden="true"></span>
                           <span wire:loading.remove wire:target="addSelectedDocumentsToChat">Pakai</span>
                      </button>
              </div>

              @if(count($availableDocuments) > 0)
                  <div class="space-y-3">
                      @foreach($availableDocuments as $doc)
                           @php
                               $isSelected = in_array($doc->id, $selectedDocuments);
                               $isReady = $doc->status === 'ready';
                               $isLoading = in_array($doc->status, ['pending', 'processing']);
                               $isError = $doc->status === 'error';
                              $ext = $doc->extension ?? strtolower(pathinfo($doc->original_name, PATHINFO_EXTENSION));
                              $size = $doc->formatted_size ?? 'Ukuran tidak tersedia';
                          @endphp
                           <label class="flex items-center gap-3 min-h-[70px] px-3 py-2 rounded-lg border transition-all duration-200 {{ $isLoading ? 'animate-pulse cursor-wait' : ($isError ? 'cursor-default border-rose-200 bg-rose-50/50 dark:border-rose-500/30 dark:bg-rose-500/10' : 'cursor-pointer') }}"
                              :class="isSelected({{ (int) $doc->id }}) ? 'bg-white/95 dark:bg-gray-800 border-ista-primary/40 dark:border-ista-primary/40 shadow-[0_1px_4px_rgba(97,95,255,0.25)]' : 'bg-white dark:bg-gray-800 border-stone-200/60 dark:border-gray-700 hover:border-[#CBD5E1] dark:hover:border-gray-600'">
                              @if($isLoading)
                                   <div class="w-3.5 h-3.5 rounded-full border-2 border-[#CBD5E1] dark:border-[#334155] border-t-[#615FFF] dark:border-t-[#8E81FF] animate-spin"></div>
                               @elseif($isError)
                                   <span class="flex h-4 w-4 items-center justify-center rounded-full bg-rose-100 text-[10px] font-bold text-rose-700 dark:bg-rose-500/20 dark:text-rose-200" aria-label="Dokumen gagal diproses">!</span>
                               @else
                                  <input type="checkbox" x-model.number="selectedDocuments" value="{{ $doc->id }}"
                                      class="rounded text-ista-primary focus:ring-ista-primary bg-white dark:bg-transparent border-[#CBD5E1] dark:border-[#64748B] w-3.5 h-3.5 cursor-pointer aspect-square"
                                      {{ $isReady ? '' : 'disabled' }}>
                              @endif
                              <div class="h-[34px] w-[34px] rounded-lg bg-white/80 backdrop-blur-sm dark:bg-gray-800 border border-stone-200/60 dark:border-[#334155] flex items-center justify-center">
                                  @if($ext === 'pdf')
                                      <svg class="w-[18px] h-[18px] text-[#FF2056] shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4zm2 6a1 1 0 011-1h6a1 1 0 110 2H7a1 1 0 01-1-1zm1 3a1 1 0 100 2h6a1 1 0 100-2H7z" clip-rule="evenodd"></path></svg>
                                  @elseif($ext === 'txt')
                                      <svg class="w-[18px] h-[18px] text-[#62748E] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 3h7l5 5v13a1 1 0 01-1 1H7a1 1 0 01-1-1V4a1 1 0 011-1zm7 1v4h4M8 13h8M8 17h6" /></svg>
                                   @elseif(in_array($ext, ['xlsx', 'csv'], true))
                                       <svg class="w-[18px] h-[18px]" fill="none" viewBox="0 0 24 24" stroke="#32CD32"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 3h7l5 5v13a1 1 0 01-1 1H7a1 1 0 01-1-1V4a1 1 0 011-1zm7 1v4h4M8 13h8M8 17h8" /></svg>
                                   @elseif(in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'img']))
                                       <svg class="w-[18px] h-[18px] text-[#FD9A00] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 5h16a1 1 0 011 1v12a1 1 0 01-1 1H4a1 1 0 01-1-1V6a1 1 0 011-1zm4 4h.01M21 15l-5-5-7 7-3-3-3 3" /></svg>
                                   @else
                                       <svg class="w-[18px] h-[18px] text-[#2B7FFF] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 3h7l5 5v13a1 1 0 01-1 1H7a1 1 0 01-1-1V4a1 1 0 011-1zm7 1v4h4M8 13h8M8 17h8" /></svg>
                                   @endif
                              </div>
                              <div class="min-w-0 flex-1 flex flex-col gap-0.5">
                                  <div class="flex items-center gap-2">
                                     <p class="text-[13.3px] text-stone-800 dark:text-[#F8FAFC] truncate">{{ $doc->original_name }}</p>
                                      @if($isLoading)
                                         <span class="inline-flex items-center gap-1 text-[10px] text-ista-primary dark:text-[#A5B4FC]">
                                             <span class="h-1.5 w-1.5 rounded-full bg-current animate-ping"></span>
                                               Memproses
                                          </span>
                                      @elseif($isError)
                                          <span class="inline-flex items-center gap-1 rounded-full bg-rose-100 px-2 py-0.5 text-[10px] font-semibold text-rose-700 dark:bg-rose-500/20 dark:text-rose-200">
                                              Gagal
                                          </span>
                                      @endif
                                   </div>
                                   <p class="text-[11.4px] text-[#64748B] dark:text-[#94A3B8]">
                                       {{ $size }}
                                       @if($isLoading) • Sedang diproses, belum siap untuk konteks AI @endif
                                       @if($isError) • Tidak dipakai sebagai konteks AI sampai berhasil diproses ulang @endif
                                   </p>
                               </div>

                              @if($isReady)
                                  <button type="button"
                                      @click.prevent="$dispatch('open-document-preview', { documentId: {{ $doc->id }} })"
                                      class="h-7 w-7 rounded-md text-[#94A3B8] hover:text-ista-primary dark:hover:text-[#A5B4FC] hover:bg-stone-100 dark:hover:bg-gray-700 transition-colors flex items-center justify-center"
                                      title="Baca dokumen"
                                      aria-label="Baca dokumen">
                                      <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                      </svg>
                                  </button>
                              @endif

                              @if($isError)
                                  <button type="button" wire:click.prevent="reprocessDocument({{ $doc->id }})" wire:loading.attr="disabled" wire:target="reprocessDocument({{ $doc->id }})" class="h-7 w-7 rounded-md text-[#94A3B8] hover:text-ista-primary dark:hover:text-[#A5B4FC] hover:bg-stone-100 dark:hover:bg-gray-700 transition-colors flex items-center justify-center disabled:opacity-60" title="Coba proses ulang" aria-label="Coba proses ulang {{ $doc->original_name }}">
                                      <span wire:loading.remove wire:target="reprocessDocument({{ $doc->id }})">↻</span>
                                      <span wire:loading.inline-flex wire:target="reprocessDocument({{ $doc->id }})" class="h-3.5 w-3.5 rounded-full border border-current border-t-transparent animate-spin"></span>
                                  </button>
                              @endif

                              <button type="button" wire:click.prevent="deleteDocument({{ $doc->id }})" wire:confirm="Hapus permanen dokumen &quot;{{ $doc->original_name }}&quot;? File, embedding, dan preview akan dihapus sepenuhnya dan tidak bisa dipulihkan." wire:loading.attr="disabled" wire:target="deleteDocument({{ $doc->id }})" class="h-7 w-7 rounded-md text-[#94A3B8] hover:text-red-500 dark:hover:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10 focus-visible:text-red-500 focus-visible:bg-red-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-red-300 transition-colors flex items-center justify-center disabled:opacity-60" title="Hapus" aria-label="Hapus {{ $doc->original_name }}">
                                  <svg wire:loading.remove wire:target="deleteDocument({{ $doc->id }})" xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                  </svg>
                                  <span wire:loading.inline-flex wire:target="deleteDocument({{ $doc->id }})" class="h-3.5 w-3.5 rounded-full border border-current border-t-transparent animate-spin"></span>
                              </button>
                          </label>
                       @endforeach
                  </div>
              @else
                  <p class="text-[12px] text-gray-400 mt-6 px-1">Belum ada dokumen. Unggah PDF/DOCX/XLSX/CSV untuk menambahkan konteks chat.</p>
              @endif
              </div>
          </div>
    </div>
</aside>
