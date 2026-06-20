<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('presentation_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('presentation_id')->constrained('presentations')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('label')->nullable();
            $table->string('pptx_path')->nullable();
            $table->string('status', 40)->default('generated');
            $table->timestamps();

            $table->unique(['presentation_id', 'version_number']);
            $table->index(['presentation_id', 'created_at']);
        });

        // Backfill versi 1 untuk presentasi yang sudah punya PPTX, lalu set
        // current_version_id agar editor OnlyOffice memakai versi aktif.
        $now = now();

        DB::table('presentations')
            ->whereNotNull('pptx_path')
            ->where('pptx_path', '!=', '')
            ->orderBy('id')
            ->chunkById(100, function ($presentations) use ($now): void {
                foreach ($presentations as $presentation) {
                    $versionId = DB::table('presentation_versions')->insertGetId([
                        'presentation_id' => $presentation->id,
                        'version_number' => 1,
                        'label' => 'Versi 1',
                        'pptx_path' => $presentation->pptx_path,
                        'status' => $presentation->status ?: 'generated',
                        'created_at' => $presentation->created_at ?: $now,
                        'updated_at' => $presentation->updated_at ?: $now,
                    ]);

                    DB::table('presentations')
                        ->where('id', $presentation->id)
                        ->update(['current_version_id' => $versionId]);
                }
            });
    }

    public function down(): void
    {
        DB::table('presentations')->update(['current_version_id' => null]);

        Schema::dropIfExists('presentation_versions');
    }
};
