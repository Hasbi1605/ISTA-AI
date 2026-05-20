<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedInteger('indexed_chunk_count')->nullable()->after('preview_status');
            $table->string('embedding_provider')->nullable()->after('indexed_chunk_count');
            $table->timestamp('indexed_at')->nullable()->after('embedding_provider');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn([
                'indexed_chunk_count',
                'embedding_provider',
                'indexed_at',
            ]);
        });
    }
};
