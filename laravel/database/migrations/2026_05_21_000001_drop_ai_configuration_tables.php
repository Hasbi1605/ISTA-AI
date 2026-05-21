<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('ai_config_audits');
        Schema::dropIfExists('ai_model_configs');
        Schema::dropIfExists('ai_prompt_profiles');
    }

    public function down(): void
    {
        // AI configuration now lives only in python-ai/config/ai_config.yaml.
    }
};
