<?php

namespace App\Http\Controllers\Presentations;

use App\Http\Controllers\Controller;
use App\Models\Presentation;
use App\Models\PresentationVersion;
use App\Services\OnlyOffice\ForceSaveException;
use App\Services\OnlyOffice\PresentationConverter;
use App\Services\OnlyOffice\PresentationDocumentKey;
use App\Services\OnlyOffice\PresentationForceSaveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class PresentationFileController extends Controller
{
    public const PPTX_MEDIA_TYPE = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';

    /**
     * Download PPTX (owner only). Memakai versi aktif (terbaru hasil edit).
     */
    public function downloadPptx(Request $request, Presentation $presentation): BinaryFileResponse
    {
        $this->authorizeView($request, $presentation);

        $version = $this->resolveVersion($request, $presentation);
        $path = $version?->pptx_path ?: $presentation->pptx_path;
        abort_if(! $path, Response::HTTP_NOT_FOUND, 'File presentasi belum tersedia.');

        $absolute = Storage::disk('local')->path($path);
        abort_unless(is_file($absolute), Response::HTTP_NOT_FOUND, 'File presentasi tidak ditemukan.');

        return response()->download($absolute, $this->fileName($presentation, 'pptx'), [
            'Content-Type' => self::PPTX_MEDIA_TYPE,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Download PDF (owner only). Konversi server-side dari versi aktif & cache ke disk.
     */
    public function downloadPdf(Request $request, Presentation $presentation, PresentationConverter $converter): Response
    {
        $this->authorizeView($request, $presentation);

        $version = $this->resolveVersion($request, $presentation);
        abort_unless($version?->pptx_path ?: $presentation->pptx_path, Response::HTTP_NOT_FOUND, 'File presentasi belum tersedia.');

        $pdf = $this->resolvePdf($presentation, $version, $converter);

        return response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->fileName($presentation, 'pdf').'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Force-save sesi editor OnlyOffice sebelum download/export (#226), agar
     * unduhan memakai versi terbaru hasil edit manual.
     */
    public function forceSave(Request $request, Presentation $presentation, PresentationForceSaveService $forceSave): JsonResponse
    {
        $this->authorizeView($request, $presentation);

        $version = $this->resolveVersion($request, $presentation, allowBodyVersionId: true);

        try {
            $result = $forceSave->forceSave($presentation, $version);
        } catch (ForceSaveException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], $e->responseStatus());
        }

        return response()->json($result);
    }

    /**
     * Signed file endpoint untuk OnlyOffice editor/converter. Selain tanda tangan
     * HMAC, wajib menyertakan oo_token presentasi-bound single-use agar URL tidak
     * bisa di-replay sebagai bearer publik.
     */
    public function signed(Request $request, Presentation $presentation): BinaryFileResponse
    {
        abort_unless(
            app(PresentationDocumentKey::class)->hasValidSignedFileSignature($request),
            Response::HTTP_FORBIDDEN
        );

        $user = $request->user();
        if ($user !== null) {
            abort_if($user->cannot('view', $presentation), Response::HTTP_FORBIDDEN);
        } else {
            abort_unless($this->isTrustedOnlyOfficeFileRequest($request), Response::HTTP_FORBIDDEN);
        }

        $version = $this->resolveVersion($request, $presentation);
        $versionId = $version?->id ?? $this->requestedVersionId($request);
        $ooToken = $request->query('oo_token', '');
        abort_unless(
            is_string($ooToken) && $ooToken !== ''
                && app(PresentationDocumentKey::class)->validateFileToken($ooToken, $presentation, $versionId),
            Response::HTTP_FORBIDDEN,
            'Token akses presentasi tidak valid atau sudah kedaluwarsa.'
        );

        $path = $version?->pptx_path ?: $presentation->pptx_path;
        abort_if(! $path, Response::HTTP_NOT_FOUND);

        $absolute = Storage::disk('local')->path($path);
        abort_unless(is_file($absolute), Response::HTTP_NOT_FOUND);

        return response()->file($absolute, [
            'Content-Type' => self::PPTX_MEDIA_TYPE,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
            'Content-Disposition' => 'inline; filename="'.$this->fileName($presentation, 'pptx').'"',
        ]);
    }

    protected function resolvePdf(Presentation $presentation, ?PresentationVersion $version, PresentationConverter $converter): string
    {
        // Cache PDF hanya untuk versi aktif (current). pdf_path di-invalidasi oleh
        // job generate dan callback edit setiap kali PPTX versi aktif berubah,
        // sehingga unduhan selalu memakai versi terbaru.
        $isActiveVersion = $version === null
            || (int) $version->id === (int) $presentation->current_version_id;

        if ($isActiveVersion && $presentation->pdf_path && Storage::disk('local')->exists($presentation->pdf_path)) {
            return (string) Storage::disk('local')->get($presentation->pdf_path);
        }

        try {
            $pdf = $converter->presentationToPdf($presentation, $version);
        } catch (RuntimeException $e) {
            report($e);
            abort(Response::HTTP_BAD_GATEWAY, 'Gagal membuat PDF presentasi. Silakan coba lagi nanti.');
        }

        if ($isActiveVersion) {
            $pdfPath = 'presentations/'.$presentation->user_id.'/'.$presentation->id.'-'.Str::uuid().'.pdf';
            Storage::disk('local')->put($pdfPath, $pdf);
            $presentation->forceFill(['pdf_path' => $pdfPath])->save();
        }

        return $pdf;
    }

    protected function resolveVersion(Request $request, Presentation $presentation, bool $allowBodyVersionId = false): ?PresentationVersion
    {
        $versionId = $this->requestedVersionId($request, $allowBodyVersionId);

        if ($versionId !== null) {
            return PresentationVersion::query()
                ->where('presentation_id', $presentation->id)
                ->whereKey($versionId)
                ->firstOrFail();
        }

        $presentation->loadMissing('currentVersion');

        return $presentation->currentVersion
            ?: $presentation->versions()->orderByDesc('version_number')->first();
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

    protected function fileName(Presentation $presentation, string $extension): string
    {
        $base = preg_replace('/[^A-Za-z0-9_.-]+/', '-', trim((string) $presentation->title)) ?: 'presentasi';
        $base = trim($base, '-_.') ?: 'presentasi';

        return $base.'.'.strtolower($extension);
    }

    protected function authorizeView(Request $request, Presentation $presentation): void
    {
        $user = $request->user();

        abort_if($user === null, Response::HTTP_UNAUTHORIZED);
        abort_if($user->cannot('view', $presentation), Response::HTTP_FORBIDDEN);
    }

    protected function isTrustedOnlyOfficeFileRequest(Request $request): bool
    {
        $internalUrl = (string) config('services.onlyoffice.laravel_internal_url', '');
        $parts = parse_url($internalUrl);

        if (! is_array($parts) || empty($parts['host'])) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
        $defaultPort = $scheme === 'https' ? 443 : 80;

        return strtolower($request->getHost()) === strtolower((string) $parts['host'])
            && (int) $request->getPort() === (int) ($parts['port'] ?? $defaultPort);
    }
}
