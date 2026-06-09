<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the Google Drive integration tables. The feature (import/export to
     * Google Drive) has been removed entirely, so these tables are no longer used.
     */
    public function up(): void
    {
        Schema::dropIfExists('cloud_storage_files');
        Schema::dropIfExists('google_drive_oauth_connections');
    }

    /**
     * Recreate the table structures (empty) so the migration is reversible.
     */
    public function down(): void
    {
        Schema::create('cloud_storage_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 40);
            $table->enum('direction', ['import', 'export']);
            $table->morphs('local');
            $table->string('external_id');
            $table->string('name');
            $table->string('mime_type')->nullable();
            $table->string('web_view_link')->nullable();
            $table->string('folder_external_id')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index(['provider', 'external_id'], 'cloud_storage_files_provider_external_id_index');
            $table->unique(['provider', 'direction', 'external_id', 'local_type', 'local_id'], 'cloud_storage_files_unique_record');
        });

        Schema::create('google_drive_oauth_connections', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->unique()->default('google_drive');
            $table->string('account_email')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token');
            $table->string('token_type')->nullable();
            $table->text('scope')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('connected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_refreshed_at')->nullable();
            $table->timestamps();
        });
    }
};
