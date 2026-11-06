<?php

namespace Xelqun\Tables;

use Illuminate\Support\ServiceProvider;
use Xelqun\Tables\Commands\InstallCommand;
use Xelqun\Tables\Commands\MakeColumnCommand;
use Xelqun\Tables\Commands\MakeTableCommand;

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

            $this->commands([InstallCommand::class, MakeColumnCommand::class, MakeTableCommand::class]);
        }
    }
}
