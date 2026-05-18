<?php

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Services\DocumentExportService;
use App\Services\Documents\DocumentExportHtmlSanitizer;
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

        $contentHtml = app(DocumentExportHtmlSanitizer::class)->sanitize($data['content_html']);

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
}
