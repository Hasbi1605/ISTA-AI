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
            $table->string('parent_id')->nullable()->after('document_id');
            $table->enum('chunk_type', ['child', 'parent'])->default('child')->after('parent_id');
            $table->integer('parent_index')->nullable()->after('chunk_type');
            $table->integer('child_index')->nullable()->after('parent_index');
            
            $table->index(['document_id', 'parent_id']);
            $table->index(['document_id', 'chunk_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_chunks', function (Blueprint $table) {
            $table->dropIndex(['document_id', 'parent_id']);
            $table->dropIndex(['document_id', 'chunk_type']);
            $table->dropColumn(['parent_id', 'chunk_type', 'parent_index', 'child_index']);
        });
    }
};