<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->timestamp('auto_download_from')->nullable();
        });

        Schema::table('videos', function (Blueprint $table): void {
            $table->boolean('download_on_demand')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->dropColumn('download_on_demand');
        });

        Schema::table('channels', function (Blueprint $table): void {
            $table->dropColumn('auto_download_from');
        });
    }
};
