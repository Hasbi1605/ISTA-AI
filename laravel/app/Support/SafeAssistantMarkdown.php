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

        return $this->stripResourceLoadingHtml($html);
    }

    private function stripResourceLoadingHtml(string $html): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        try {
            $document->loadHTML(
                '<!DOCTYPE html><html><body><div>'.$html.'</div></body></html>',
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
