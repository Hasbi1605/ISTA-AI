<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->string('processing_claim_token', 64)
                ->nullable()
                ->after('status')
                ->index('knowledge_documents_processing_claim_token_index');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->dropIndex('knowledge_documents_processing_claim_token_index');
            $table->dropColumn('processing_claim_token');
        });
    }
};
