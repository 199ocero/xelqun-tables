<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Xelqun\Tables\Columns\TextColumn;
use Xelqun\Tables\Exceptions\TableDefinitionException;
use Xelqun\Tables\Table;
use Xelqun\Tables\Tests\Fixtures\Category;
use Xelqun\Tables\Tests\Fixtures\Product;

class CatalogTable extends Table
{
    protected function boot(): void
    {
        $this->paginationPageOptions([2, 50]);
    }

    public function query(): Builder
    {
        return Product::query();
    }

    public function columns(): array
    {
        return [
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('category.name')->searchable()->sortable(),
            TextColumn::make('price')->sortable(),
            TextColumn::make('secret')->searchable()->sortable()->hidden(),
        ];
    }
}

class EitherNameTable extends CatalogTable
{
    public function query(): Builder
    {
        return Product::query()->where('name', 'Alpha')->orWhere('name', 'Beta');
    }
}

class NewestFirstTable extends CatalogTable
{
    public function query(): Builder
    {
        return Product::query()->latest('id');
    }
}

class DefaultSortTable extends CatalogTable
{
    protected function boot(): void
    {
        $this->defaultSort('price', 'desc')->paginationPageOptions([50]);
    }
}

class ChildCategoriesTable extends Table
{
    public function query(): Builder
    {
        return Category::query();
    }

    public function columns(): array
    {
        return [
            TextColumn::make('name'),
            TextColumn::make('parent.name')->sortable(),
            TextColumn::make('products.name'),
        ];
    }
}

class CustomQueriesTable extends CatalogTable
{
    public function columns(): array
    {
        return [
            TextColumn::make('name')
                ->searchable(query: fn (Builder $query, string $search) => $query->orWhere('name', strtoupper($search)))
                ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('price', $direction)),
        ];
    }
}

class MethodNameTable extends CatalogTable
{
    public function columns(): array
    {
        return [TextColumn::make('save'), TextColumn::make('delete')];
    }
}

abstract class BrokenTable extends CatalogTable
{
    public function query(): Builder
    {
        return Category::query();
    }
}

class TwoHopSortTable extends BrokenTable
{
    public function columns(): array
    {
        return [TextColumn::make('parent.parent.name')->sortable()];
    }
}

class ManySortTable extends BrokenTable
{
    public function columns(): array
    {
        return [TextColumn::make('products.name')->sortable()];
    }
}

class JsonSearchTable extends BrokenTable
{
    public function columns(): array
    {
        return [TextColumn::make('meta.color')->searchable()];
    }
}

function product(string $name, array $attributes = []): Product
{
    return Product::create(['name' => $name, ...$attributes]);
}

/**
 * @return array<int, mixed>
 */
function namesFrom(Table $table, array $query = [], string $column = 'name'): array
{
    return collect(test()->queryTable($table, $query)->assertOk()->json('rows'))
        ->pluck("cells.{$column}.state")
        ->all();
}

it('finds rows by a searchable column', function () {
    product('Keyboard');
    product('Mouse');

    expect(namesFrom(CatalogTable::make(), ['search' => 'KEY']))->toBe(['Keyboard']);
});

it('finds rows through a relation', function () {
    product('Keyboard', ['category_id' => Category::create(['name' => 'Input'])->id]);
    product('Monitor', ['category_id' => Category::create(['name' => 'Display'])->id]);

    expect(namesFrom(CatalogTable::make(), ['search' => 'displ']))->toBe(['Monitor']);
});

it('keeps a search inside a query that uses orWhere', function () {
    product('Alpha one');
    product('Alpha');
    product('Beta');
    product('Betamax outside');

    expect(namesFrom(EitherNameTable::make(), ['search' => 'beta']))->toBe(['Beta'])
        ->and(namesFrom(EitherNameTable::make(), ['search' => 'outside']))->toBe([]);
});

it('counts only what the search found', function () {
    product('Keyboard');
    product('Mouse');

    $this->queryTable(CatalogTable::make(), ['search' => 'key'])->assertJsonPath('pagination.total', 1);
});

it('never searches a hidden column', function () {
    product('Keyboard', ['secret' => 'needle']);

    expect(namesFrom(CatalogTable::make(), ['search' => 'needle']))->toBe([]);
});

it('never sorts by a hidden column', function () {
    product('A', ['secret' => 'z']);
    product('B', ['secret' => 'a']);

    expect(namesFrom(CatalogTable::make(), ['sort' => 'secret']))->toBe(['A', 'B'])
        ->and($this->queryTable(CatalogTable::make(), ['sort' => 'secret'])->json('sort'))->toBeNull();
});

it('ignores a sort it did not declare', function (mixed $sort) {
    product('A');

    $this->queryTable(CatalogTable::make(), ['sort' => $sort])->assertOk()->assertJsonPath('sort', null);
})->with(['id', 'password', 'name; drop table products', '-', '--name', [['name']], 7]);

it('treats a search as plain text, never as SQL', function () {
    product('Keyboard');

    expect(namesFrom(CatalogTable::make(), ['search' => "' OR 1=1 --"]))->toBe([]);
});

it('trims a search and caps its length', function () {
    product('Keyboard');

    expect($this->queryTable(CatalogTable::make(), ['search' => '  key  '])->json('query.search'))->toBe('key')
        ->and(mb_strlen($this->queryTable(CatalogTable::make(), ['search' => str_repeat('a', 500)])->json('query.search')))->toBe(200);
});

it('ignores a search that is not text', function () {
    product('Keyboard');

    expect(namesFrom(CatalogTable::make(), ['search' => ['key']]))->toBe(['Keyboard']);
});

it('sorts up and down', function () {
    product('B');
    product('A');
    product('C');

    $table = CatalogTable::make();

    expect(namesFrom($table, ['sort' => 'name', 'per_page' => 50]))->toBe(['A', 'B', 'C'])
        ->and(namesFrom($table, ['sort' => '-name', 'per_page' => 50]))->toBe(['C', 'B', 'A']);
});

it('sorts through a relation', function () {
    product('Second', ['category_id' => Category::create(['name' => 'Z'])->id]);
    product('First', ['category_id' => Category::create(['name' => 'A'])->id]);

    expect(namesFrom(CatalogTable::make(), ['sort' => 'category.name']))->toBe(['First', 'Second'])
        ->and(namesFrom(CatalogTable::make(), ['sort' => '-category.name']))->toBe(['Second', 'First']);
});

it('sorts through a relation to its own table', function () {
    $older = Category::create(['name' => 'Z parent']);
    $newer = Category::create(['name' => 'A parent']);

    Category::create(['name' => 'Child of Z', 'parent_id' => $older->id]);
    Category::create(['name' => 'Child of A', 'parent_id' => $newer->id]);

    $rows = $this->queryTable(ChildCategoriesTable::make(), ['sort' => '-parent.name'])->assertOk()->json('rows');

    expect($rows[0]['cells']['name']['state'])->toBe('Child of Z');
});

it('lets the viewer\'s sort replace the order in query()', function () {
    product('A');
    product('B');

    expect(namesFrom(NewestFirstTable::make()))->toBe(['B', 'A'])
        ->and(namesFrom(NewestFirstTable::make(), ['sort' => 'name']))->toBe(['A', 'B']);
});

it('starts with the default sort and leaves it out of the url', function () {
    product('Cheap', ['price' => 1]);
    product('Dear', ['price' => 9]);

    $table = DefaultSortTable::make();

    expect(namesFrom($table))->toBe(['Dear', 'Cheap'])
        ->and($this->queryTable($table)->json('sort'))->toBe('-price')
        ->and($this->queryTable($table, ['sort' => '-price'])->json('query'))->toBe([])
        ->and($this->queryTable($table, ['sort' => 'price'])->json('query'))->toBe(['sort' => 'price']);
});

it('never shows a row twice across pages when values are equal', function () {
    collect(range(1, 5))->each(fn (int $number) => product("Row {$number}", ['price' => 5]));

    $seen = collect(range(1, 3))->flatMap(fn (int $page): array => namesFrom(CatalogTable::make(), ['sort' => 'price', 'page' => $page]));

    expect($seen->unique())->toHaveCount(5);
});

it('reads many related records', function () {
    $category = Category::create(['name' => 'Input']);

    product('Keyboard', ['category_id' => $category->id]);
    product('Mouse', ['category_id' => $category->id]);

    $rows = $this->queryTable(ChildCategoriesTable::make())->json('rows');

    expect($rows[0]['cells']['products.name']['state'])->toBe(['Keyboard', 'Mouse']);
});

it('loads relations up front, whatever the row count', function () {
    Model::preventLazyLoading();

    collect(range(1, 3))->each(fn (int $number) => product("Row {$number}", [
        'category_id' => Category::create(['name' => "Category {$number}"])->id,
    ]));

    $table = CatalogTable::make();

    DB::enableQueryLog();
    $response = $this->queryTable($table, ['per_page' => 50])->assertOk();

    expect(DB::getQueryLog())->toHaveCount(3)
        ->and($response->json('rows.0.cells')['category.name']['state'])->toBe('Category 1');

    Model::preventLazyLoading(false);
});

it('uses custom search and sort closures', function () {
    product('KEY', ['price' => 2]);
    product('other', ['price' => 1]);

    expect(namesFrom(CustomQueriesTable::make(), ['search' => 'key']))->toBe(['KEY'])
        ->and(namesFrom(CustomQueriesTable::make(), ['sort' => 'name']))->toBe(['other', 'KEY']);
});

it('never calls a model method that shares a column\'s name', function () {
    MethodNameTable::make()->toSchema();

    expect(Product::query()->count())->toBe(0);
});

it('leaves search out of the schema when nothing can be searched', function () {
    expect(ChildCategoriesTable::make()->toSchema()['searchable'])->toBeFalse()
        ->and(CatalogTable::make()->toSchema()['searchable'])->toBeTrue();
});

it('refuses a column it can\'t query, before the viewer clicks', function (string $table) {
    $table::make()->toSchema();
})->with([TwoHopSortTable::class, ManySortTable::class, JsonSearchTable::class])->throws(TableDefinitionException::class);
