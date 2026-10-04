<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Скачивание по запросу.
 *
 * download_on_demand — канал только ведёт каталог: видео заводятся (с обложками),
 * но воркер их не трогает, пока их не попросят. По умолчанию выключено —
 * уже добавленные каналы качаются как раньше.
 *
 * download_requested_at — видео попросили скачать. Воркер берёт такие первыми,
 * в порядке запросов, и только потом — очередь каналов с автоскачиванием.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->boolean('download_on_demand')->default(false)->after('is_playlist');
        });

        Schema::table('videos', function (Blueprint $table) {
            $table->timestamp('download_requested_at')->nullable()->after('downloaded_at');
            $table->index(['is_downloaded', 'download_requested_at']);
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropIndex(['is_downloaded', 'download_requested_at']);
            $table->dropColumn('download_requested_at');
        });

        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn('download_on_demand');
        });
    }
};
