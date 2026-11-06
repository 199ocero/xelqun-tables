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

it('creates a table for a model', function (): void {
    $this->artisan('make:xelqun-table', ['name' => 'ProductsTable'])->assertSuccessful();

    $source = File::get("{$this->appPath}/app/Tables/ProductsTable.php");

    expect($source)->toContain('class ProductsTable extends Table')
        ->and($source)->toContain('use App\\Models\\Product;')
        ->and($source)->toContain('return Product::query();');
});

it('uses the model it is given', function (): void {
    $this->artisan('make:xelqun-table', ['name' => 'Orders', '--model' => 'Invoice'])->assertSuccessful();

    expect(File::get("{$this->appPath}/app/Tables/Orders.php"))->toContain('return Invoice::query();');
});

it('refuses a table name it can\'t use, and keeps what is there', function (): void {
    $this->artisan('make:xelqun-table', ['name' => 'not-a-class'])->assertFailed();
    $this->artisan('make:xelqun-table', ['name' => 'Products', '--model' => '../Evil'])->assertFailed();

    $this->artisan('make:xelqun-table', ['name' => 'Products'])->assertSuccessful();
    $this->artisan('make:xelqun-table', ['name' => 'Products'])->assertFailed();
});

it('imports the stylesheet right after tailwindcss', function (): void {
    File::ensureDirectoryExists("{$this->appPath}/resources/css");
    File::put("{$this->appPath}/resources/css/app.css", "@import 'tailwindcss';\n");

    $this->artisan('xelqun-tables:install', ['--without-node' => true])->assertSuccessful();
    $this->artisan('xelqun-tables:install', ['--without-node' => true])->assertSuccessful();

    expect(File::get("{$this->appPath}/resources/css/app.css"))->toBe("@import 'tailwindcss';\n@import '@xelqun/tables/style.css';\n");
});

it('prints the step when it can\'t find the stylesheet', function (): void {
    $this->artisan('xelqun-tables:install', ['--without-node' => true])
        ->expectsOutputToContain('@xelqun/tables/style.css')
        ->assertSuccessful();
});
