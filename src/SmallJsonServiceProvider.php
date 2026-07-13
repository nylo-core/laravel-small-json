<?php

declare(strict_types=1);

namespace SmallJson;

use Illuminate\Support\Facades\Response;
use Illuminate\Support\ServiceProvider;
use SmallJson\Http\Middleware\SmallJsonResponses;

class SmallJsonServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/smalljson.php', 'smalljson');

        $this->app->singleton(SmallJsonManager::class);
        $this->app->alias(SmallJsonManager::class, 'smalljson');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/smalljson.php' => config_path('smalljson.php'),
            ], 'smalljson-config');
        }

        Response::macro('smallJson', function (mixed $data = [], int $status = 200, array $headers = [], int $options = 0) {
            return app(SmallJsonManager::class)->response($data, $status, $headers, $options);
        });

        $this->app['router']->aliasMiddleware('smalljson', SmallJsonResponses::class);
    }
}
