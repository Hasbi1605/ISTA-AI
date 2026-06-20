<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('presentations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            // pending -> processing -> ready / error
            $table->string('status', 40)->default('pending');
            $table->string('visual_template', 60)->nullable();
            // Konfigurasi hybrid (audience, slide_count, header/footer, dll) + prompt tambahan.
            $table->json('configuration')->nullable();
            // Outline hasil/rencana slide (single source untuk renderer).
            $table->json('outline')->nullable();
            // Dokumen sumber milik user (divalidasi owner + ready sebelum dipakai).
            $table->json('source_document_ids')->nullable();
            // Artefak hasil generate (disimpan di private disk).
            $table->string('pptx_path')->nullable();
            $table->string('pdf_path')->nullable();
            // Pesan error user-facing (tanpa stack trace) saat status = error.
            $table->string('error_message', 1000)->nullable();
            $table->timestamp('generated_at')->nullable();
            // Disiapkan untuk fase versioning OnlyOffice Slides (#226). FK menyusul
            // saat tabel presentation_versions dibuat.
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('presentations');
    }
};
