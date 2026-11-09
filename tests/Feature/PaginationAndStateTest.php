<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Xelqun\Tables\Columns\TextColumn;
use Xelqun\Tables\Filters\TernaryFilter;
use Xelqun\Tables\Table;
use Xelqun\Tables\Tests\Fixtures\Product;

class PagedTable extends Table
{
    public static ?Closure $configure = null;

    protected function boot(): void
    {
        $this->paginationPageOptions([2, 'all']);

        if (self::$configure) {
            (self::$configure)($this);
        }
    }

    public function query(): Builder
    {
        return Product::query()->orderBy('name');
    }

    public function authorize(?Authenticatable $user): bool
    {
        return true;
    }

    public function columns(): array
    {
        return [TextColumn::make('name')->searchable()->sortable()];
    }

    public function filters(): array
    {
        return [
            TernaryFilter::make('is_featured')->trueLabel('Featured')->falseLabel('Plain'),
            TernaryFilter::make('secret')->nullable(),
            TernaryFilter::make('price_band')->queries(
                true: fn (Builder $query) => $query->where('price', '>=', 100),
                false: fn (Builder $query) => $query->where('price', '<', 100),
                blank: fn (Builder $query) => $query->where('status', '!=', 'hidden'),
            ),
        ];
    }
}

function pagedNames(array $query = []): array
{
    return collect(test()->queryTable(PagedTable::make(), $query)->json('rows'))->pluck('cells.name.state')->all();
}

beforeEach(function () {
    PagedTable::$configure = null;

    foreach (['A', 'B', 'C', 'D', 'E'] as $index => $name) {
        Product::create(['name' => $name, 'status' => 'live', 'price' => $index * 50, 'is_featured' => $index % 2 === 0, 'secret' => $index < 2 ? 'x' : null]);
    }

    Product::create(['name' => 'Hidden', 'status' => 'hidden']);
});

it('shows every row on one page when asked, or with no pager at all', function () {
    expect(pagedNames(['per_page' => 'all']))->toBe(['A', 'B', 'C', 'D', 'E'])
        ->and($this->queryTable(PagedTable::make(), ['per_page' => 'all'])->json('pagination'))->toMatchArray(['perPage' => 'all', 'lastPage' => 1])
        ->and($this->queryTable(PagedTable::make(), ['per_page' => 'all'])->json('query.per_page'))->toBe('all');

    PagedTable::$configure = fn (Table $table) => $table->paginated(false);

    expect(pagedNames())->toHaveCount(5)
        ->and($this->queryTable(PagedTable::make())->json('pagination.hidden'))->toBeTrue();
});

it('pages without counting, simply or by cursor', function () {
    PagedTable::$configure = fn (Table $table) => $table->paginationMode('simple');

    $simple = $this->queryTable(PagedTable::make(), ['page' => 2])->json('pagination');

    expect($simple)->toMatchArray(['mode' => 'simple', 'page' => 2, 'hasMore' => true])->not->toHaveKey('total')
        ->and(pagedNames(['page' => 3]))->toBe(['E']);

    PagedTable::$configure = fn (Table $table) => $table->paginationMode('cursor');

    $first = $this->queryTable(PagedTable::make())->json();
    $second = $this->queryTable(PagedTable::make(), ['cursor' => $first['pagination']['next']])->json();

    expect(collect($first['rows'])->pluck('cells.name.state')->all())->toBe(['A', 'B'])
        ->and(collect($second['rows'])->pluck('cells.name.state')->all())->toBe(['C', 'D'])
        ->and($second['pagination']['previous'])->not->toBeNull()
        ->and(pagedNames(['cursor' => 'not a cursor']))->toBe(['A', 'B']);
});

it('renders without rows when loading is deferred', function () {
    PagedTable::$configure = fn (Table $table) => $table->deferLoading()->deferFilters(false);

    $schema = PagedTable::make()->toSchema();

    expect($schema['rows'])->toBe([])
        ->and($schema)->toMatchArray(['deferred' => true, 'liveFilters' => true]);
});

it('labels a ternary filter, checks for null, and runs its own queries', function () {
    expect($this->queryTable(PagedTable::make(), ['filters' => ['is_featured' => '1']])->json('indicators.0.label'))->toBe('Is Featured: Featured')
        ->and(pagedNames(['filters' => ['secret' => '1'], 'per_page' => 'all']))->toBe(['A', 'B'])
        ->and(pagedNames(['filters' => ['secret' => '0'], 'per_page' => 'all']))->toBe(['C', 'D', 'E'])
        ->and(pagedNames(['filters' => ['price_band' => '1'], 'per_page' => 'all']))->toBe(['C', 'D', 'E'])
        ->and(pagedNames(['filters' => ['price_band' => '0'], 'per_page' => 'all']))->toBe(['A', 'B', 'Hidden']);
});

it('keeps search, sort and filters in the session, and a link with its own state wins', function () {
    PagedTable::$configure = fn (Table $table) => $table->persistSearchInSession()->persistSortInSession()->persistFiltersInSession();

    $this->queryTable(PagedTable::make(), ['search' => 'b', 'sort' => '-name', 'filters' => ['is_featured' => '0'], 'page' => 2]);

    expect(collect(session('xelqun-tables.PagedTable.paged-table'))->first())
        ->toBe(['search' => 'b', 'sort' => '-name', 'filters' => ['is_featured' => 0]]);

    expect(json_decode(json_encode(PagedTable::make()->toSchema()), true)['query'])->toMatchArray(['search' => 'b', 'sort' => '-name']);

    request()->query->set('paged-table', ['search' => 'c']);

    expect(json_decode(json_encode(PagedTable::make()->toSchema()), true)['query'])->toBe(['search' => 'c']);
});
