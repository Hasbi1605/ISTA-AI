<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('role');
                $table->index('is_active');
            }

            if (! Schema::hasColumn('users', 'disabled_at')) {
                $table->timestamp('disabled_at')->nullable()->after('is_active');
            }

            if (! Schema::hasColumn('users', 'disabled_by')) {
                $table->unsignedBigInteger('disabled_by')->nullable()->after('disabled_at');
                $table->foreign('disabled_by')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('users', 'disabled_reason')) {
                $table->string('disabled_reason')->nullable()->after('disabled_by');
            }

            if (! Schema::hasColumn('users', 'force_password_change')) {
                $table->boolean('force_password_change')->default(false)->after('disabled_reason');
            }

            if (! Schema::hasColumn('users', 'last_admin_login_at')) {
                $table->timestamp('last_admin_login_at')->nullable()->after('force_password_change');
            }

            if (! Schema::hasColumn('users', 'last_admin_login_ip')) {
                $table->string('last_admin_login_ip', 45)->nullable()->after('last_admin_login_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'last_admin_login_ip')) {
                $table->dropColumn('last_admin_login_ip');
            }

            if (Schema::hasColumn('users', 'last_admin_login_at')) {
                $table->dropColumn('last_admin_login_at');
            }

            if (Schema::hasColumn('users', 'force_password_change')) {
                $table->dropColumn('force_password_change');
            }

            if (Schema::hasColumn('users', 'disabled_reason')) {
                $table->dropColumn('disabled_reason');
            }

            if (Schema::hasColumn('users', 'disabled_by')) {
                try {
                    $table->dropForeign(['disabled_by']);
                } catch (\Throwable $e) {
                    // ignore if foreign key does not exist
                }
                $table->dropColumn('disabled_by');
            }

            if (Schema::hasColumn('users', 'disabled_at')) {
                $table->dropColumn('disabled_at');
            }

            if (Schema::hasColumn('users', 'is_active')) {
                $table->dropIndex(['is_active']);
                $table->dropColumn('is_active');
            }
        });
    }
};
