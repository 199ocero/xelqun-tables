<?php

namespace Xelqun\Tables\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Xelqun\Forms\FormsServiceProvider;
use Xelqun\Tables\TablesServiceProvider;
use Xelqun\Tables\Testing\InteractsWithTables;

abstract class TestCase extends Orchestra
{
    use InteractsWithTables;

    protected function getPackageProviders($app): array
    {
        return [FormsServiceProvider::class, TablesServiceProvider::class];
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable();
            $table->string('name');
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->nullable();
            $table->string('name');
            $table->string('secret')->nullable();
            $table->string('status')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->decimal('price', 12, 2)->default(0);
            $table->softDeletes();
            $table->timestamps();
        });
    }
}
