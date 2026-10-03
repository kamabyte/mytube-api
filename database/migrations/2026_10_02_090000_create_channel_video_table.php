<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * В каких каналах и плейлистах лежит видео. Одно видео может быть и в канале
 * автора, и в любом числе плейлистов — показывается во всех.
 *
 * videos.channel_id остаётся: это «основной» источник видео (канал автора,
 * если он отслеживается, иначе первый плейлист). Он подписывает видео
 * в списках и задаёт каталог, куда воркер кладёт файл.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_video', function (Blueprint $table) {
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            $table->primary(['channel_id', 'video_id']);
            $table->index('video_id');
        });

        DB::statement('INSERT INTO channel_video (channel_id, video_id) SELECT channel_id, id FROM videos');
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_video');
    }
};
