<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Xelqun\Tables\Columns\Column;
use Xelqun\Tables\Columns\CustomColumn;
use Xelqun\Tables\Columns\IconColumn;
use Xelqun\Tables\Columns\ImageColumn;
use Xelqun\Tables\Columns\TextColumn;
use Xelqun\Tables\Exceptions\TableDefinitionException;
use Xelqun\Tables\Table;
use Xelqun\Tables\Tests\Fixtures\Category;
use Xelqun\Tables\Tests\Fixtures\Product;

class OneColumnTable extends Table
{
    public static Column $column;

    public function query(): Builder
    {
        return Product::query();
    }

    public function columns(): array
    {
        return [self::$column];
    }
}

/**
 * @return array<string, mixed>
 */
function cellOf(Column $column, ?Product $record = null): array
{
    OneColumnTable::$column = $column;
    $record ??= Product::create(['name' => 'Keyboard', 'price' => 1234.5]);

    return $column->mount(OneColumnTable::make())->toCell($record);
}

it('keeps the shape of every column', function (Column $column) {
    OneColumnTable::$column = $column;
    Product::create(['name' => 'Keyboard', 'price' => 1234.5, 'secret' => 'a.png', 'created_at' => '2026-01-15 10:00:00']);

    $schema = OneColumnTable::make()->toSchema();

    expect(json_encode(['column' => $schema['columns'], 'cell' => $schema['rows'][0]['cells']], JSON_PRETTY_PRINT))->toMatchSnapshot();
})->with([
    'text' => fn () => TextColumn::make('name'),
    'text in full' => fn () => TextColumn::make('name')->badge()->wrap()->color('success')->icon('star')->url('/products', openInNewTab: true),
    'money' => fn () => TextColumn::make('price')->money('USD', 'en'),
    'date' => fn () => TextColumn::make('created_at')->date(),
    'icon' => fn () => IconColumn::make('name')->icon('star')->color('primary'),
    'boolean' => fn () => IconColumn::make('name')->boolean(),
    'image' => fn () => ImageColumn::make('secret')->size(32)->circular()->alt('Photo'),
    'custom' => fn () => CustomColumn::make('name')->component('acme/thing')->props(['a' => 1]),
    'text with every option' => fn () => TextColumn::make('name')->description('About', 'above')->placeholder('None')->lineClamp(2)
        ->copyable()->copyMessage('Done')->copyMessageDuration(500)->bulleted()->listWithLineBreaks()->limitList(3)->expandableLimitedList()->tooltip('Hover'),
]);

it('fills an empty cell with its default, and shows a placeholder for one with none', function () {
    $product = Product::create(['name' => 'Keyboard', 'price' => 1]);

    expect(cellOf(TextColumn::make('secret')->default(fn ($record) => 'no '.$record->name), $product)['state'])->toBe('no Keyboard');
});

it('cuts long text and shows the whole of it on hover', function () {
    $product = Product::create(['name' => 'A long keyboard name', 'price' => 1]);

    expect(cellOf(TextColumn::make('name')->limit(6), $product))->toMatchArray(['state' => 'A long...', 'tooltip' => 'A long keyboard name'])
        ->and(cellOf(TextColumn::make('name')->words(2, '…'), $product))->toMatchArray(['state' => 'A long…', 'tooltip' => 'A long keyboard name'])
        ->and(cellOf(TextColumn::make('name')->limit(6)->tooltip('Mine'), $product)['tooltip'])->toBe('Mine')
        ->and(cellOf(TextColumn::make('name')->limit(60), $product))->not->toHaveKey('tooltip');
});

it('gives each cell its description', function () {
    expect(cellOf(TextColumn::make('name')->description(fn (Product $record, string $state): string => "{$state} #{$record->price}"))['description'])->toBe('Keyboard #1234.5');
});

it('formats numbers, times and how long ago, in a time zone', function () {
    $product = Product::create(['name' => 'Keyboard', 'price' => 1234.5, 'created_at' => now()->subDays(3)]);
    $product->created_at = '2026-01-15 23:30:00';

    expect(cellOf(TextColumn::make('price')->numeric(2, 'en'), $product)['state'])->toBe('1,234.50')
        ->and(cellOf(TextColumn::make('created_at')->time('H:i')->timezone('Asia/Manila'), $product)['state'])->toBe('07:30')
        ->and(cellOf(TextColumn::make('created_at')->dateTime('M j H:i', 'Asia/Manila'), $product)['state'])->toBe('Jan 16 07:30');

    $product->created_at = now()->subDays(3);

    expect(cellOf(TextColumn::make('created_at')->since(), $product)['state'])->toBe('3 days ago');
});

it('formats money in the given currency', function () {
    expect(cellOf(TextColumn::make('price')->money('EUR', 'de'))['state'])->toBe("1.234,50\u{a0}€");
});

it('uses the app locale for money by default', function () {
    app()->setLocale('en');

    expect(cellOf(TextColumn::make('price')->money('USD'))['state'])->toBe('$1,234.50');
});

it('leaves a value alone when it is not a number or a date', function () {
    expect(cellOf(TextColumn::make('name')->money('USD'))['state'])->toBe('Keyboard')
        ->and(cellOf(TextColumn::make('name')->date())['state'])->toBe('Keyboard');
});

it('shows nothing for an empty value, without running the formatter', function () {
    $calls = 0;

    expect(cellOf(TextColumn::make('secret')->formatStateUsing(function () use (&$calls) {
        $calls++;
    }))['state'])->toBeNull()
        ->and($calls)->toBe(0);
});

it('formats a date', function () {
    $product = Product::create(['name' => 'A', 'created_at' => '2026-01-15 10:30:00']);

    expect(cellOf(TextColumn::make('created_at')->date(), $product)['state'])->toBe('Jan 15, 2026')
        ->and(cellOf(TextColumn::make('created_at')->dateTime(), $product)['state'])->toBe('Jan 15, 2026 10:30')
        ->and(cellOf(TextColumn::make('created_at')->date('Y'), $product)['state'])->toBe('2026');
});

it('formats each value of a list', function () {
    $category = Category::create(['name' => 'Input']);

    Product::create(['name' => 'a', 'category_id' => $category->id]);
    Product::create(['name' => 'b', 'category_id' => $category->id]);

    $column = TextColumn::make('products.name')->formatStateUsing(fn (string $state): string => strtoupper($state));
    OneColumnTable::$column = $column;

    $cell = $column->mount(OneColumnTable::make())->toCell($category);

    expect($cell['state'])->toBe(['A', 'B']);
});

it('runs no color or icon closure for a cell with no value', function () {
    $product = Product::create(['name' => 'A']);

    $cell = cellOf(TextColumn::make('secret')
        ->color(fn (string $state): string => 'success')
        ->icon(fn (string $state): string => 'star')
        ->url(fn (Product $record): string => "/products/{$record->id}"), $product);

    expect($cell)->toBe(['state' => null, 'url' => "/products/{$product->id}"]);
});

it('keeps an empty date empty', function () {
    expect(cellOf(TextColumn::make('secret')->date(), Product::create(['name' => 'A', 'secret' => '']))['state'])->toBe('');
});

it('gives closures the state and the record', function () {
    $cell = cellOf(TextColumn::make('name')
        ->color(fn (string $state): string => $state === 'Keyboard' ? 'success' : 'gray')
        ->icon(fn (Product $record): string => $record->name === 'Keyboard' ? 'star' : 'x')
        ->url(fn (Product $record): string => "/products/{$record->id}"));

    expect($cell)->toMatchArray(['color' => 'success', 'icon' => 'star', 'url' => '/products/1']);
});

it('leaves out what a cell does not use', function () {
    expect(array_keys(cellOf(TextColumn::make('name'))))->toBe(['state']);
});

it('drops an unsafe link', function (string $url) {
    expect(cellOf(TextColumn::make('name')->url(fn (): string => $url)))->not->toHaveKey('url');
})->with(['javascript:alert(1)', 'data:text/html,x', '//evil.example']);

it('shows a check or a cross with a spoken label', function () {
    $product = Product::create(['name' => 'A', 'price' => 0]);

    expect(cellOf(IconColumn::make('name')->boolean(), $product))->toMatchArray(['icon' => 'check', 'color' => 'success', 'label' => 'Yes'])
        ->and(cellOf(IconColumn::make('price')->boolean()->state(fn (): bool => false), $product))->toMatchArray(['icon' => 'x', 'color' => 'gray', 'label' => 'No']);
});

it('builds an image url from a disk', function () {
    Storage::fake('public');

    $product = Product::create(['name' => 'A', 'secret' => 'photos/a.png']);

    expect(cellOf(ImageColumn::make('secret')->disk('public'), $product)['state'])->toContain('photos/a.png');
});

it('uses a stored url as it is, and drops one it can\'t trust', function () {
    expect(cellOf(ImageColumn::make('secret'), Product::create(['name' => 'A', 'secret' => 'https://cdn.example/a.png']))['state'])->toBe('https://cdn.example/a.png')
        ->and(cellOf(ImageColumn::make('secret'), Product::create(['name' => 'B', 'secret' => 'javascript:alert(1)']))['state'])->toBeNull()
        ->and(cellOf(ImageColumn::make('secret'), Product::create(['name' => 'C']))['state'])->toBeNull();
});

it('falls back to the label for image alt text', function () {
    expect(cellOf(ImageColumn::make('secret')->label('Photo'))['alt'])->toBe('Photo');
});

it('gives a custom column its props', function () {
    $column = CustomColumn::make('name')->component('acme/thing')->props(fn (Product $record): array => ['id' => $record->id]);

    expect(cellOf($column)['props'])->toBe(['id' => 1])
        ->and($column->getType())->toBe('acme/thing');
});

it('asks for a component on a custom column', function () {
    CustomColumn::make('name')->getType();
})->throws(TableDefinitionException::class);
