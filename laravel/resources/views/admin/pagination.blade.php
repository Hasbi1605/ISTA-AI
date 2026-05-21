@if ($paginator->hasPages())
    @php
        $totalPages = $paginator->lastPage();
        $currentPage = $paginator->currentPage();
        $pageName = $paginator->getPageName();
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
            <ul class="pagination admin-pagination__list">
            @if ($paginator->onFirstPage())
                <li class="page-item admin-pagination__item disabled" aria-disabled="true" aria-label="Halaman sebelumnya" wire:key="admin-pagination-{{ $pageName }}-previous-disabled">
                    <span class="page-link admin-pagination__link admin-pagination__link--disabled" aria-hidden="true">‹</span>
                </li>
            @else
                <li class="page-item admin-pagination__item" wire:key="admin-pagination-{{ $pageName }}-previous">
                    <button type="button"
                            class="page-link admin-pagination__link"
                            wire:click="previousPage('{{ $pageName }}')"
                            wire:loading.attr="disabled"
                            rel="prev"
                            aria-label="Halaman sebelumnya">
                        ‹
                    </button>
                </li>
            @endif

            @foreach ($paginationItems as $index => $item)
                @if ($item === 'ellipsis')
                    <li class="page-item admin-pagination__item disabled" aria-disabled="true" wire:key="admin-pagination-{{ $pageName }}-ellipsis-{{ $index }}">
                        <span class="page-link admin-pagination__link admin-pagination__link--ellipsis" aria-hidden="true">&hellip;</span>
                    </li>
                    @continue
                @endif

                @if ($item == $currentPage)
                    <li class="page-item admin-pagination__item active" aria-current="page" wire:key="admin-pagination-{{ $pageName }}-page-{{ $item }}">
                        <span class="page-link admin-pagination__link admin-pagination__link--active">{{ $item }}</span>
                    </li>
                @else
                    <li class="page-item admin-pagination__item" wire:key="admin-pagination-{{ $pageName }}-page-{{ $item }}">
                        <button type="button"
                                class="page-link admin-pagination__link"
                                wire:click="gotoPage({{ $item }}, '{{ $pageName }}')"
                                wire:loading.attr="disabled"
                                aria-label="Ke halaman {{ $item }}">
                            {{ $item }}
                        </button>
                    </li>
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <li class="page-item admin-pagination__item" wire:key="admin-pagination-{{ $pageName }}-next">
                    <button type="button"
                            class="page-link admin-pagination__link"
                            wire:click="nextPage('{{ $pageName }}')"
                            wire:loading.attr="disabled"
                            rel="next"
                            aria-label="Halaman berikutnya">
                        ›
                    </button>
                </li>
            @else
                <li class="page-item admin-pagination__item disabled" aria-disabled="true" aria-label="Halaman berikutnya" wire:key="admin-pagination-{{ $pageName }}-next-disabled">
                    <span class="page-link admin-pagination__link admin-pagination__link--disabled" aria-hidden="true">›</span>
                </li>
            @endif
            </ul>
        </div>
    </nav>
@else
    <div class="admin-pagination admin-pagination--single">
        <p class="admin-pagination__summary">
            Menampilkan {{ number_format($paginator->total()) }} dari {{ number_format($paginator->total()) }}
        </p>
    </div>
@endif
