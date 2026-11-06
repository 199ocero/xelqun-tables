<?php

namespace Xelqun\Tables;

use Illuminate\Support\ServiceProvider;

class TablesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/xelqun-tables.php', 'xelqun-tables');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/xelqun-tables.php' => config_path('xelqun-tables.php'),
            ], 'xelqun-tables-config');
        }
    }
}
