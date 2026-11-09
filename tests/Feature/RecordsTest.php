<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Xelqun\Actions\Action;
use Xelqun\Tables\Actions\BulkAction;
use Xelqun\Tables\Columns\TextColumn;
use Xelqun\Tables\Table;
use Xelqun\Tables\Tests\Fixtures\Category;
use Xelqun\Tables\Tests\Fixtures\Product;

class SelectableTable extends Table
{
    public static array $calls = [];

    protected function boot(): void
    {
        $this->checkIfRecordIsSelectableUsing(fn (Product $record): bool => $record->status !== 'locked');
    }

    public function query(): Builder
    {
        return Product::query();
    }

    public function authorize(?Authenticatable $user): bool
    {
        return true;
    }

    public function columns(): array
    {
        return [
            TextColumn::make('name')->searchable(isIndividual: true, isGlobal: false),
            TextColumn::make('status')->searchable(),
        ];
    }

    public function recordActions(): array
    {
        return [Action::make('open')->authorize(true)->action(fn () => null)];
    }

    public function recordAction(Model $record): ?string
    {
        return 'open';
    }

    public function bulkActions(): array
    {
        return [
            BulkAction::make('collect')->authorize(true)->action(function (Collection $records) {
                self::$calls[] = $records->pluck('name')->sort()->values()->all();
            }),
            BulkAction::make('archive')->authorize(true)->authorizeIndividualRecords('archive')->action(function (Collection $records, Builder $query) {
                self::$calls[] = [$records->pluck('name')->sort()->values()->all(), $query->count()];
            }),
        ];
    }
}

class CategoryTotalsTable extends Table
{
    public function query(): Builder
    {
        return Category::query();
    }

    public function authorize(?Authenticatable $user): bool
    {
        return true;
    }

    public function columns(): array
    {
        return [
            TextColumn::make('name'),
            TextColumn::make('products_count')->counts('products')->sortable(),
            TextColumn::make('stock_value')->sum('products', 'price')->sortable(),
        ];
    }
}

beforeEach(function () {
    SelectableTable::$calls = [];
});

it('marks rows that can not be selected, and leaves them out of select all and bulk actions', function () {
    $open = Product::create(['name' => 'Open', 'status' => 'live']);
    $locked = Product::create(['name' => 'Locked', 'status' => 'locked']);

    $rows = collect($this->queryTable(SelectableTable::make())->json('rows'))->keyBy('key');

    expect($rows[$open->id])->not->toHaveKey('selectable')
        ->and($rows[$locked->id]['selectable'])->toBeFalse()
        ->and($rows[$open->id]['action'])->toBe('open');

    $this->postJson(route('xelqun.tables.query'), ['token' => SelectableTable::make()->makeToken(), 'query' => [], 'keys' => true])
        ->assertJsonPath('keys', [(string) $open->id]);

    $this->runBulkTableAction(SelectableTable::make(), 'collect', [$open->id, $locked->id])->assertOk();
    $this->runBulkTableAction(SelectableTable::make(), 'collect', [$locked->id])->assertNotFound();

    expect(SelectableTable::$calls)->toBe([['Open']]);
});

it('runs a bulk action on the records the policy allows, and refuses when it allows none', function () {
    $yes = Product::create(['name' => 'Yes', 'status' => 'live']);
    $no = Product::create(['name' => 'No', 'status' => 'live']);

    Gate::define('archive', fn (?User $user, Product $product): bool => $product->name === 'Yes');

    $this->runBulkTableAction(SelectableTable::make(), 'archive', [$yes->id, $no->id])->assertOk();
    $this->runBulkTableAction(SelectableTable::make(), 'archive', [$no->id])->assertForbidden();

    expect(SelectableTable::$calls)->toBe([[['Yes'], 1]]);
});

it('searches a column in its own box, and leaves it out of the search above the table', function () {
    Product::create(['name' => 'Desk', 'status' => 'live']);
    Product::create(['name' => 'Lamp', 'status' => 'desk']);

    $names = fn (array $query): array => collect($this->queryTable(SelectableTable::make(), $query)->json('rows'))->pluck('cells.name.state')->all();

    expect($names(['column_search' => ['name' => 'des']]))->toBe(['Desk'])
        ->and($names(['search' => 'desk']))->toBe(['Lamp'])
        ->and($names(['column_search' => ['status' => 'desk']]))->toBe(['Desk', 'Lamp'])
        ->and($this->queryTable(SelectableTable::make(), ['column_search' => ['name' => 'des']])->json('query.column_search'))->toBe(['name' => 'des']);
});

it('counts and sums related records, and sorts by them', function () {
    $desks = Category::create(['name' => 'Desks']);
    $lamps = Category::create(['name' => 'Lamps']);
    Product::create(['name' => 'A', 'category_id' => $desks->id, 'price' => 10]);
    Product::create(['name' => 'B', 'category_id' => $desks->id, 'price' => 5]);
    Product::create(['name' => 'C', 'category_id' => $lamps->id, 'price' => 40]);

    $rows = $this->queryTable(CategoryTotalsTable::make(), ['sort' => '-products_count'])->json('rows');

    expect(collect($rows)->pluck('cells.name.state')->all())->toBe(['Desks', 'Lamps'])
        ->and($rows[0]['cells']['products_count']['state'])->toBe(2)
        ->and((float) $rows[0]['cells']['stock_value']['state'])->toBe(15.0)
        ->and(collect($this->queryTable(CategoryTotalsTable::make(), ['sort' => '-stock_value'])->json('rows'))->pluck('cells.name.state')->all())->toBe(['Lamps', 'Desks']);
});
