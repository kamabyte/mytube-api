<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->unique();
            $table->string('uploads_playlist_id')->nullable();
            $table->string('username')->unique()->nullable();
            $table->string('name');
            $table->string('thumbnail')->nullable();
            $table->timestamp('last_synced_at')->nullable()->comment('last time the channel was synced');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('channels');
    }
};
