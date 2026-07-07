<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Support\Str;

class SafeAssistantMarkdown
{
    /**
     * Render assistant markdown while removing tags that can trigger browser
     * requests to attacker-controlled resources.
     */
    public function toHtml(string $markdown): string
    {
        $html = (string) Str::of($markdown)->markdown([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        $html = $this->stripResourceLoadingHtml($html);
        $html = $this->enhanceCodeBlocks($html);
        $html = $this->wrapTables($html);

        return $html;
    }

    private function stripResourceLoadingHtml(string $html): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        try {
            $document->loadHTML(
                '<?xml encoding="UTF-8"><!DOCTYPE html><html><body><div>'.$html.'</div></body></html>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $root = $document->getElementsByTagName('div')->item(0);

        if (! $root instanceof DOMElement) {
            return '';
        }

        $this->sanitizeChildren($root);

        return $this->innerHtml($root);
    }

    private function enhanceCodeBlocks(string $html): string
    {
        return (string) preg_replace_callback(
            '/<pre>\s*<code(?:\s+class="language-([^"]*)")?>(.*?)<\/code>\s*<\/pre>/is',
            function (array $matches): string {
                $language = strtolower(trim((string) ($matches[1] ?? '')));
                $language = $language !== '' ? $language : 'text';
                $code = (string) ($matches[2] ?? '');

                return implode('', [
                    '<div class="ista-code-block" data-ista-code-block>',
                    '<div class="ista-code-block__header">',
                    '<span class="ista-code-block__lang">'.htmlspecialchars($language, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</span>',
                    '<button type="button" class="ista-code-block__copy" data-ista-copy-code aria-label="Salin kode">Salin</button>',
                    '</div>',
                    '<pre><code class="language-'.htmlspecialchars($language, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">'.$code.'</code></pre>',
                    '</div>',
                ]);
            },
            $html,
        );
    }

    private function wrapTables(string $html): string
    {
        if (! str_contains($html, '<table')) {
            return $html;
        }

        return (string) preg_replace(
            '/<table(\s|>)/',
            '<div class="ista-table-wrap overflow-x-auto"><table$1',
            str_replace('</table>', '</table></div>', $html),
        );
    }

    private function sanitizeChildren(DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $tagName = strtolower($child->tagName);

            if ($this->isResourceLoadingTag($tagName)) {
                $child->parentNode?->removeChild($child);

                continue;
            }

            $this->sanitizeAttributes($child);
            $this->sanitizeChildren($child);
        }
    }

    private function sanitizeAttributes(DOMElement $element): void
    {
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->name);

            if (str_starts_with($name, 'on') || in_array($name, ['src', 'srcset', 'style'], true)) {
                $element->removeAttributeNode($attribute);
            }
        }
    }

    private function isResourceLoadingTag(string $tagName): bool
    {
        return in_array($tagName, [
            'audio', 'base', 'embed', 'frame', 'iframe', 'img', 'link', 'math', 'meta', 'object',
            'picture', 'script', 'source', 'style', 'svg', 'template', 'track', 'video',
        ], true);
    }

    private function innerHtml(DOMNode $node): string
    {
        $html = '';

        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument?->saveHTML($child) ?: '';
        }

        return $html;
    }
}
