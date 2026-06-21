<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('presentation_versions');
        Schema::dropIfExists('presentations');
    }

    public function down(): void
    {
        Schema::create('presentations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('status', 40)->default('pending');
            $table->string('visual_template', 60)->nullable();
            $table->json('configuration')->nullable();
            $table->json('outline')->nullable();
            $table->json('source_document_ids')->nullable();
            $table->string('pptx_path')->nullable();
            $table->string('pdf_path')->nullable();
            $table->string('error_message', 1000)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('presentation_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('presentation_id')->constrained('presentations')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('label')->nullable();
            $table->string('pptx_path')->nullable();
            $table->string('status', 40)->default('generated');
            $table->timestamps();

            $table->unique(['presentation_id', 'version_number']);
            $table->index(['presentation_id', 'created_at']);
        });
    }
};
