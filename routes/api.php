<?php

use App\Http\Controllers\ChannelController;
use App\Http\Controllers\DownloadRequestController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Internal\VideoDownloadController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\StatisticsController;
use App\Http\Controllers\VideoController;
use Illuminate\Support\Facades\Route;

/*
 * JSON API для ТВ-клиентов (tvOS, Android). Префикс /api задан в
 * bootstrap/app.php; имена маршрутов — с «api.», чтобы не путать их
 * с одноимёнными страницами веб-клиента (routes/web.php).
 */
Route::name('api.')->group(function (): void {
    Route::get('/home', HomeController::class)->name('home');
    Route::get('/search', SearchController::class)->name('search');
    Route::get('/statistics', [StatisticsController::class, 'show'])->name('statistics');
    Route::get('/statistics/channels', [StatisticsController::class, 'channels'])->name('statistics.channels');
    Route::get('/statistics/daily', [StatisticsController::class, 'daily'])->name('statistics.daily');
    Route::get('/channels', [ChannelController::class, 'index'])->name('channels.index');
    Route::get('/channels/{channel}', [ChannelController::class, 'show'])->name('channels.show');
    Route::get('/videos', [VideoController::class, 'index'])->name('videos.index');
    // {catalogVideo} — и нескачанные видео каталога (их статус клиент переспрашивает тут).
    Route::get('/videos/{catalogVideo}', [VideoController::class, 'show'])->name('videos.show');
    Route::post('/videos/{catalogVideo}/download', [DownloadRequestController::class, 'store'])->name('videos.download.store');
    Route::delete('/videos/{catalogVideo}/download', [DownloadRequestController::class, 'destroy'])->name('videos.download.destroy');
    Route::get('/videos/{video}/up-next', [VideoController::class, 'upNext'])->name('videos.up-next');
    Route::get('/videos/{video}/stream', [VideoController::class, 'stream'])->name('videos.stream');
    Route::get('/videos/{video}/subtitles/{track}', [VideoController::class, 'subtitles'])
        ->whereNumber('track')
        ->name('videos.subtitles');
});

// Для воркера внутри стека, не для клиентов: закрыто токеном (services.worker.hook_token).
Route::post('/internal/videos/{video}/downloaded', [VideoDownloadController::class, 'store'])
    ->name('internal.videos.downloaded');
