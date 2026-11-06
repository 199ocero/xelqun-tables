<?php

use Illuminate\Support\Facades\Route;
use Xelqun\Tables\Http\Controllers\ActionController;
use Xelqun\Tables\Http\Controllers\QueryController;

Route::prefix(config('xelqun-tables.routes.prefix'))
    ->middleware(config('xelqun-tables.routes.middleware'))
    ->name('xelqun.tables.')
    ->group(function (): void {
        Route::post('query', QueryController::class)
            ->middleware('throttle:'.config('xelqun-tables.routes.throttle.query'))
            ->name('query');

        Route::post('action', ActionController::class)
            ->middleware('throttle:'.config('xelqun-tables.routes.throttle.action'))
            ->name('action');
    });
