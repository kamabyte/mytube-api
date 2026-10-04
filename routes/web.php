<?php

use App\Http\Controllers\Web\ChannelController;
use App\Http\Controllers\Web\DownloadRequestController;
use App\Http\Controllers\Web\HomeController;
use App\Http\Controllers\Web\SearchController;
use App\Http\Controllers\Web\StatisticsController;
use App\Http\Controllers\Web\VideoController;
use App\Models\Video;
use Illuminate\Support\Facades\Route;

/*
 * Веб-клиент. JSON API для ТВ-клиентов — под /api (routes/api.php).
 */
Route::get('/', HomeController::class)->name('home');

// Видео из каталога, скачанное или нет. {video} остаётся «только скачанные»:
// глобальный скоуп модели, на нём же держится JSON API.
Route::bind('catalogVideo', fn (string $value) => Video::catalog()->withDownloadState()->findOrFail($value));

Route::get('/videos', [VideoController::class, 'index'])->name('videos.index');
Route::get('/videos/lookup', [VideoController::class, 'lookup'])->name('videos.lookup');
Route::get('/videos/downloaded', [VideoController::class, 'downloaded'])->name('videos.downloaded');
Route::get('/watch/{catalogVideo}', [VideoController::class, 'show'])->name('watch');
Route::delete('/videos/{video}', [VideoController::class, 'destroy'])->name('videos.destroy');
Route::post('/videos/{catalogVideo}/download', [DownloadRequestController::class, 'store'])->name('videos.download.store');
Route::delete('/videos/{catalogVideo}/download', [DownloadRequestController::class, 'destroy'])->name('videos.download.destroy');

Route::get('/channels', [ChannelController::class, 'index'])->name('channels.index');
Route::post('/channels', [ChannelController::class, 'store'])->name('channels.store');
Route::get('/channels/{channel}', [ChannelController::class, 'show'])->name('channels.show');
Route::patch('/channels/{channel}', [ChannelController::class, 'update'])->name('channels.update');
Route::delete('/channels/{channel}', [ChannelController::class, 'destroy'])->name('channels.destroy');

Route::get('/search', SearchController::class)->name('search');
Route::get('/statistics', StatisticsController::class)->name('statistics');
