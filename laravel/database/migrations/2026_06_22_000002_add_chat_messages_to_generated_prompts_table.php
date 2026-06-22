<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generated_prompts', function (Blueprint $table) {
            $table->json('chat_messages')->nullable()->after('source_document_ids');
        });
    }

    public function down(): void
    {
        Schema::table('generated_prompts', function (Blueprint $table) {
            $table->dropColumn('chat_messages');
        });
    }
};
