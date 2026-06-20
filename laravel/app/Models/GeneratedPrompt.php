<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Riwayat paket prompt Prompy Studio (epic #218, child #263).
 *
 * Milik user (private). Menyimpan ide asli, paket prompt hasil generate, dan
 * metadata reference image privat. Tidak pernah memanggil platform eksternal.
 */
class GeneratedPrompt extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'platform',
        'platform_label',
        'prompt_type',
        'prompt_type_label',
        'title',
        'idea',
        'package',
        'source_document_ids',
        'contains_internal_context',
        'reference_image_path',
        'reference_image_mime',
        'reference_image_size_bytes',
        'model_label',
    ];

    protected function casts(): array
    {
        return [
            'package' => 'array',
            'source_document_ids' => 'array',
            'contains_internal_context' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOwnedBy(?User $user): bool
    {
        return $user !== null && (int) $user->id === (int) $this->user_id;
    }

    public function displayTitle(): string
    {
        $title = self::compactDisplayTitle((string) ($this->title ?: $this->idea));

        return $title !== '' ? $title : 'Paket Prompt';
    }

    public static function compactDisplayTitle(string $value, int $limit = 64): string
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', strip_tags($value)));
        if ($clean === '') {
            return '';
        }

        $withoutCommand = preg_replace(
            '/^(?:tolong|mohon|buatkan|buat|bikin|hasilkan|generate|susun|rancang|desain|create|please)\s+/iu',
            '',
            $clean
        ) ?: $clean;

        $firstPhrase = trim((string) (preg_split('/[\r\n.!?;]+/u', $withoutCommand)[0] ?? $withoutCommand));
        $topicParts = preg_split(
            '/\s+(?:dengan|yang|untuk|berisi|menggunakan|memakai|nuansa|gaya|tema|bertema|agar|supaya)\s+/iu',
            $firstPhrase,
            2
        );

        $candidate = trim((string) ($topicParts[0] ?? $firstPhrase), " \t\n\r\0\x0B,.-:;\"'()[]{}");
        if (mb_strlen($candidate) < 12 && mb_strlen($firstPhrase) > mb_strlen($candidate)) {
            $candidate = trim($firstPhrase, " \t\n\r\0\x0B,.-:;\"'()[]{}");
        }

        if ($candidate === '') {
            $candidate = $withoutCommand;
        }
        $candidate = Str::ucfirst($candidate);

        if (mb_strlen($candidate) > $limit) {
            $truncated = Str::limit($candidate, $limit, '');
            $candidate = trim((string) (preg_replace('/\s+\S*$/u', '', $truncated) ?: $truncated));
        }

        return $candidate !== '' ? $candidate : Str::limit($clean, $limit, '');
    }

    /**
     * @return array{main_prompt: string, variants: list<string>, negative_prompt: string, recommended_settings: array<string, string>, notes_id: string}
     */
    public function normalizedPackage(): array
    {
        $package = is_array($this->package) ? $this->package : [];

        return [
            'main_prompt' => (string) ($package['main_prompt'] ?? ''),
            'variants' => array_values(array_filter(
                is_array($package['variants'] ?? null) ? $package['variants'] : [],
                fn ($v) => is_string($v) && trim($v) !== '',
            )),
            'negative_prompt' => (string) ($package['negative_prompt'] ?? ''),
            'recommended_settings' => is_array($package['recommended_settings'] ?? null)
                ? $package['recommended_settings']
                : [],
            'notes_id' => (string) ($package['notes_id'] ?? ''),
        ];
    }
}
