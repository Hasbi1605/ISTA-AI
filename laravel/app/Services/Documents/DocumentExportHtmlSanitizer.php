<?php

namespace App\Services\Documents;

use DOMDocument;
use DOMElement;
use DOMNode;

class DocumentExportHtmlSanitizer
{
    public function sanitize(string $contentHtml): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        try {
            $document->loadHTML(
                '<?xml encoding="UTF-8"><!DOCTYPE html><html><body><div>'.$contentHtml.'</div></body></html>',
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

        $this->sanitizeNodeChildren($root);

        return $this->innerHtml($root);
    }

    private function sanitizeNodeChildren(DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $tagName = strtolower($child->tagName);

            if ($this->shouldDropElement($tagName)) {
                $child->parentNode?->removeChild($child);

                continue;
            }

            if (! $this->isAllowedElement($tagName)) {
                $this->sanitizeNodeChildren($child);
                $this->unwrapElement($child);

                continue;
            }

            $this->sanitizeAttributes($child, $tagName);
            $this->sanitizeNodeChildren($child);
        }
    }

    private function sanitizeAttributes(DOMElement $element, string $tagName): void
    {
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->name);
            $value = trim($attribute->value);

            if (! $this->isAllowedAttribute($tagName, $name, $value)) {
                $element->removeAttributeNode($attribute);
            }
        }
    }

    private function isAllowedElement(string $tagName): bool
    {
        return in_array($tagName, [
            'a', 'article', 'blockquote', 'br', 'code', 'div', 'em', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'li', 'ol', 'p', 'pre', 'section', 'span', 'strong', 'table', 'tbody', 'td', 'tfoot', 'th',
            'thead', 'tr', 'ul',
        ], true);
    }

    private function shouldDropElement(string $tagName): bool
    {
        return in_array($tagName, [
            'base', 'button', 'embed', 'form', 'iframe', 'img', 'input', 'link', 'math', 'meta', 'object',
            'picture', 'script', 'select', 'source', 'style', 'svg', 'template', 'textarea', 'video', 'audio',
        ], true);
    }

    private function isAllowedAttribute(string $tagName, string $name, string $value): bool
    {
        if (str_starts_with($name, 'on') || $name === 'style') {
            return false;
        }

        if (in_array($tagName, ['td', 'th'], true) && in_array($name, ['colspan', 'rowspan'], true)) {
            return preg_match('/^\d{1,2}$/', $value) === 1 && (int) $value >= 1 && (int) $value <= 20;
        }

        if ($tagName === 'a' && $name === 'href') {
            return $this->isSafeHref($value);
        }

        return false;
    }

    private function isSafeHref(string $value): bool
    {
        if ($value === '' || str_starts_with($value, '#') || str_starts_with($value, '/')) {
            return true;
        }

        $scheme = parse_url($value, PHP_URL_SCHEME);

        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https', 'mailto'], true);
    }

    private function unwrapElement(DOMElement $element): void
    {
        $parent = $element->parentNode;

        if ($parent === null) {
            return;
        }

        while ($element->firstChild) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
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
