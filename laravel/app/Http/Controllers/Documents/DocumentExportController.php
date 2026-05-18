<?php

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Services\DocumentExportService;
use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DocumentExportController extends Controller
{
    public function export(Request $request, DocumentExportService $exportService): Response
    {
        $data = $request->validate([
            'content_html' => ['required', 'string', 'max:512000'],
            'target_format' => ['required', 'in:pdf,docx,xlsx,csv'],
            'file_name' => ['nullable', 'string', 'max:120'],
        ]);

        $contentHtml = $this->sanitizeExportHtml($data['content_html']);

        if ($this->formatRequiresTable($data['target_format']) && ! $this->containsHtmlTable($contentHtml ?? '')) {
            return response('Format spreadsheet hanya tersedia untuk konten yang memiliki tabel.', Response::HTTP_UNPROCESSABLE_ENTITY, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'no-store',
            ]);
        }

        $artifact = $exportService->exportContent(
            $contentHtml ?? '',
            $data['target_format'],
            $data['file_name'] ?? null,
        );

        return response($artifact['body'], Response::HTTP_OK, [
            'Content-Type' => $artifact['content_type'],
            'Content-Disposition' => 'attachment; filename="'.$artifact['file_name'].'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function extractTables(Request $request, Document $document, DocumentExportService $exportService): JsonResponse
    {
        $this->authorizeView($request, $document);

        $result = $exportService->extractTables($document);

        return response()->json($result);
    }

    public function extractContent(Request $request, Document $document, DocumentExportService $exportService): JsonResponse
    {
        $this->authorizeView($request, $document);

        $result = $exportService->extractContent($document);

        return response()->json($result);
    }

    protected function authorizeView(Request $request, Document $document): void
    {
        $user = $request->user();

        if ($user === null) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        if ($user->cannot('view', $document)) {
            abort(Response::HTTP_FORBIDDEN);
        }
    }

    private function formatRequiresTable(string $targetFormat): bool
    {
        return in_array(strtolower(trim($targetFormat)), ['xlsx', 'csv'], true);
    }

    private function containsHtmlTable(string $contentHtml): bool
    {
        return preg_match('/<table\b/i', $contentHtml) === 1;
    }

    private function sanitizeExportHtml(string $contentHtml): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        try {
            $document->loadHTML(
                '<!DOCTYPE html><html><body><div>'.$contentHtml.'</div></body></html>',
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
            return str_starts_with($value, '#');
        }

        return false;
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
