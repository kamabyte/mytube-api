<?php

namespace App\Providers;

use App\Models\Video;
use Google\Client;
use Google\Service\YouTube;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Client::class, function () {
            $client = new Client;
            $client->setDeveloperKey(config('services.youtube.key'));

            return $client;
        });

        $this->app->singleton(YouTube::class, function ($app) {
            return new YouTube($app->make(Client::class));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Видео из каталога, скачанное или нет. {video} остаётся «только скачанные»:
        // глобальный скоуп модели, на нём же держится JSON API.
        // Здесь, а не в routes/web.php: в проде маршруты закешированы (route:cache),
        // файлы маршрутов не выполняются — и привязка оттуда просто не регистрировалась.
        Route::bind('catalogVideo', fn (string $value) => Video::catalog()->withDownloadState()->findOrFail($value));
    }
}
