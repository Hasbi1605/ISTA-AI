@if ($paginator->hasPages())
    @php
        $totalPages = $paginator->lastPage();
        $currentPage = $paginator->currentPage();
        $paginationItems = [];

        if ($totalPages <= 7) {
            $paginationItems = range(1, $totalPages);
        } else {
            $pages = [
                1,
                $totalPages,
                $currentPage,
            ];

            if ($currentPage > 1) {
                $pages[] = $currentPage - 1;
            }

            if ($currentPage < $totalPages) {
                $pages[] = $currentPage + 1;
            }

            if ($currentPage <= 2) {
                $pages[] = 3;
            }

            if ($currentPage >= $totalPages - 1) {
                $pages[] = $totalPages - 2;
            }

            $pages = collect($pages)
                ->filter(fn (int $page): bool => $page >= 1 && $page <= $totalPages)
                ->unique()
                ->sort()
                ->values();

            $previousPage = null;

            foreach ($pages as $page) {
                if ($previousPage !== null) {
                    $gap = $page - $previousPage;

                    if ($gap === 2) {
                        $paginationItems[] = $previousPage + 1;
                    } elseif ($gap > 2) {
                        $paginationItems[] = 'ellipsis';
                    }
                }

                $paginationItems[] = $page;
                $previousPage = $page;
            }
        }
    @endphp

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

            @foreach ($paginationItems as $item)
                @if ($item === 'ellipsis')
                    <span class="admin-pagination__ellipsis" aria-hidden="true">&hellip;</span>
                    @continue
                @endif

                @if ($item == $currentPage)
                    <span class="admin-pagination__button admin-pagination__button--active" aria-current="page">{{ $item }}</span>
                @else
                    <button type="button"
                            class="admin-pagination__button"
                            wire:click="gotoPage({{ $item }}, '{{ $paginator->getPageName() }}')"
                            wire:loading.attr="disabled"
                            aria-label="Ke halaman {{ $item }}">
                        {{ $item }}
                    </button>
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
