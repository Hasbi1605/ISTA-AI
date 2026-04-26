<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'filename',
        'original_name',
        'provider_file_id',
        'file_path',
        'mime_type',
        'file_size_bytes',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'file_size_bytes' => 'integer',
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
                    return number_format($bytes / 1048576, 1) . ' MB';
                }

                return number_format(max($bytes / 1024, 0.1), 1) . ' KB';
            }
        );
    }

    protected function extension(): Attribute
    {
        return Attribute::make(
            get: fn() => strtolower((string) pathinfo((string) $this->original_name, PATHINFO_EXTENSION))
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function chunks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(DocumentChunk::class);
    }
}
