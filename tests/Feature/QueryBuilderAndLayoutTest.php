<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Testing\TestResponse;
use Xelqun\Tables\Columns\Column;
use Xelqun\Tables\Columns\SelectColumn;
use Xelqun\Tables\Columns\TextColumn;
use Xelqun\Tables\Filters\Constraints\BooleanConstraint;
use Xelqun\Tables\Filters\Constraints\DateConstraint;
use Xelqun\Tables\Filters\Constraints\NumberConstraint;
use Xelqun\Tables\Filters\Constraints\SelectConstraint;
use Xelqun\Tables\Filters\Constraints\TextConstraint;
use Xelqun\Tables\Filters\QueryBuilder;
use Xelqun\Tables\State\TableState;
use Xelqun\Tables\Table;
use Xelqun\Tables\Tests\Fixtures\Category;
use Xelqun\Tables\Tests\Fixtures\Product;

enum StockStatus: string
{
    case Low = 'low';
    case Plain = 'plain';

    public function getLabel(): string
    {
        return "Status {$this->name}";
    }

    public function getColor(): ?string
    {
        return $this === self::Low ? 'danger' : null;
    }

    public function getIcon(): string
    {
        return 'check';
    }
}

class CastProduct extends Product
{
    protected $table = 'products';

    protected function casts(): array
    {
        return ['status' => StockStatus::class];
    }
}

class BuilderTable extends Table
{
    public static bool $canReorder = true;

    /** @var array<int, Column>|null */
    public static ?array $columns = null;

    public function query(): Builder
    {
        return Product::query()->where('status', '!=', 'hidden');
    }

    public function columns(): array
    {
        return self::$columns ?? [TextColumn::make('name')];
    }

    public function filters(): array
    {
        return [QueryBuilder::make('rules')->constraints([
            TextConstraint::make('name'),
            NumberConstraint::make('price'),
            DateConstraint::make('created_at'),
            BooleanConstraint::make('is_featured'),
            SelectConstraint::make('status')->options(['draft' => 'Draft', 'live' => 'Live']),
            TextConstraint::make('category.name')->label('Category'),
        ])];
    }

    protected function boot(): void
    {
        $this->reorderable('price', fn (): bool => self::$canReorder);
    }
}

/**
 * @param  array<int, array<string, mixed>>  $rules
 * @return array<int, string>
 */
function matching(array $rules, bool $matchAny = false): array
{
    $query = ['filters' => ['rules' => $rules, 'rules_match_any' => $matchAny]];
    $table = BuilderTable::make();

    return collect($table->toResult(TableState::from($query, $table))['rows'])->pluck('cells.name.state')->all();
}

function rule(string $constraint, string $operator, mixed $value = null): array
{
    return ['constraint' => $constraint, 'operator' => $operator, 'value' => $value];
}

function move(int $record, string $direction): TestResponse
{
    return test()->postJson(route('xelqun.tables.reorder'), ['token' => BuilderTable::make()->toSchema()['token'], 'record' => (string) $record, 'direction' => $direction]);
}

beforeEach(function () {
    BuilderTable::$canReorder = true;
    BuilderTable::$columns = null;

    $audio = Category::create(['name' => 'Audio']);
    $this->lamp = Product::create(['name' => 'Lamp', 'status' => 'draft', 'price' => 10, 'is_featured' => true]);
    $this->desk = Product::create(['name' => 'Desk', 'status' => 'live', 'price' => 20, 'category_id' => $audio->id]);
    $this->sofa = Product::create(['name' => 'Sofa', 'status' => 'live', 'price' => 30]);
    $this->hidden = Product::create(['name' => 'Hidden', 'status' => 'hidden', 'price' => 40]);
});

describe('the query builder', function () {
    it('answers each kind of rule', function (array $rule, array $names) {
        expect(matching([$rule]))->toEqualCanonicalizing($names);
    })->with([
        'contains' => [rule('name', 'contains', 'es'), ['Desk']],
        'not contains' => [rule('name', 'not_contains', 'es'), ['Lamp', 'Sofa']],
        'starts with' => [rule('name', 'starts_with', 'La'), ['Lamp']],
        'ends with' => [rule('name', 'ends_with', 'fa'), ['Sofa']],
        'more than' => [rule('price', 'greater', '15'), ['Desk', 'Sofa']],
        'at most' => [rule('price', 'less_or_equal', '20'), ['Lamp', 'Desk']],
        'on' => [rule('is_featured', 'is_true'), ['Lamp']],
        'select' => [rule('status', 'equals', 'draft'), ['Lamp']],
        'a relation' => [rule('category.name', 'contains', 'Aud'), ['Desk']],
    ]);

    it('needs every rule, or any of them', function () {
        $rules = [rule('name', 'contains', 'Lamp'), rule('status', 'equals', 'live')];

        expect(matching($rules))->toBe([])
            ->and(matching($rules, matchAny: true))->toEqualCanonicalizing(['Lamp', 'Desk', 'Sofa']);
    });

    it('can\'t step outside the table\'s query, even with match any', function () {
        expect(matching([rule('price', 'greater', '0'), rule('name', 'contains', 'Hidden')], matchAny: true))->not->toContain('Hidden');
    });

    it('ignores a column that isn\'t a constraint', function () {
        expect(matching([rule('secret', 'equals', 'x')]))->toHaveCount(3);
    });

    it('ignores an operator that isn\'t the constraint\'s, or a value it can\'t use', function (array $rule) {
        expect(matching([$rule]))->toHaveCount(3);
    })->with([
        'operator' => [rule('name', 'greater', 'x')],
        'a word for a number' => [rule('price', 'greater', 'lots')],
        'a word for a date' => [rule('created_at', 'after', 'lots')],
        'a choice that isn\'t one' => [rule('status', 'equals', 'hidden')],
    ]);
});

describe('moving rows', function () {
    it('swaps a row with its neighbour', function () {
        move($this->desk->id, 'up')->assertOk()->assertJsonPath('moved', true);

        expect((float) $this->desk->fresh()->price)->toBe(10.0)
            ->and((float) $this->lamp->fresh()->price)->toBe(20.0);
    });

    it('stays put at the end of the list', function () {
        move($this->lamp->id, 'up')->assertOk()->assertJsonPath('moved', false);

        expect((float) $this->lamp->fresh()->price)->toBe(10.0);
    });

    it('is refused until the table allows it', function () {
        $token = BuilderTable::make()->toSchema()['token'];
        BuilderTable::$canReorder = false;

        $this->postJson(route('xelqun.tables.reorder'), ['token' => $token, 'record' => (string) $this->desk->id, 'direction' => 'up'])->assertForbidden();

        expect((float) $this->desk->fresh()->price)->toBe(20.0);
    });

    it('never swaps with a row outside the table\'s query', function () {
        move($this->sofa->id, 'down')->assertOk()->assertJsonPath('moved', false);

        expect((float) $this->hidden->fresh()->price)->toBe(40.0);
    });

    it('can\'t reach a row outside the query', function () {
        move($this->hidden->id, 'up')->assertNotFound();
    });

    it('refuses any other direction', function () {
        move($this->desk->id, 'sideways')->assertUnprocessable();
    });
});

describe('enums in columns', function () {
    function enumCell(string $status): array
    {
        return TextColumn::make('status')->toCell(new CastProduct(['status' => $status]));
    }

    it('takes the label, color and icon from the enum', function () {
        expect(enumCell('low'))->toMatchArray(['state' => 'Status Low', 'color' => 'danger', 'icon' => 'check']);
    });

    it('leaves out what the enum doesn\'t give', function () {
        expect(enumCell('plain'))->not->toHaveKey('color');
    });

    it('lets the column override it', function () {
        $cell = TextColumn::make('status')->color('info')->formatStateUsing(fn (): string => 'Mine')->toCell(new CastProduct(['status' => 'low']));

        expect($cell)->toMatchArray(['state' => 'Mine', 'color' => 'info']);
    });

    it('keeps the value for a select', function () {
        expect(SelectColumn::make('status')->toCell(new CastProduct(['status' => 'low']))['state'])->toBe('low');
    });
});

describe('column toggles', function () {
    it('tells the browser which columns can hide', function () {
        BuilderTable::$columns = [TextColumn::make('name')->toggleable(isHiddenByDefault: true), TextColumn::make('status')];

        $columns = BuilderTable::make()->toSchema()['columns'];

        expect($columns[0])->toMatchArray(['toggleable' => true, 'hiddenByDefault' => true])
            ->and($columns[1])->not->toHaveKey('toggleable');
    });
});
