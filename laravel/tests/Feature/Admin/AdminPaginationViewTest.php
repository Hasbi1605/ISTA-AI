<?php

namespace Tests\Feature\Admin;

use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

class AdminPaginationViewTest extends TestCase
{
    public function test_admin_pagination_keeps_all_page_numbers_for_short_lists(): void
    {
        $html = $this->renderPagination(total: 30, perPage: 5, currentPage: 1);

        $this->assertStringContainsString('aria-current="page">1</span>', $html);
        $this->assertStringContainsString("gotoPage(2, 'page')", $html);
        $this->assertStringContainsString("gotoPage(3, 'page')", $html);
        $this->assertStringContainsString("gotoPage(4, 'page')", $html);
        $this->assertStringContainsString("gotoPage(5, 'page')", $html);
        $this->assertStringContainsString("gotoPage(6, 'page')", $html);
        $this->assertStringNotContainsString('admin-pagination__ellipsis', $html);
    }

    public function test_admin_pagination_uses_ellipsis_for_large_lists_near_the_start(): void
    {
        $html = $this->renderPagination(total: 150, perPage: 5, currentPage: 1);

        $this->assertStringContainsString('aria-current="page">1</span>', $html);
        $this->assertStringContainsString("gotoPage(2, 'page')", $html);
        $this->assertStringContainsString("gotoPage(3, 'page')", $html);
        $this->assertStringContainsString("gotoPage(30, 'page')", $html);
        $this->assertSame(1, substr_count($html, 'admin-pagination__ellipsis'));
        $this->assertStringNotContainsString("gotoPage(4, 'page')", $html);
    }

    public function test_admin_pagination_keeps_context_around_the_current_page_for_large_lists(): void
    {
        $html = $this->renderPagination(total: 150, perPage: 5, currentPage: 8);

        $this->assertStringContainsString("gotoPage(1, 'page')", $html);
        $this->assertStringContainsString("gotoPage(7, 'page')", $html);
        $this->assertStringContainsString('aria-current="page">8</span>', $html);
        $this->assertStringContainsString("gotoPage(9, 'page')", $html);
        $this->assertStringContainsString("gotoPage(30, 'page')", $html);
        $this->assertSame(2, substr_count($html, 'admin-pagination__ellipsis'));
        $this->assertStringNotContainsString("gotoPage(2, 'page')", $html);
        $this->assertStringNotContainsString("gotoPage(6, 'page')", $html);
        $this->assertStringNotContainsString("gotoPage(10, 'page')", $html);
    }

    private function renderPagination(int $total, int $perPage, int $currentPage): string
    {
        $paginator = new LengthAwarePaginator(
            items: range(1, min($perPage, $total)),
            total: $total,
            perPage: $perPage,
            currentPage: $currentPage,
            options: ['path' => '/admin/usage'],
        );

        return view('admin.pagination', ['paginator' => $paginator])->render();
    }
}
