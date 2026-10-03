<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A channel row can also stand for a hand-made playlist: external_id then holds the
     * playlist id instead of the channel id, and the parser walks the whole playlist
     * instead of stopping at the videos it has already seen.
     */
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->boolean('is_playlist')->default(false)->after('uploads_playlist_id');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn('is_playlist');
        });
    }
};
