<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_account_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64)->index();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->json('before_snapshot')->nullable();
            $table->json('after_snapshot')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('created_at');
            $table->index(['target_user_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_account_audits');
    }
};
