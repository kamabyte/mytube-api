<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Премьеры и трансляции, которые парсер встретил до выхода в эфир. Длительности
 * у них ещё нет, а курсор канала (last_synced_at) уходит дальше — без этой
 * таблицы после эфира видео бы уже не нашлось. Строк в videos для них нет.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upcoming_videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->timestamp('check_after');
            $table->timestamps();
            $table->unique(['channel_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upcoming_videos');
    }
};
