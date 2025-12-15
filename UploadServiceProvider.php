<?php

namespace App\Services\UploadService;

use Illuminate\Support\ServiceProvider;

class UploadServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $routesPrefix = config('upload_service.routes_prefix', 'api/files');
        // Load routes
        $this->loadRoutesFrom(__DIR__ . '/routes.php');


        // Load migrations
        $this->loadMigrationsFrom(__DIR__ . '/Database/Migrations');
    }

    public function register(): void
    {
        // Merge the config file
        $this->mergeConfigFrom(
            __DIR__ . '/config/upload-service.php', 'upload_service'
        );

        // Register the service
        $this->app->singleton(FileService::class, function ($app) {
            return new FileService($app['auth']->guard());
        });
    }
}

