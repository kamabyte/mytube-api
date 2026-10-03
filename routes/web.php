<?php

use App\Http\Controllers\Web\ChannelController;
use App\Http\Controllers\Web\HomeController;
use App\Http\Controllers\Web\SearchController;
use App\Http\Controllers\Web\StatisticsController;
use App\Http\Controllers\Web\VideoController;
use Illuminate\Support\Facades\Route;

/*
 * Веб-клиент. JSON API для ТВ-клиентов — под /api (routes/api.php).
 */
Route::get('/', HomeController::class)->name('home');

Route::get('/videos', [VideoController::class, 'index'])->name('videos.index');
Route::get('/videos/lookup', [VideoController::class, 'lookup'])->name('videos.lookup');
Route::get('/watch/{video}', [VideoController::class, 'show'])->name('watch');
Route::delete('/videos/{video}', [VideoController::class, 'destroy'])->name('videos.destroy');

Route::get('/channels', [ChannelController::class, 'index'])->name('channels.index');
Route::post('/channels', [ChannelController::class, 'store'])->name('channels.store');
Route::get('/channels/{channel}', [ChannelController::class, 'show'])->name('channels.show');
Route::delete('/channels/{channel}', [ChannelController::class, 'destroy'])->name('channels.destroy');

Route::get('/search', SearchController::class)->name('search');
Route::get('/statistics', StatisticsController::class)->name('statistics');
