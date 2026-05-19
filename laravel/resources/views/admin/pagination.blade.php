@if ($paginator->hasPages())
    <nav class="admin-pagination" role="navigation" aria-label="Pagination">
        <p class="admin-pagination__summary">
            Menampilkan {{ number_format($paginator->firstItem() ?? 0) }}-{{ number_format($paginator->lastItem() ?? 0) }}
            dari {{ number_format($paginator->total()) }}
        </p>

        <div class="admin-pagination__nav">
            @if ($paginator->onFirstPage())
                <span class="admin-pagination__button admin-pagination__button--disabled" aria-disabled="true" aria-label="Halaman sebelumnya">‹</span>
            @else
                <button type="button"
                        class="admin-pagination__button"
                        wire:click="previousPage('{{ $paginator->getPageName() }}')"
                        wire:loading.attr="disabled"
                        rel="prev"
                        aria-label="Halaman sebelumnya">
                    ‹
                </button>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="admin-pagination__ellipsis" aria-hidden="true">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="admin-pagination__button admin-pagination__button--active" aria-current="page">{{ $page }}</span>
                        @else
                            <button type="button"
                                    class="admin-pagination__button"
                                    wire:click="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')"
                                    wire:loading.attr="disabled">
                                {{ $page }}
                            </button>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <button type="button"
                        class="admin-pagination__button"
                        wire:click="nextPage('{{ $paginator->getPageName() }}')"
                        wire:loading.attr="disabled"
                        rel="next"
                        aria-label="Halaman berikutnya">
                    ›
                </button>
            @else
                <span class="admin-pagination__button admin-pagination__button--disabled" aria-disabled="true" aria-label="Halaman berikutnya">›</span>
            @endif
        </div>
    </nav>
@else
    <div class="admin-pagination admin-pagination--single">
        <p class="admin-pagination__summary">
            Menampilkan {{ number_format($paginator->total()) }} dari {{ number_format($paginator->total()) }}
        </p>
    </div>
@endif
