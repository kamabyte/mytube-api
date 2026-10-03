<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал попыток загрузки: по строке на каждый заход воркера за видео.
 *
 * В videos есть только downloaded_at — момент, когда всё уже закончилось.
 * Сколько времени на это ушло и что именно его съело, оттуда не видно, а
 * journald держит логи меньше двух суток. Отсюда отдельная таблица: она
 * переживает ротацию журнала и хранит неудачные попытки тоже.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_download_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();

            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();

            // running — попытка ещё идёт либо воркер умер, не закрыв строку.
            // ok — файл на диске и прошёл проверку.
            // failed — упало; текст в error.
            // unavailable — видео удалили или закрыли на YouTube.
            $table->string('status')->default('running');

            // Две фазы порознь: чистая загрузка и ffmpeg. Разделение не
            // косметическое — на замерах по журналу ffmpeg съедал 92% времени.
            $table->unsignedInteger('download_seconds')->nullable();
            $table->unsignedInteger('transcode_seconds')->nullable();

            $table->unsignedBigInteger('file_size')->nullable();
            $table->text('error')->nullable();

            $table->index(['video_id', 'started_at']);
            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_download_runs');
    }
};
