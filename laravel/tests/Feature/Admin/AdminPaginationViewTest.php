<?php

namespace Tests\Feature\Admin;

use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

class AdminPaginationViewTest extends TestCase
{
    public function test_admin_pagination_keeps_all_page_numbers_for_short_lists(): void
    {
        $html = $this->renderPagination(total: 30, perPage: 5, currentPage: 1);

        $this->assertStringContainsString('class="pagination admin-pagination__list"', $html);
        $this->assertStringContainsString('class="page-item admin-pagination__item active"', $html);
        $this->assertStringContainsString('class="page-link admin-pagination__link admin-pagination__link--active">1</span>', $html);
        $this->assertStringContainsString("gotoPage(2, 'page')", $html);
        $this->assertStringContainsString("gotoPage(3, 'page')", $html);
        $this->assertStringContainsString("gotoPage(4, 'page')", $html);
        $this->assertStringContainsString("gotoPage(5, 'page')", $html);
        $this->assertStringContainsString("gotoPage(6, 'page')", $html);
        $this->assertStringNotContainsString('admin-pagination__link--ellipsis', $html);
    }

    public function test_admin_pagination_uses_ellipsis_for_large_lists_near_the_start(): void
    {
        $html = $this->renderPagination(total: 150, perPage: 5, currentPage: 1);

        $this->assertStringContainsString('class="page-link admin-pagination__link admin-pagination__link--active">1</span>', $html);
        $this->assertStringContainsString("gotoPage(2, 'page')", $html);
        $this->assertStringContainsString("gotoPage(3, 'page')", $html);
        $this->assertStringContainsString("gotoPage(30, 'page')", $html);
        $this->assertSame(1, substr_count($html, 'admin-pagination__link--ellipsis'));
        $this->assertStringNotContainsString("gotoPage(4, 'page')", $html);
    }

    public function test_admin_pagination_keeps_context_around_the_current_page_for_large_lists(): void
    {
        $html = $this->renderPagination(total: 150, perPage: 5, currentPage: 8);

        $this->assertStringContainsString("gotoPage(1, 'page')", $html);
        $this->assertStringContainsString("gotoPage(7, 'page')", $html);
        $this->assertStringContainsString('class="page-link admin-pagination__link admin-pagination__link--active">8</span>', $html);
        $this->assertStringContainsString("gotoPage(9, 'page')", $html);
        $this->assertStringContainsString("gotoPage(30, 'page')", $html);
        $this->assertSame(2, substr_count($html, 'admin-pagination__link--ellipsis'));
        $this->assertStringNotContainsString("gotoPage(2, 'page')", $html);
        $this->assertStringNotContainsString("gotoPage(6, 'page')", $html);
        $this->assertStringNotContainsString("gotoPage(10, 'page')", $html);
    }

    public function test_admin_pagination_keeps_middle_context_for_ten_page_usage_lists(): void
    {
        $html = $this->renderPagination(total: 46, perPage: 5, currentPage: 4);

        $this->assertStringContainsString('Menampilkan 16-20', $html);
        $this->assertStringContainsString("gotoPage(1, 'page')", $html);
        $this->assertStringContainsString("gotoPage(2, 'page')", $html);
        $this->assertStringContainsString("gotoPage(3, 'page')", $html);
        $this->assertStringContainsString('class="page-link admin-pagination__link admin-pagination__link--active">4</span>', $html);
        $this->assertStringContainsString("gotoPage(5, 'page')", $html);
        $this->assertStringContainsString("gotoPage(10, 'page')", $html);
        $this->assertSame(1, substr_count($html, 'admin-pagination__link--ellipsis'));
        $this->assertStringContainsString('wire:key="admin-pagination-page-page-4"', $html);
    }

    public function test_admin_pagination_summary_is_correct_on_last_partial_page(): void
    {
        $html = $this->renderPagination(total: 46, perPage: 5, currentPage: 10);

        $this->assertStringContainsString('Menampilkan 46-46', $html);
        $this->assertStringContainsString('dari 46', $html);
        $this->assertStringContainsString("gotoPage(1, 'page')", $html);
        $this->assertStringContainsString("gotoPage(8, 'page')", $html);
        $this->assertStringContainsString("gotoPage(9, 'page')", $html);
        $this->assertStringContainsString('class="page-link admin-pagination__link admin-pagination__link--active">10</span>', $html);
        $this->assertStringContainsString('aria-label="Halaman berikutnya"', $html);
        $this->assertStringContainsString('admin-pagination__link--disabled', $html);
    }

    private function renderPagination(int $total, int $perPage, int $currentPage): string
    {
        $offset = max(0, ($currentPage - 1) * $perPage);
        $itemCount = max(0, min($perPage, $total - $offset));

        $paginator = new LengthAwarePaginator(
            items: $itemCount > 0 ? range(1, $itemCount) : [],
            total: $total,
            perPage: $perPage,
            currentPage: $currentPage,
            options: ['path' => '/admin/usage'],
        );

        return view('admin.pagination', ['paginator' => $paginator])->render();
    }
}
