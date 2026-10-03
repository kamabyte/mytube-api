<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Видео, удалённое вручную (из веб-клиента): файл стёрт, а строка остаётся.
 *
 * Строку удалять нельзя — парсер находит видео по external_id и завёл бы его
 * заново, а воркер скачал бы ещё раз. Поэтому удалённое видео помечается:
 * removed_at — чтобы парсер его больше не трогал, is_unavailable — чтобы
 * воркер не ставил в очередь (он смотрит только на этот флаг).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->timestamp('removed_at')->nullable()->after('is_unavailable');
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn('removed_at');
        });
    }
};
