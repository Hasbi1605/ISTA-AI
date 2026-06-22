<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generated_prompt_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('generated_prompt_id')->constrained('generated_prompts')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->json('package')->nullable();
            $table->text('revision_instruction')->nullable();
            $table->json('reference_images')->nullable();
            $table->string('model_label', 191)->nullable();
            $table->timestamps();

            $table->unique(['generated_prompt_id', 'version_number'], 'gen_prompt_ver_unique');
            $table->index(['generated_prompt_id', 'created_at'], 'gen_prompt_ver_created_idx');
        });

        Schema::table('generated_prompts', function (Blueprint $table) {
            $table->unsignedBigInteger('current_version_id')->nullable()->after('package')->index();
        });

        $now = now();

        DB::table('generated_prompts')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunkById(100, function ($prompts) use ($now): void {
                foreach ($prompts as $prompt) {
                    $referenceImages = null;
                    if (! empty($prompt->reference_image_path)) {
                        $referenceImages = json_encode([[
                            'label' => 'Gambar 1',
                            'path' => $prompt->reference_image_path,
                            'mime' => $prompt->reference_image_mime,
                            'size' => $prompt->reference_image_size_bytes,
                        ]]);
                    }

                    $versionId = DB::table('generated_prompt_versions')->insertGetId([
                        'generated_prompt_id' => $prompt->id,
                        'version_number' => 1,
                        'package' => $prompt->package,
                        'revision_instruction' => null,
                        'reference_images' => $referenceImages,
                        'model_label' => $prompt->model_label,
                        'created_at' => $prompt->created_at ?: $now,
                        'updated_at' => $prompt->updated_at ?: $now,
                    ]);

                    DB::table('generated_prompts')
                        ->where('id', $prompt->id)
                        ->update(['current_version_id' => $versionId]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('generated_prompts', function (Blueprint $table) {
            $table->dropIndex('generated_prompts_current_version_id_index');
            $table->dropColumn('current_version_id');
        });

        Schema::dropIfExists('generated_prompt_versions');
    }
};
