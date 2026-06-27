<?php

namespace App\Http\Controllers\Prompts;

use App\Http\Controllers\Controller;
use App\Models\GeneratedPrompt;
use App\Models\GeneratedPromptVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serve reference images attached to Prompy prompt versions.
 *
 * Images are stored on the private `local` disk and may only be accessed
 * by the prompt owner.
 */
class PromptReferenceImageController extends Controller
{
    public function show(Request $request, GeneratedPrompt $prompt, int $imageIndex): Response
    {
        $user = $request->user();

        if (! $user || (int) $prompt->user_id !== (int) $user->id) {
            abort(403);
        }

        $prompt->loadMissing(['currentVersion', 'versions']);

        $versionId = $request->query('version');
        $version = $versionId
            ? $prompt->versions->firstWhere('id', (int) $versionId)
            : $prompt->currentVersion;

        if (! $version) {
            abort(404);
        }

        $images = is_array($version->reference_images) ? $version->reference_images : [];
        $image = $images[$imageIndex] ?? null;

        if (! is_array($image) || empty($image['path'])) {
            abort(404);
        }

        $path = (string) $image['path'];
        $mime = (string) ($image['mime'] ?? 'image/jpeg');

        if (! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        return response(Storage::disk('local')->get($path), 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=86400',
            'Content-Disposition' => 'inline',
        ]);
    }
}
