<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('document_chunks', function (Blueprint $table) {
            $table->longText('embedding')->nullable()->after('text_content');
            $table->string('embedding_model')->nullable()->after('embedding');
            $table->integer('embedding_dimensions')->nullable()->after('embedding_model');
            
            // Add index for faster lookup per document
            $table->index(['document_id', 'embedding_model']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_chunks', function (Blueprint $table) {
            $table->dropIndex(['document_id', 'embedding_model']);
            $table->dropColumn(['embedding', 'embedding_model', 'embedding_dimensions']);
        });
    }
};
