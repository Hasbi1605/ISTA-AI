<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->index(['user_id', 'updated_at', 'id'], 'conversations_user_updated_id_index');
        });

        Schema::table('memos', function (Blueprint $table) {
            $table->index(['user_id', 'updated_at', 'id'], 'memos_user_updated_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('memos', function (Blueprint $table) {
            $table->dropIndex('memos_user_updated_id_index');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('conversations_user_updated_id_index');
        });
    }
};
