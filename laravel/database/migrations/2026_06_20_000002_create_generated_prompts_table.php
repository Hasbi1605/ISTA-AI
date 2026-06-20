<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generated_prompts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Platform AI eksternal target + jenis keluaran (profil di ai_config.yaml).
            $table->string('platform', 60);
            $table->string('platform_label', 120)->nullable();
            $table->string('prompt_type', 60);
            $table->string('prompt_type_label', 120)->nullable();
            // Judul ringkas (diturunkan dari ide) untuk daftar riwayat.
            $table->string('title')->nullable();
            // Ide asli (Bahasa Indonesia) dari user.
            $table->text('idea')->nullable();
            // Paket prompt hasil generate: main_prompt, variants, negative_prompt,
            // recommended_settings, notes_id.
            $table->json('package')->nullable();
            // Dokumen sumber milik user (divalidasi owner + ready sebelum dipakai).
            $table->json('source_document_ids')->nullable();
            // Penanda konteks internal dipakai (dokumen / reference image / catatan).
            $table->boolean('contains_internal_context')->default(false);
            // Reference image privat (MVP): path private disk + metadata validasi.
            $table->string('reference_image_path')->nullable();
            $table->string('reference_image_mime', 100)->nullable();
            $table->unsignedInteger('reference_image_size_bytes')->nullable();
            $table->string('model_label', 191)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_prompts');
    }
};
