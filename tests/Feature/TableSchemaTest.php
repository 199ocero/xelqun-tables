<?php

use Symfony\Component\HttpKernel\Exception\HttpException;
use Xelqun\Tables\Columns\Column;
use Xelqun\Tables\Columns\TextColumn;
use Xelqun\Tables\Schema\SchemaVersion;
use Xelqun\Tables\Tests\Fixtures\Product;
use Xelqun\Tables\Tests\Fixtures\ProductsTable;

beforeEach(function () {
    ProductsTable::$allowed = true;
});

afterEach(function () {
    Column::flushConfigurations();
    Column::flushMacros();
});

/**
 * @return array<string, mixed>
 */
function tableSchema(): array
{
    return json_decode(json_encode(ProductsTable::make()->toSchema()), true);
}

it('keeps the shape of the schema', function () {
    Product::create(['name' => 'Keyboard', 'price' => 120]);

    expect(json_encode(ProductsTable::make()->toSchema(), JSON_PRETTY_PRINT))->toMatchSnapshot();
});

it('sends the version the browser checks', function () {
    expect(tableSchema())->toMatchArray(['$xq' => SchemaVersion::CURRENT, 'pkg' => SchemaVersion::PACKAGE, 'id' => 'products-table']);
});

it('shows only the first page, at the first page size', function () {
    collect(range(1, 3))->each(fn (int $number) => Product::create(['name' => "Product {$number}"]));

    $schema = tableSchema();

    expect($schema['rows'])->toHaveCount(2)
        ->and($schema['pagination'])->toMatchArray(['page' => 1, 'perPage' => 2, 'total' => 3, 'lastPage' => 2, 'options' => [2, 5]]);
});

it('never sends a value no visible column declares', function () {
    Product::create(['name' => 'Keyboard', 'secret' => 'do not leak']);

    $schema = tableSchema();

    expect(array_keys($schema['rows'][0]['cells']))->toBe(['name', 'price', 'shouted'])
        ->and(collect($schema['columns'])->pluck('name')->all())->toBe(['name', 'price', 'shouted'])
        ->and(json_encode($schema))->not->toContain('do not leak');
});

it('works out a cell with a closure', function () {
    Product::create(['name' => 'Keyboard']);

    expect(tableSchema()['rows'][0]['cells']['shouted']['state'])->toBe('KEYBOARD');
});

it('sends row keys as strings', function () {
    $product = Product::create(['name' => 'Keyboard']);

    expect(tableSchema()['rows'][0]['key'])->toBe((string) $product->id);
});

it('refuses to render for someone it does not authorize', function () {
    ProductsTable::$allowed = false;
    Product::create(['name' => 'Keyboard']);

    ProductsTable::make()->toSchema();
})->throws(HttpException::class);

it('names a column after its path and lets the label override that', function () {
    expect(TextColumn::make('category.name')->getLabel())->toBe('Category Name')
        ->and(TextColumn::make('price')->label('Cost')->getLabel())->toBe('Cost');
});

it('applies configureUsing before the caller has the last word', function () {
    TextColumn::configureUsing(fn (TextColumn $column) => $column->label('Configured'));

    expect(TextColumn::make('a')->getLabel())->toBe('Configured')
        ->and(TextColumn::make('a')->label('Mine')->getLabel())->toBe('Mine');
});

it('reads visible() as the opposite of hidden()', function () {
    expect(TextColumn::make('a')->visible(false)->isHidden())->toBeTrue()
        ->and(TextColumn::make('a')->visible(fn (): bool => true)->isHidden())->toBeFalse();
});
