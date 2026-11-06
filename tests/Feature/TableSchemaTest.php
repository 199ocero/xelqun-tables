<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Xelqun\Tables\Columns\Column;
use Xelqun\Tables\Columns\TextColumn;
use Xelqun\Tables\Exceptions\TableDefinitionException;
use Xelqun\Tables\Schema\SchemaVersion;
use Xelqun\Tables\Table;
use Xelqun\Tables\Tests\Fixtures\Category;
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

    expect(json_encode(Arr::except(ProductsTable::make()->toSchema(), 'token'), JSON_PRETTY_PRINT))->toMatchSnapshot();
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

describe('recordUrl()', function () {
    function linkedRow(string $url): array
    {
        Product::create(['name' => 'Keyboard', 'price' => 1]);

        $table = new class extends ProductsTable
        {
            public static string $url = '';

            public function recordUrl(Model $record): string
            {
                return self::$url;
            }
        };
        $table::$url = $url;

        return $table::make()->toSchema()['rows'][0];
    }

    it('sends where a row goes', function () {
        expect(linkedRow('/products/1')['url'])->toBe('/products/1');
    });

    it('drops an unsafe link', function (string $url) {
        expect(linkedRow($url))->not->toHaveKey('url');
    })->with(['javascript:alert(1)', 'data:text/html,x', '//evil.example']);

    it('adds nothing to a table without one', function () {
        Product::create(['name' => 'Keyboard', 'price' => 1]);

        expect(tableSchema()['rows'][0])->not->toHaveKey('url');
    });
});

it('sends an empty state only when there is one', function () {
    expect(ProductsTable::make()->toSchema())->not->toHaveKey('emptyState')
        ->and(ProductsTable::make()->emptyState('No products', 'Add one.')->toSchema()['emptyState'])
        ->toBe(['heading' => 'No products', 'description' => 'Add one.']);
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

class RelationColumnTable extends Table
{
    public function query(): Builder
    {
        return Product::query();
    }

    public function columns(): array
    {
        return [TextColumn::make('category'), TextColumn::make('list')->state(fn (Product $record) => Category::query()->get())];
    }
}

class DuplicateColumnTable extends RelationColumnTable
{
    public function columns(): array
    {
        return [TextColumn::make('name'), TextColumn::make('name')->label('Again')];
    }
}

class NoPageSizeTable extends RelationColumnTable
{
    public static array $options = [];

    protected function boot(): void
    {
        $this->paginationPageOptions(self::$options);
    }
}

it('sends a related record as its key, never its values', function () {
    $category = Category::create(['name' => 'do not leak']);
    Product::create(['name' => 'Keyboard', 'category_id' => $category->id]);

    $schema = json_decode(json_encode(RelationColumnTable::make()->toSchema()), true);

    expect($schema['rows'][0]['cells']['category']['state'])->toBe($category->id)
        ->and($schema['rows'][0]['cells']['list']['state'])->toBe([$category->id])
        ->and(json_encode($schema))->not->toContain('do not leak');
});

it('refuses two columns with the same name', function () {
    DuplicateColumnTable::make()->getColumns();
})->throws(TableDefinitionException::class);

it('refuses page sizes it can\'t divide by', function (array $options) {
    NoPageSizeTable::$options = $options;

    NoPageSizeTable::make();
})->throws(TableDefinitionException::class)->with([[[]], [[0]], [[10, -1]]]);

it('sends translated text only when it differs from English', function () {
    expect(tableSchema())->not->toHaveKey('messages');

    app('translator')->setLoaded(['xelqun-tables' => ['client' => ['es' => ['No records found.' => 'Sin registros.']]]]);
    app()->setLocale('es');

    expect(tableSchema()['messages'])->toBe(['No records found.' => 'Sin registros.']);
});
