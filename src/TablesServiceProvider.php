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
        $this->loadRoutesFrom(__DIR__.'/../routes/xelqun-tables.php');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'xelqun-tables');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/xelqun-tables.php' => config_path('xelqun-tables.php'),
            ], 'xelqun-tables-config');

            $this->publishes([
                __DIR__.'/../resources/lang' => lang_path('vendor/xelqun-tables'),
            ], 'xelqun-tables-lang');
        }
    }
}
