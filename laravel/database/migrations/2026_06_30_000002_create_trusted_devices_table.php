<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trusted_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('device_hash', 64)->index();
            $table->string('device_name')->nullable();
            $table->timestamp('trusted_until');
            $table->timestamps();

            $table->unique(['user_id', 'device_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trusted_devices');
    }
};
