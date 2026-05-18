<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'role')) {
                $table->string('role', 32)->default('user')->after('password');
                $table->index('role');
            }

            if (! Schema::hasColumn('users', 'last_seen_at')) {
                $table->timestamp('last_seen_at')->nullable()->after('role');
                $table->index('last_seen_at');
            }

            if (! Schema::hasColumn('users', 'last_active_feature')) {
                $table->string('last_active_feature', 64)->nullable()->after('last_seen_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'last_active_feature')) {
                $table->dropColumn('last_active_feature');
            }

            if (Schema::hasColumn('users', 'last_seen_at')) {
                $table->dropIndex(['last_seen_at']);
                $table->dropColumn('last_seen_at');
            }

            if (Schema::hasColumn('users', 'role')) {
                $table->dropIndex(['role']);
                $table->dropColumn('role');
            }
        });
    }
};
