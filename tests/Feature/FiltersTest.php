<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Route;
use Xelqun\Forms\Components\Select;
use Xelqun\Forms\Components\TextInput;
use Xelqun\Forms\Layouts\Section;
use Xelqun\Forms\State\Get;
use Xelqun\Tables\Columns\TextColumn;
use Xelqun\Tables\Exceptions\TableDefinitionException;
use Xelqun\Tables\Filters\Filter;
use Xelqun\Tables\Filters\SelectFilter;
use Xelqun\Tables\Filters\TernaryFilter;
use Xelqun\Tables\Table;
use Xelqun\Tables\Tests\Fixtures\Category;
use Xelqun\Tables\Tests\Fixtures\Product;

class FilteredTable extends Table
{
    public static bool $allowed = true;

    public static bool $showsStatus = true;

    public function query(): Builder
    {
        return Product::query();
    }

    public function authorize(?Authenticatable $user): bool
    {
        return self::$allowed;
    }

    public function columns(): array
    {
        return [TextColumn::make('name')->searchable()];
    }

    public function filters(): array
    {
        return [
            SelectFilter::make('status')->options(['draft' => 'Draft', 'active' => 'Active'])->hidden(fn (): bool => ! self::$showsStatus),
            SelectFilter::make('kinds')->attribute('status')->multiple()->options(['draft' => 'Draft', 'active' => 'Active']),
            SelectFilter::make('category')->relationship('category', 'name'),
            TernaryFilter::make('is_featured'),
            Filter::make('priced')->query(fn (Builder $query) => $query->where('price', '>', 0))->indicateUsing(fn (array $data): string => 'With a price'),
            Filter::make('range')
                ->schema([
                    TextInput::make('min')->numeric(),
                    TextInput::make('max')->numeric(),
                ])
                ->query(fn (Builder $query, array $data) => $query
                    ->when($data['min'] ?? null, fn (Builder $query, mixed $min) => $query->where('price', '>=', $min))
                    ->when($data['max'] ?? null, fn (Builder $query, mixed $max) => $query->where('price', '<=', $max))),
        ];
    }
}

class SearchedCategoryTable extends FilteredTable
{
    public function filters(): array
    {
        return [SelectFilter::make('category')->relationship('category', 'name')->searchable()];
    }
}

class FalseDefaultTable extends FilteredTable
{
    public function filters(): array
    {
        return [TernaryFilter::make('is_featured')->default(false)];
    }
}

class CodeFilterTable extends FilteredTable
{
    public function filters(): array
    {
        return [
            Filter::make('code')
                ->schema([TextInput::make('code')])
                ->query(fn (Builder $query, array $data) => $query->where('name', $data['code'])),
        ];
    }
}

class LeadingZeroTable extends FilteredTable
{
    public function filters(): array
    {
        return [SelectFilter::make('status')->options(['0012' => 'Twelve', 'other' => 'Other'])];
    }
}

class SwitchedOnTable extends FilteredTable
{
    public function filters(): array
    {
        return [Filter::make('priced')->default(true)->query(fn (Builder $query) => $query->where('price', '>', 0))];
    }
}

class LayoutFilterTable extends FilteredTable
{
    public function filters(): array
    {
        return [
            Filter::make('price')
                ->schema([Section::make('Price')->schema([TextInput::make('from')->numeric()])])
                ->query(fn (Builder $query, array $data) => $query->where('price', '>=', $data['from'])),
        ];
    }
}

class NoQueryFilterTable extends FilteredTable
{
    public function filters(): array
    {
        return [Filter::make('price')->schema([TextInput::make('min')])];
    }
}

class ToggleColumnTable extends FilteredTable
{
    public function filters(): array
    {
        return [Filter::make('is_featured')];
    }
}

class DefaultFilterTable extends FilteredTable
{
    public function filters(): array
    {
        return [SelectFilter::make('status')->options(['draft' => 'Draft', 'active' => 'Active'])->default('active')];
    }
}

class OrWhereFiltersTable extends FilteredTable
{
    public function query(): Builder
    {
        return Product::query()->where('name', 'Alpha')->orWhere('name', 'Beta');
    }

    public function filters(): array
    {
        return [
            Filter::make('either')->query(fn (Builder $query) => $query->where('status', 'draft')->orWhere('status', 'active')),
        ];
    }
}

class DependentFilterTable extends FilteredTable
{
    public function filters(): array
    {
        return [
            Filter::make('place')->schema([
                Select::make('kind')->live()->options(['a' => 'A', 'b' => 'B']),
                Select::make('sub')->options(fn (Get $get): array => $get('kind') === 'a' ? ['one' => 'One'] : ['two' => 'Two']),
            ])->query(fn (Builder $query) => $query),
        ];
    }
}

function names(Table $table, array $filters): array
{
    return collect(test()->queryTable($table, ['filters' => $filters])->assertOk()->json('rows'))
        ->pluck('cells.name.state')
        ->all();
}

beforeEach(function () {
    FilteredTable::$allowed = true;
    FilteredTable::$showsStatus = true;
});

it('keeps rows with the chosen option', function () {
    Product::create(['name' => 'Draft one', 'status' => 'draft']);
    Product::create(['name' => 'Active one', 'status' => 'active']);

    expect(names(FilteredTable::make(), ['status' => 'draft']))->toBe(['Draft one']);
});

it('takes several options and another column', function () {
    Product::create(['name' => 'A', 'status' => 'draft']);
    Product::create(['name' => 'B', 'status' => 'active']);
    Product::create(['name' => 'C', 'status' => 'gone']);

    expect(names(FilteredTable::make(), ['kinds' => ['draft', 'active']]))->toBe(['A', 'B']);
});

it('filters through a relation and lists its records', function () {
    $wanted = Category::create(['name' => 'Wanted']);
    $other = Category::create(['name' => 'Other']);

    Product::create(['name' => 'In', 'category_id' => $wanted->id]);
    Product::create(['name' => 'Out', 'category_id' => $other->id]);

    $table = FilteredTable::make();

    expect(names($table, ['category' => (string) $wanted->id]))->toBe(['In']);

    $node = collect($table->toSchema()['filters']['components'])->firstWhere('path', 'data.category');
    $props = json_decode(json_encode($node), true)['props'];

    expect(collect($props['options'])->pluck('label')->all())->toBe(['Other', 'Wanted']);
});

it('searches a related list on the server instead of sending it', function () {
    $wanted = Category::create(['name' => 'Wanted']);
    Category::create(['name' => 'Other']);
    Product::create(['name' => 'In', 'category_id' => $wanted->id]);
    Product::create(['name' => 'Out']);

    $schema = SearchedCategoryTable::make()->toSchema();
    $node = json_decode(json_encode(collect($schema['filters']['components'])->firstWhere('path', 'data.category')), true);

    expect($node['props']['options'])->toBe([])
        ->and($node['props']['async'])->toBeTrue()
        ->and(names(SearchedCategoryTable::make(), ['category' => (string) $wanted->id]))->toBe(['In']);

    $this->postJson(route('xelqun.forms.search'), ['token' => $schema['filters']['token'], 'path' => 'data.category', 'search' => 'want'])
        ->assertOk()
        ->assertJsonPath('options', [['value' => $wanted->id, 'label' => 'Wanted']]);
});

it('searches a long related list on the server even when not asked to', function () {
    Category::query()->insert(array_map(fn (int $index): array => ['name' => "Category {$index}"], range(1, Select::PRELOAD_LIMIT + 1)));

    $node = json_decode(json_encode(collect(FilteredTable::make()->toSchema()['filters']['components'])->firstWhere('path', 'data.category')), true);

    expect($node['props']['async'])->toBeTrue()
        ->and($node['props']['options'])->toBe([]);
});

it('tells yes, no and either apart', function () {
    Product::create(['name' => 'Featured', 'is_featured' => true]);
    Product::create(['name' => 'Plain', 'is_featured' => false]);

    expect(names(FilteredTable::make(), ['is_featured' => '1']))->toBe(['Featured'])
        ->and(names(FilteredTable::make(), ['is_featured' => '0']))->toBe(['Plain'])
        ->and(names(FilteredTable::make(), ['is_featured' => null]))->toBe(['Featured', 'Plain']);
});

it('reads true and false as yes and no', function () {
    Product::create(['name' => 'Featured', 'is_featured' => true]);
    Product::create(['name' => 'Plain', 'is_featured' => false]);

    expect(names(FilteredTable::make(), ['is_featured' => false]))->toBe(['Plain'])
        ->and(names(FilteredTable::make(), ['is_featured' => true]))->toBe(['Featured']);
});

it('starts on no when a switch defaults to false, and keeps that out of the url', function () {
    Product::create(['name' => 'Featured', 'is_featured' => true]);
    Product::create(['name' => 'Plain', 'is_featured' => false]);

    $response = $this->queryTable(FalseDefaultTable::make())->assertOk();

    expect(collect($response->json('rows'))->pluck('cells.name.state')->all())->toBe(['Plain'])
        ->and($response->json('query'))->not->toHaveKey('filters');
});

it('runs a filter\'s query only while it is on', function () {
    Product::create(['name' => 'Free', 'price' => 0]);
    Product::create(['name' => 'Paid', 'price' => 5]);

    expect(names(FilteredTable::make(), ['priced' => true]))->toBe(['Paid'])
        ->and(names(FilteredTable::make(), ['priced' => false]))->toBe(['Free', 'Paid']);
});

it('leaves a switch off when the url says so', function () {
    Product::create(['name' => 'Free', 'price' => 0]);

    $response = $this->queryTable(FilteredTable::make(), ['filters' => ['priced' => '0']]);

    expect($response->json('activeFilters'))->toBe(0)
        ->and($response->json('rows'))->toHaveCount(1);
});

it('keeps a switch off when it defaults to on', function () {
    Product::create(['name' => 'Free', 'price' => 0]);
    Product::create(['name' => 'Paid', 'price' => 5]);

    $table = SwitchedOnTable::make();

    expect(names($table, []))->toBe(['Paid'])
        ->and(names($table, ['priced' => '0']))->toBe(['Free', 'Paid'])
        ->and($this->queryTable($table, ['filters' => ['priced' => '0']])->json('query.filters.priced'))->toBe('0');
});

it('counts zero in a number field as a value', function () {
    Product::create(['name' => 'Free', 'price' => 0]);

    $this->queryTable(FilteredTable::make(), ['filters' => ['max' => '0']])->assertJsonPath('activeFilters', 1);
});

it('reads fields inside a layout', function () {
    Product::create(['name' => 'Free', 'price' => 0]);
    Product::create(['name' => 'Paid', 'price' => 5]);

    expect(names(LayoutFilterTable::make(), ['from' => '1']))->toBe(['Paid']);
});

it('gives each table on a page its own filter form', function () {
    expect(FilteredTable::make()->id('first')->getFiltersForm()->getId())->toBe('first-filters')
        ->and(FilteredTable::make()->id('second')->getFiltersForm()->getId())->toBe('second-filters');
});

it('asks for a query when a filter has fields', function () {
    Product::create(['name' => 'A']);

    $this->withoutExceptionHandling();
    $this->queryTable(NoQueryFilterTable::make(), ['filters' => ['min' => '1']]);
})->throws(TableDefinitionException::class);

it('reads a toggle from the url', function () {
    Product::create(['name' => 'Free', 'price' => 0]);
    Product::create(['name' => 'Paid', 'price' => 5]);

    expect(names(FilteredTable::make(), ['priced' => '1']))->toBe(['Paid']);
});

it('filters a column of the same name when a switch has no query', function () {
    Product::create(['name' => 'Featured', 'is_featured' => true]);
    Product::create(['name' => 'Plain']);

    expect(names(ToggleColumnTable::make(), ['is_featured' => true]))->toBe(['Featured']);
});

it('passes a filter its own fields', function () {
    Product::create(['name' => 'Cheap', 'price' => 1]);
    Product::create(['name' => 'Middle', 'price' => 5]);
    Product::create(['name' => 'Dear', 'price' => 9]);

    expect(names(FilteredTable::make(), ['min' => '2', 'max' => '8']))->toBe(['Middle']);
});

it('counts the active filters, not their fields', function () {
    Product::create(['name' => 'A']);

    $this->queryTable(FilteredTable::make(), ['filters' => ['min' => '1', 'max' => '8', 'status' => 'draft']])
        ->assertJsonPath('activeFilters', 2);
    $this->queryTable(FilteredTable::make())->assertJsonPath('activeFilters', 0);
});

it('ignores a field no filter declares', function () {
    Product::create(['name' => 'A', 'secret' => 'x']);

    $response = $this->queryTable(FilteredTable::make(), ['filters' => ['secret' => 'nothing', 'id' => 999]])->assertOk();

    expect($response->json('rows'))->toHaveCount(1)
        ->and($response->json('query'))->toBe([]);
});

it('ignores a value its field would reject', function (array $filters) {
    Product::create(['name' => 'A', 'status' => 'draft']);

    $response = $this->queryTable(FilteredTable::make(), ['filters' => $filters])->assertOk();

    expect($response->json('rows'))->toHaveCount(1)
        ->and($response->json('query'))->toBe([])
        ->and($response->json('activeFilters'))->toBe(0);
})->with([
    'an option nobody offered' => [['status' => 'hacked']],
    'a list for a single value' => [['status' => ['draft', 'active']]],
    'one bad value in a list' => [['kinds' => ['draft', 'hacked']]],
    'a record that does not exist' => [['category' => '999']],
    'text in a number field' => [['min' => 'lots']],
]);

it('ignores filters that are not a list', function () {
    $this->queryTable(FilteredTable::make(), ['filters' => 'status'])->assertOk()->assertJsonPath('activeFilters', 0);
});

it('skips a hidden filter', function () {
    FilteredTable::$showsStatus = false;
    Product::create(['name' => 'A', 'status' => 'draft']);
    Product::create(['name' => 'B', 'status' => 'active']);

    expect(names(FilteredTable::make(), ['status' => 'draft']))->toBe(['A', 'B']);
});

it('starts with a default and leaves it out of the url', function () {
    Product::create(['name' => 'Draft', 'status' => 'draft']);
    Product::create(['name' => 'Active', 'status' => 'active']);

    $table = DefaultFilterTable::make();

    expect(names($table, []))->toBe(['Active'])
        ->and($this->queryTable($table, ['filters' => ['status' => 'active']])->json('query'))->toBe([])
        ->and($this->queryTable($table, ['filters' => ['status' => 'draft']])->json('query'))->toBe(['filters' => ['status' => 'draft']]);
});

it('tells a cleared filter from an untouched one', function () {
    Product::create(['name' => 'Draft', 'status' => 'draft']);
    Product::create(['name' => 'Active', 'status' => 'active']);

    $table = DefaultFilterTable::make();

    $cleared = $this->queryTable($table, ['filters' => ['status' => null]]);

    expect($cleared->json('rows'))->toHaveCount(2)
        ->and($cleared->json('query'))->toHaveKey('filters')
        ->and(names($table, ['status' => '']))->toBe(['Draft', 'Active']);
});

it('keeps a filter\'s orWhere inside the filter and the table', function () {
    Product::create(['name' => 'Alpha', 'status' => 'draft']);
    Product::create(['name' => 'Beta', 'status' => 'gone']);
    Product::create(['name' => 'Outside', 'status' => 'active']);

    expect(names(OrWhereFiltersTable::make(), ['either' => true]))->toBe(['Alpha']);
});

it('combines with search', function () {
    Product::create(['name' => 'Keyboard', 'status' => 'draft']);
    Product::create(['name' => 'Keypad', 'status' => 'active']);

    $this->queryTable(FilteredTable::make(), ['search' => 'key', 'filters' => ['status' => 'active']])
        ->assertJsonPath('pagination.total', 1)
        ->assertJsonPath('rows.0.cells.name.state', 'Keypad');
});

it('matches a url value to an option with an integer key', function () {
    $category = Category::create(['name' => 'Input']);

    Product::create(['name' => 'In', 'category_id' => $category->id, 'is_featured' => true]);

    Route::get('/filtered', fn () => response()->json(FilteredTable::make()->toSchema()));

    $response = $this->getJson("/filtered?filtered-table[filters][category]={$category->id}&filtered-table[filters][is_featured]=1&filtered-table[filters][kinds][0]=draft");

    expect($response->json('filters.state.data.category'))->toBe($category->id)
        ->and($response->json('filters.state.data.is_featured'))->toBe(1)
        ->and($response->json('filters.state.data.kinds'))->toBe(['draft']);
});

it('keeps digits in a text field as text', function () {
    Product::create(['name' => '123']);
    Product::create(['name' => 'Other']);

    $response = $this->queryTable(CodeFilterTable::make(), ['filters' => ['code' => '123']])->assertOk();

    expect($response->json('rows'))->toHaveCount(1)
        ->and($response->json('query.filters.code'))->toBe('123');
});

it('leaves a select value with leading zeros as text', function () {
    Product::create(['name' => 'A', 'status' => '0012']);
    Product::create(['name' => 'B', 'status' => 'other']);

    $response = $this->queryTable(LeadingZeroTable::make(), ['filters' => ['status' => '0012']])->assertOk();

    expect($response->json('rows'))->toHaveCount(1);
});

it('reads the first render from the url and fills the form', function () {
    Product::create(['name' => 'Draft', 'status' => 'draft']);
    Product::create(['name' => 'Active', 'status' => 'active']);

    Route::get('/filtered', fn () => response()->json(FilteredTable::make()->toSchema()));

    $response = $this->getJson('/filtered?filtered-table[filters][status]=draft&filtered-table[filters][nope]=1');

    expect($response->json('rows'))->toHaveCount(1)
        ->and($response->json('filters.state.data.status'))->toBe('draft')
        ->and($response->json('filters.state.data'))->not->toHaveKey('nope')
        ->and($response->json('query'))->toBe(['filters' => ['status' => 'draft']]);
});

it('sends no filter form to a table without filters', function () {
    expect(ContextlessTable::make()->toSchema()['filters'])->toBeNull();
});

class ContextlessTable extends Table
{
    public function query(): Builder
    {
        return Product::query();
    }

    public function columns(): array
    {
        return [TextColumn::make('name')];
    }
}

it('updates dependent options through the forms endpoint', function () {
    $form = DependentFilterTable::make()->getFiltersForm();

    $response = $this->postJson(route('xelqun.forms.update'), [
        'token' => $form->makeToken(),
        'seq' => 1,
        'state' => ['data' => ['kind' => 'a', 'sub' => null]],
        'changed' => ['data.kind'],
        'deps' => ['data.sub' => ['data.kind']],
    ])->assertOk();

    expect(collect($response->json('schema')['data.sub']['props']['options'])->pluck('value')->all())->toBe(['one']);
});

it('rejects a value the chosen parent does not offer', function () {
    $response = $this->queryTable(DependentFilterTable::make(), ['filters' => ['kind' => 'a', 'sub' => 'two']])->assertOk();

    expect($response->json('query.filters'))->toBe(['kind' => 'a']);
});

it('checks the table\'s authorize on the filter form\'s own endpoints', function () {
    $token = DependentFilterTable::make()->getFiltersForm()->makeToken();

    FilteredTable::$allowed = false;

    $this->postJson(route('xelqun.forms.update'), [
        'token' => $token,
        'seq' => 1,
        'state' => ['data' => []],
        'changed' => [],
        'deps' => (object) [],
    ])->assertForbidden();
});

it('names each active filter in a chip, with the fields removing it clears', function () {
    $category = Category::create(['name' => 'Desks']);

    $this->queryTable(FilteredTable::make(), ['filters' => ['status' => 'active', 'category' => (string) $category->getKey(), 'is_featured' => '0', 'priced' => true, 'min' => 5]])
        ->assertOk()
        ->assertJsonPath('indicators', [
            ['label' => 'Status: Active', 'fields' => ['status']],
            ['label' => 'Category: Desks', 'fields' => ['category']],
            ['label' => 'Is Featured: No', 'fields' => ['is_featured']],
            ['label' => 'With a price', 'fields' => ['priced']],
            ['label' => 'Range', 'fields' => ['min', 'max']],
        ]);
});
