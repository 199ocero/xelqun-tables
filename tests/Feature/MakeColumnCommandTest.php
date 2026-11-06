<?php

use App\Tables\Columns\StockMeter;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->appPath = sys_get_temp_dir().'/xelqun-make-column-'.uniqid();

    File::ensureDirectoryExists("{$this->appPath}/resources/js");
    File::put("{$this->appPath}/composer.json", json_encode([
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
    ]));

    $this->app->setBasePath($this->appPath);
});

afterEach(function (): void {
    File::deleteDirectory($this->appPath);
});

it('creates a column and its cell', function (): void {
    $this->artisan('make:xelqun-column', ['name' => 'StockMeter'])
        ->expectsOutputToContain("'app/stock-meter': () => import('./tables/StockMeterCell.vue')")
        ->assertSuccessful();

    require "{$this->appPath}/app/Tables/Columns/StockMeter.php";

    expect(StockMeter::make('stock')->getType())->toBe('app/stock-meter')
        ->and(File::get("{$this->appPath}/resources/js/tables/StockMeterCell.vue"))->toBe(File::get(__DIR__.'/../../stubs/column.vue'));
});

it('uses the type it is given', function (): void {
    $this->artisan('make:xelqun-column', ['name' => 'Spark', '--type' => 'acme/spark'])->assertSuccessful();

    expect(File::get("{$this->appPath}/app/Tables/Columns/Spark.php"))->toContain("'acme/spark'");
});

it('refuses a name or type it can\'t use', function (array $arguments): void {
    $this->artisan('make:xelqun-column', $arguments)->assertFailed();
})->with([
    'not a class name' => [['name' => 'not-a-class']],
    'no vendor prefix' => [['name' => 'Spark', '--type' => 'spark']],
]);

it('keeps what is already there unless told otherwise', function (): void {
    $this->artisan('make:xelqun-column', ['name' => 'Spark'])->assertSuccessful();
    $this->artisan('make:xelqun-column', ['name' => 'Spark'])->assertFailed();
    $this->artisan('make:xelqun-column', ['name' => 'Spark', '--force' => true])->assertSuccessful();
});
