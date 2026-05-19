<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('knowledge_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_source_id')
                ->nullable()
                ->constrained('knowledge_sources')
                ->nullOnDelete();
            $table->foreignId('uploaded_by_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('title');
            $table->string('original_name');
            $table->string('filename');
            $table->string('file_path');
            $table->string('mime_type', 191)->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->string('checksum_sha256', 64)->nullable();
            $table->string('scope', 32)->default('global_internal');
            $table->string('audience', 32)->default('all_users');
            $table->string('status', 32)->default('draft');
            $table->string('vector_namespace', 64)->default('knowledge');
            $table->json('metadata')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('status', 'knowledge_documents_status_index');
            $table->index('scope', 'knowledge_documents_scope_index');
            $table->index('audience', 'knowledge_documents_audience_index');
            $table->index('knowledge_source_id', 'knowledge_documents_source_index');
            $table->index('uploaded_by_id', 'knowledge_documents_uploader_index');
            $table->index('created_at', 'knowledge_documents_created_at_index');
        });

        Schema::create('knowledge_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_document_id')
                ->constrained('knowledge_documents')
                ->cascadeOnDelete();
            $table->unsignedInteger('chunk_count')->default(0);
            $table->unsignedInteger('successful_chunks')->default(0);
            $table->unsignedInteger('failed_chunks')->default(0);
            $table->string('embedding_provider', 191)->nullable();
            $table->json('summary')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique('knowledge_document_id', 'knowledge_chunks_document_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_chunks');
        Schema::dropIfExists('knowledge_documents');
        Schema::dropIfExists('knowledge_sources');
    }
};
