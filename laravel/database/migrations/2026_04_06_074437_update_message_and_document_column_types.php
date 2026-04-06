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
        Schema::table('messages', function (Blueprint $table) {
            $table->longText('content')->change();
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->text('file_path')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->text('content')->change();
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->string('file_path')->change();
        });
    }
};
