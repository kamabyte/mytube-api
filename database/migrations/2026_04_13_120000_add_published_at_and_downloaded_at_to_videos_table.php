<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->timestamp('published_at')->nullable()->after('thumbnail');
            $table->timestamp('downloaded_at')->nullable()->after('published_at');
        });

        DB::table('videos')
            ->whereNull('published_at')
            ->update(['published_at' => DB::raw('created_at')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn(['published_at', 'downloaded_at']);
        });
    }
};
