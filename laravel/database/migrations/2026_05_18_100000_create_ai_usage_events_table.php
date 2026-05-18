<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('feature', 64);
            $table->string('action', 64);
            $table->string('status', 32);
            $table->string('request_id', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_type', 191)->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('user_id', 'ai_usage_events_user_id_index');
            $table->index('feature', 'ai_usage_events_feature_index');
            $table->index('status', 'ai_usage_events_status_index');
            $table->index('request_id', 'ai_usage_events_request_id_index');
            $table->index('created_at', 'ai_usage_events_created_at_index');
            $table->index(['feature', 'status', 'created_at'], 'ai_usage_events_feature_status_created_index');
            $table->index(['user_id', 'created_at'], 'ai_usage_events_user_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_events');
    }
};
