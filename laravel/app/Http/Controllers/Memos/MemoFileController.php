<?php

namespace App\Http\Controllers\Memos;

use App\Http\Controllers\Controller;
use App\Models\Memo;
use App\Models\MemoVersion;
use App\Services\OnlyOffice\DocumentConverter;
use App\Services\OnlyOffice\ForceSaveException;
use App\Services\OnlyOffice\MemoForceSaveService;
use App\Services\OnlyOffice\MemoDocumentKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class MemoFileController extends Controller
{
    public function signed(Request $request, Memo $memo): BinaryFileResponse
    {
        abort_unless($request->hasValidSignature(false), Response::HTTP_FORBIDDEN);

        // Require a valid memo-bound session token (oo_token) generated at editor-open
        // time. The token is random, TTL-limited, and stored in cache — not derivable
        // from the URL itself, preventing replay by anyone who captures the signed URL.
        $versionId = $this->requestedVersionId($request);
        $ooToken = $request->query('oo_token', '');
        abort_unless(
            is_string($ooToken) && $ooToken !== '' && app(MemoDocumentKey::class)->validateFileToken($ooToken, $memo, $versionId),
            Response::HTTP_FORBIDDEN,
            'Token akses memo tidak valid atau sudah kedaluwarsa.'
        );

        $version = $this->resolveVersion($request, $memo);

        return $this->fileResponse($memo, 'inline', $version);
    }

    public function download(Request $request, Memo $memo): BinaryFileResponse
    {
        $this->authorizeView($request, $memo);

        $version = $this->resolveVersion($request, $memo);

        return $this->fileResponse($memo, 'attachment', $version);
    }

    public function exportPdf(Request $request, Memo $memo, DocumentConverter $converter): Response
    {
        $this->authorizeView($request, $memo);

        $version = $this->resolveVersion($request, $memo);
        $pdf = $converter->memoToPdf($memo, $version);

        return response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$converter->fileName($memo, 'pdf').'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function forceSave(Request $request, Memo $memo, MemoForceSaveService $forceSave): JsonResponse
    {
        $this->authorizeView($request, $memo);

        $version = $this->resolveVersion($request, $memo, allowBodyVersionId: true);

        try {
            $result = $forceSave->forceSave($memo, $version);
        } catch (ForceSaveException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], $e->responseStatus());
        }

        return response()->json($result);
    }

    protected function fileResponse(Memo $memo, string $disposition, ?MemoVersion $version = null): BinaryFileResponse
    {
        $path = $version?->file_path ?: $memo->file_path;

        abort_if(! $path, Response::HTTP_NOT_FOUND);

        $absolute = Storage::disk('local')->path($path);
        abort_unless(is_file($absolute), Response::HTTP_NOT_FOUND);

        $fileName = $this->fileName($memo, 'docx');
        $headers = [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ];

        if ($disposition === 'attachment') {
            return response()->download($absolute, $fileName, $headers);
        }

        return response()->file($absolute, array_merge($headers, [
            'Content-Disposition' => 'inline; filename="'.$fileName.'"',
        ]));
    }

    protected function fileName(Memo $memo, string $extension): string
    {
        $base = preg_replace('/[^A-Za-z0-9_.-]+/', '-', trim($memo->title)) ?: 'memo';
        $base = trim($base, '-_.') ?: 'memo';

        return $base.'.'.strtolower($extension);
    }

    protected function resolveVersion(Request $request, Memo $memo, bool $allowBodyVersionId = false): ?MemoVersion
    {
        $versionId = $this->requestedVersionId($request, $allowBodyVersionId);

        if ($versionId !== null) {
            return MemoVersion::query()
                ->where('memo_id', $memo->id)
                ->whereKey($versionId)
                ->firstOrFail();
        }

        $memo->loadMissing('currentVersion');

        return $memo->currentVersion
            ?: $memo->versions()->orderByDesc('version_number')->first();
    }

    protected function requestedVersionId(Request $request, bool $allowBodyVersionId = false): ?int
    {
        $versionId = $request->query('version_id');

        if (($versionId === null || $versionId === '') && $allowBodyVersionId) {
            $versionId = $request->input('version_id');
        }

        if ($versionId === null || $versionId === '') {
            return null;
        }

        abort_unless(is_numeric($versionId), Response::HTTP_NOT_FOUND);

        return (int) $versionId;
    }

    protected function authorizeView(Request $request, Memo $memo): void
    {
        $user = $request->user();

        abort_if($user === null, Response::HTTP_UNAUTHORIZED);
        abort_if($user->cannot('view', $memo), Response::HTTP_FORBIDDEN);
    }
}
