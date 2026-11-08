<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Xelqun\Tables\Columns\CheckboxColumn;
use Xelqun\Tables\Columns\ColorColumn;
use Xelqun\Tables\Columns\Column;
use Xelqun\Tables\Columns\SelectColumn;
use Xelqun\Tables\Columns\TextColumn;
use Xelqun\Tables\Columns\TextInputColumn;
use Xelqun\Tables\Columns\ToggleColumn;
use Xelqun\Tables\Exceptions\TableDefinitionException;
use Xelqun\Tables\State\TableState;
use Xelqun\Tables\Summarizers\Average;
use Xelqun\Tables\Summarizers\Count;
use Xelqun\Tables\Summarizers\Range;
use Xelqun\Tables\Summarizers\Sum;
use Xelqun\Tables\Summarizers\Summarizer;
use Xelqun\Tables\Table;
use Xelqun\Tables\Tests\Fixtures\Category;
use Xelqun\Tables\Tests\Fixtures\Product;

class EditableTable extends Table
{
    /** @var array<int, Column> */
    public static array $columns = [];

    public static ?string $group = null;

    protected function boot(): void
    {
        $this->defaultGroup(self::$group);
    }

    public function query(): Builder
    {
        return Product::query()->where('status', '!=', 'hidden');
    }

    public function columns(): array
    {
        return self::$columns;
    }
}

function saveCell(string $column, mixed $value, int|string $record, ?Table $table = null): TestResponse
{
    return test()->postJson(route('xelqun.tables.update'), [
        'token' => ($table ?? EditableTable::make())->toSchema()['token'],
        'record' => (string) $record,
        'column' => $column,
        'value' => $value,
    ]);
}

beforeEach(function () {
    EditableTable::$group = null;
    $this->product = Product::create(['name' => 'Lamp', 'status' => 'draft', 'is_featured' => false, 'price' => 10]);
});

describe('editing a cell', function () {
    it('saves a toggle, and answers with the new cell', function () {
        EditableTable::$columns = [ToggleColumn::make('is_featured')->authorize(true)];

        saveCell('is_featured', true, $this->product->id)->assertOk()->assertJsonPath('cell.state', 1);

        expect($this->product->fresh()->is_featured)->toEqual(true);
    });

    it('saves text and a checkbox', function () {
        EditableTable::$columns = [TextInputColumn::make('name')->authorize(true), CheckboxColumn::make('is_featured')->authorize(true)];

        saveCell('name', 'Bulb', $this->product->id)->assertOk();
        saveCell('is_featured', true, $this->product->id)->assertOk();

        expect($this->product->fresh())->name->toBe('Bulb')->is_featured->toEqual(true);
    });

    it('is read-only until a column says who can edit it', function () {
        EditableTable::$columns = [ToggleColumn::make('is_featured')];

        saveCell('is_featured', true, $this->product->id)->assertForbidden();

        expect($this->product->fresh()->is_featured)->toEqual(false);
    });

    it('tells the browser which rows can be edited', function () {
        EditableTable::$columns = [ToggleColumn::make('is_featured')->authorize(fn (Product $record): bool => $record->name === 'Lamp')];
        Product::create(['name' => 'Other', 'status' => 'draft']);

        expect(collect(EditableTable::make()->toSchema()['rows'])->pluck('cells.is_featured.canEdit')->all())->toEqualCanonicalizing([true, false]);
    });

    it('asks the policy for an ability', function () {
        Gate::define('toggle', fn ($user, Product $product): bool => $product->name === 'Lamp');
        $this->actingAs(new User);
        EditableTable::$columns = [ToggleColumn::make('is_featured')->authorize('toggle')];
        $other = Product::create(['name' => 'Other', 'status' => 'draft']);

        saveCell('is_featured', true, $other->id)->assertForbidden();
        saveCell('is_featured', true, $this->product->id)->assertOk();
    });

    it('can\'t reach a row outside the table\'s query', function () {
        EditableTable::$columns = [ToggleColumn::make('is_featured')->authorize(true)];
        $hidden = Product::create(['name' => 'Hidden', 'status' => 'hidden']);

        saveCell('is_featured', true, $hidden->id)->assertNotFound();

        expect($hidden->fresh()->is_featured)->toEqual(false);
    });

    it('can\'t write a column that isn\'t editable, or isn\'t there', function (string $column) {
        EditableTable::$columns = [ToggleColumn::make('is_featured')->authorize(true), TextColumn::make('name')];

        saveCell($column, 'x', $this->product->id)->assertNotFound();

        expect($this->product->fresh()->name)->toBe('Lamp');
    })->with(['read only' => 'name', 'not a column' => 'secret']);

    it('checks the value', function () {
        EditableTable::$columns = [
            TextInputColumn::make('name')->authorize(true)->rules(['required', 'max:5']),
            ToggleColumn::make('is_featured')->authorize(true),
        ];

        saveCell('name', 'Far too long', $this->product->id)->assertUnprocessable()
            ->assertJsonPath('errors.value.0', 'The Name field must not be greater than 5 characters.');
        saveCell('name', ['an', 'array'], $this->product->id)->assertUnprocessable();
        saveCell('is_featured', 'maybe', $this->product->id)->assertUnprocessable();

        expect($this->product->fresh()->name)->toBe('Lamp');
    });

    it('only takes a select\'s own choices', function () {
        EditableTable::$columns = [SelectColumn::make('status')->authorize(true)->options(['draft' => 'Draft', 'live' => 'Live'])];

        saveCell('status', 'hacked', $this->product->id)->assertUnprocessable();
        saveCell('status', 'live', $this->product->id)->assertOk();

        expect($this->product->fresh()->status)->toBe('live');
    });

    it('hands saving over to a closure', function () {
        EditableTable::$columns = [TextInputColumn::make('name')->authorize(true)->updateStateUsing(fn (string $state, Product $record) => $record->update(['name' => strtoupper($state)]))];

        saveCell('name', 'bulb', $this->product->id)->assertOk();

        expect($this->product->fresh()->name)->toBe('BULB');
    });

    it('explains a relation column', function () {
        EditableTable::$columns = [TextInputColumn::make('category.name')->authorize(true)];

        $this->withoutExceptionHandling();

        saveCell('category.name', 'x', $this->product->id);
    })->throws(TableDefinitionException::class);
});

describe('a color column', function () {
    it('sends the value for a swatch', function () {
        EditableTable::$columns = [ColorColumn::make('status')];

        expect(EditableTable::make()->toSchema()['columns'][0]['type'])->toBe('color-column');
    });
});

describe('summaries', function () {
    beforeEach(function () {
        Product::create(['name' => 'B', 'status' => 'draft', 'price' => 30]);
        Product::create(['name' => 'Hidden', 'status' => 'hidden', 'price' => 1000]);
    });

    function summaries(array $query = []): array
    {
        return EditableTable::make()->toResult(TableState::from($query, EditableTable::make()))['summaries'];
    }

    it('adds up every row of the query, across pages', function () {
        EditableTable::$columns = [TextColumn::make('price')->summarize([
            Sum::make()->label('Total'),
            Average::make(),
            Count::make(),
            Range::make(),
        ])];

        expect(summaries()['price'])->toEqual([
            ['label' => 'Total', 'value' => 40],
            ['label' => null, 'value' => 20],
            ['label' => null, 'value' => 2],
            ['label' => null, 'value' => '10 – 30'],
        ]);
    });

    it('works out a column\'s summaries in one query, and still runs a summarizer of its own', function () {
        EditableTable::$columns = [TextColumn::make('price')->summarize([Sum::make(), Average::make(), Count::make(), Range::make(), new class extends Summarizer
        {
            public function summarize(Builder $query, string $column): mixed
            {
                return $query->max($column) * 2;
            }
        }])];
        $aggregateQueries = 0;
        DB::listen(function ($query) use (&$aggregateQueries): void {
            $aggregateQueries += (int) preg_match('/\b(sum|avg|count|min|max)\(/i', $query->sql);
        });
        $table = EditableTable::make();
        $state = TableState::from([], $table);
        $aggregateQueries = 0;

        $lines = $table->toResult($state)['summaries']['price'];

        expect($lines[4]['value'])->toEqual(60)
            // The count for pagination, the shared one and the custom one.
            ->and($aggregateQueries)->toBe(3);
    });

    it('formats like the column', function () {
        EditableTable::$columns = [TextColumn::make('price')->money('USD')->summarize(Sum::make())];

        expect(summaries()['price'][0]['value'])->toBe('$40.00');
    });

    it('follows the search', function () {
        EditableTable::$columns = [TextColumn::make('name')->searchable(), TextColumn::make('price')->summarize(Sum::make())];

        expect(summaries(['search' => 'Lamp'])['price'][0]['value'])->toEqual(10);
    });

    it('sends nothing for a table with no summaries', function () {
        EditableTable::$columns = [TextColumn::make('name')];

        expect(summaries())->toBe([]);
    });
});

describe('grouping', function () {
    it('keeps a group\'s rows together, whatever the sort', function () {
        Product::create(['name' => 'Aa', 'status' => 'live']);
        Product::create(['name' => 'Zz', 'status' => 'draft']);
        EditableTable::$columns = [TextColumn::make('name')->sortable(), TextColumn::make('status')];
        EditableTable::$group = 'status';

        $table = EditableTable::make();
        $rows = $table->toResult(TableState::from(['sort' => 'name'], $table))['rows'];

        expect(collect($rows)->pluck('group')->all())->toBe(['draft', 'draft', 'live'])
            ->and(collect($rows)->pluck('cells.name.state')->all())->toBe(['Lamp', 'Zz', 'Aa']);
    });

    it('keeps a related value\'s rows together', function () {
        $lamps = Category::create(['name' => 'Lamps']);
        $chairs = Category::create(['name' => 'Chairs']);
        $this->product->update(['category_id' => $lamps->id]);
        Product::create(['name' => 'Stool', 'status' => 'draft', 'category_id' => $chairs->id]);
        Product::create(['name' => 'Bulb', 'status' => 'draft', 'category_id' => $lamps->id]);
        EditableTable::$columns = [TextColumn::make('name'), TextColumn::make('category.name')];
        EditableTable::$group = 'category.name';

        expect(collect(EditableTable::make()->toSchema()['rows'])->pluck('group')->all())->toBe(['Chairs', 'Lamps', 'Lamps']);
    });

    it('needs a column to group by a related value', function () {
        EditableTable::$columns = [TextColumn::make('name')];
        EditableTable::$group = 'category.name';

        EditableTable::make()->toSchema();
    })->throws(TableDefinitionException::class);

    it('labels rows only when grouped', function () {
        EditableTable::$columns = [TextColumn::make('name')];

        expect(EditableTable::make()->toSchema()['rows'][0])->not->toHaveKey('group');
    });
});
