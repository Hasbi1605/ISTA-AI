<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_prompt_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('feature', 64)->index();
            $table->string('name', 120);
            $table->longText('system_prompt');
            $table->string('status', 32)->default('draft')->index();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('parent_id')->nullable()->constrained('ai_prompt_profiles')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['feature', 'status', 'version'], 'ai_prompt_profiles_feature_status_version_index');
        });

        Schema::create('ai_model_configs', function (Blueprint $table) {
            $table->id();
            $table->string('feature', 64)->index();
            $table->string('name', 120);
            $table->string('provider', 64);
            $table->string('model_name', 191);
            $table->string('fallback_model_name', 191)->nullable();
            $table->decimal('temperature', 4, 2)->nullable();
            $table->unsignedInteger('max_tokens')->nullable();
            $table->unsignedInteger('timeout_seconds')->nullable();
            $table->unsignedTinyInteger('retrieval_top_k')->nullable();
            $table->string('status', 32)->default('draft')->index();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('parent_id')->nullable()->constrained('ai_model_configs')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['feature', 'status', 'version'], 'ai_model_configs_feature_status_version_index');
            $table->index(['provider', 'model_name'], 'ai_model_configs_provider_model_index');
        });

        Schema::create('ai_config_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('auditable_type', 191);
            $table->unsignedBigInteger('auditable_id');
            $table->string('action', 64)->index();
            $table->json('before_snapshot')->nullable();
            $table->json('after_snapshot')->nullable();
            $table->string('reason', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['auditable_type', 'auditable_id'], 'ai_config_audits_auditable_index');
            $table->index('created_at', 'ai_config_audits_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_config_audits');
        Schema::dropIfExists('ai_model_configs');
        Schema::dropIfExists('ai_prompt_profiles');
    }
};
