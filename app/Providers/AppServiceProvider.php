<?php

namespace App\Providers;

use Google\Client;
use Google\Service\YouTube;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Client::class, function () {
            $client = new Client();
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
        //
    }
}
