<?php

use App\Http\Controllers\Web\ChannelController;
use App\Http\Controllers\Web\DownloadController;
use App\Http\Controllers\Web\DownloadRequestController;
use App\Http\Controllers\Web\HomeController;
use App\Http\Controllers\Web\NotificationController;
use App\Http\Controllers\Web\SearchController;
use App\Http\Controllers\Web\StatisticsController;
use App\Http\Controllers\Web\VideoController;
use App\Http\Controllers\Web\VideoFileController;
use Illuminate\Support\Facades\Route;

/*
 * Веб-клиент. JSON API для ТВ-клиентов — под /api (routes/api.php).
 */
Route::get('/', HomeController::class)->name('home');

Route::get('/videos', [VideoController::class, 'index'])->name('videos.index');
Route::get('/videos/lookup', [VideoController::class, 'lookup'])->name('videos.lookup');
// {catalogVideo} — видео из каталога, скачанное или нет (привязка — в AppServiceProvider).
Route::get('/watch/{catalogVideo}', [VideoController::class, 'show'])->name('watch');
// Удалить можно и нескачанное видео каталога — тогда оно просто скрывается навсегда.
Route::delete('/videos/{catalogVideo}', [VideoController::class, 'destroy'])->name('videos.destroy');
Route::delete('/videos/{video}/file', [VideoFileController::class, 'destroy'])->name('videos.file.destroy');
Route::post('/videos/{catalogVideo}/download', [DownloadRequestController::class, 'store'])->name('videos.download.store');
Route::delete('/videos/{catalogVideo}/download', [DownloadRequestController::class, 'destroy'])->name('videos.download.destroy');

Route::get('/channels', [ChannelController::class, 'index'])->name('channels.index');
Route::post('/channels', [ChannelController::class, 'store'])->name('channels.store');
Route::get('/channels/{channel}', [ChannelController::class, 'show'])->name('channels.show');
Route::patch('/channels/{channel}', [ChannelController::class, 'update'])->name('channels.update');
Route::delete('/channels/{channel}', [ChannelController::class, 'destroy'])->name('channels.destroy');

Route::get('/search', SearchController::class)->name('search');

// Панель «Загрузки» в шапке (JSON).
Route::get('/downloads', [DownloadController::class, 'index'])->name('downloads.index');

// Колокольчик в шапке (JSON для выпадающей панели).
Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
Route::post('/notifications/read', [NotificationController::class, 'markAllRead'])->name('notifications.read');
Route::patch('/notifications/{notification}', [NotificationController::class, 'update'])->name('notifications.update');
Route::delete('/notifications', [NotificationController::class, 'destroy'])->name('notifications.destroy');
Route::get('/statistics', StatisticsController::class)->name('statistics');
