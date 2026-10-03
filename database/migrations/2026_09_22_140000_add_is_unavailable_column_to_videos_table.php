<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Признак «YouTube больше не отдаёт это видео».
 *
 * Без него снятый с публикации ролик попадал в очередь заново каждый цикл:
 * видео 2771 набрало 32 неудачные попытки за три часа и продолжало бы вечно.
 *
 * Флаг ортогонален is_downloaded и намеренно не влияет на выдачу: если файл
 * успели скачать до удаления с YouTube, он остаётся в каталоге и играется —
 * в том и смысл архива. Флаг нужен только воркеру, чтобы перестать ходить
 * за тем, чего больше нет.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->boolean('is_unavailable')->default(false)->after('is_downloaded');
            $table->index(['is_downloaded', 'is_unavailable']);
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropIndex(['is_downloaded', 'is_unavailable']);
            $table->dropColumn('is_unavailable');
        });
    }
};
