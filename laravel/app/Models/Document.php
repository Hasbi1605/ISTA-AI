<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Document extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'filename',
        'original_name',
        'file_path',
        'source_provider',
        'source_external_id',
        'source_synced_at',
        'preview_html_path',
        'preview_status',
        'mime_type',
        'file_size_bytes',
        'status',
    ];

    public const PREVIEW_STATUS_PENDING = 'pending';

    public const PREVIEW_STATUS_READY = 'ready';

    public const PREVIEW_STATUS_FAILED = 'failed';

    public const PDF_MIME_TYPES = [
        'application/pdf',
    ];

    public const DOCX_MIME_TYPES = [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    public const XLSX_MIME_TYPES = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public const CSV_MIME_TYPES = [
        'text/csv',
        'text/plain',
        'application/csv',
        'application/vnd.ms-excel',
    ];

    public static function attachmentFileExtensions(): array
    {
        return ['pdf', 'docx', 'xlsx', 'csv'];
    }

    public static function attachmentMimeTypes(): array
    {
        return [
            ...self::PDF_MIME_TYPES,
            ...self::DOCX_MIME_TYPES,
            ...self::XLSX_MIME_TYPES,
            ...self::CSV_MIME_TYPES,
        ];
    }

    protected function casts(): array
    {
        return [
            'file_size_bytes' => 'integer',
            'source_synced_at' => 'datetime',
        ];
    }

    protected function formattedSize(): Attribute
    {
        return Attribute::make(
            get: function () {
                $bytes = $this->file_size_bytes;

                if ($bytes === null || $bytes < 1) {
                    return 'Ukuran tidak tersedia';
                }

                if ($bytes >= 1048576) {
                    return number_format($bytes / 1048576, 1).' MB';
                }

                return number_format(max($bytes / 1024, 0.1), 1).' KB';
            }
        );
    }

    protected function extension(): Attribute
    {
        return Attribute::make(
            get: fn () => strtolower((string) pathinfo((string) $this->original_name, PATHINFO_EXTENSION))
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(DocumentChunk::class);
    }

    public function cloudStorageFiles(): MorphMany
    {
        return $this->morphMany(CloudStorageFile::class, 'local');
    }
}
