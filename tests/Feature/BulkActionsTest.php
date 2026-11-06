<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Xelqun\Forms\Components\TextInput;
use Xelqun\Forms\Form;
use Xelqun\Forms\Layouts\Actions;
use Xelqun\Forms\State\Get;
use Xelqun\Forms\State\Set;
use Xelqun\Tables\Actions\BulkAction;
use Xelqun\Tables\Columns\TextColumn;
use Xelqun\Tables\Filters\SelectFilter;
use Xelqun\Tables\Table;
use Xelqun\Tables\Tests\Fixtures\Product;

class BulkTable extends Table
{
    public static array $calls = [];

    public static bool $allowed = true;

    protected function boot(): void
    {
        $this->paginationPageOptions([2])->maxSelectableRecords(3);
    }

    public function query(): Builder
    {
        return Product::query()->where('status', 'live');
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
        return [SelectFilter::make('price')->options(['1' => 'One', '2' => 'Two'])];
    }

    public function bulkActions(): array
    {
        return [
            BulkAction::make('collect')
                ->authorize(true)
                ->action(function (Collection $records) {
                    self::$calls[] = $records->pluck('name')->sort()->values()->all();
                }),
            BulkAction::make('rename')
                ->authorize(true)
                ->schema([
                    TextInput::make('name')->required(),
                    TextInput::make('code')->live()->afterStateUpdated(fn (Get $get, Set $set) => $set('name', 'x'.$get('code'))),
                ])
                ->action(fn (array $data, Collection $records) => $records->each->update(['name' => $data['name']])),
            BulkAction::make('remove')->authorize('delete')->action(fn (Collection $records) => $records->each->delete()),
            BulkAction::make('by_closure')
                ->authorize(fn (Collection $records): bool => $records->every(fn (Product $product): bool => $product->price < 10))
                ->action(fn () => self::$calls[] = 'by_closure'),
            BulkAction::make('denied')->action(fn () => self::$calls[] = 'denied'),
        ];
    }
}

function liveRow(string $name, array $attributes = []): Product
{
    return Product::create(['name' => $name, 'status' => 'live', ...$attributes]);
}

function requestKeys(Table $table, array $query = []): TestResponse
{
    return test()->postJson(route('xelqun.tables.query'), ['token' => $table->makeToken(), 'query' => $query, 'keys' => true]);
}

beforeEach(function () {
    BulkTable::$calls = [];
    BulkTable::$allowed = true;
});

it('offers its bulk actions and its limit', function () {
    $schema = json_decode(json_encode(BulkTable::make()->toSchema()), true);

    expect(array_column($schema['bulkActions'], 'name'))->toBe(['collect', 'rename', 'remove', 'by_closure'])
        ->and($schema['maxSelectable'])->toBe(3);
});

it('gives the closure the selected rows', function () {
    $first = liveRow('A');
    liveRow('B');
    $third = liveRow('C');

    $this->runBulkTableAction(BulkTable::make(), 'collect', [$first->id, $third->id])->assertOk();

    expect(BulkTable::$calls)->toBe([['A', 'C']]);
});

it('skips a row outside the table\'s query', function () {
    $inside = liveRow('Inside');
    $outside = Product::create(['name' => 'Outside', 'status' => 'private']);

    $this->runBulkTableAction(BulkTable::make(), 'collect', [$inside->id, $outside->id])->assertOk();

    expect(BulkTable::$calls)->toBe([['Inside']]);
});

it('needs at least one row it can reach', function () {
    $outside = Product::create(['name' => 'Outside', 'status' => 'private']);

    $this->runBulkTableAction(BulkTable::make(), 'collect', [$outside->id])->assertNotFound();
    $this->runBulkTableAction(BulkTable::make(), 'remove', [$outside->id])->assertNotFound();

    expect(BulkTable::$calls)->toBe([])
        ->and(Product::query()->count())->toBe(1);
});

it('ignores keys that can\'t be rows, and repeats', function () {
    $row = liveRow('A');

    $this->runBulkTableAction(BulkTable::make(), 'collect', [$row->id, $row->id, 'abc'])->assertOk();

    expect(BulkTable::$calls)->toBe([['A']]);
});

it('refuses more rows than the limit', function () {
    $keys = collect(range(1, 4))->map(fn (int $number): int => liveRow("Row {$number}")->id)->all();

    $this->runBulkTableAction(BulkTable::make(), 'collect', $keys)->assertStatus(422);
    $this->runBulkTableAction(BulkTable::make(), 'collect', array_slice($keys, 0, 3))->assertOk();
});

it('refuses a bad selection', function (mixed $records) {
    $this->postJson(route('xelqun.tables.action'), [
        'token' => BulkTable::make()->makeToken(), 'action' => 'collect', 'kind' => 'bulk', 'phase' => 'run', 'records' => $records,
    ])->assertStatus(422);
})->with([
    'none' => [null],
    'not a list' => ['1,2'],
]);

it('skips a key that is a list', function () {
    $this->postJson(route('xelqun.tables.action'), [
        'token' => BulkTable::make()->makeToken(), 'action' => 'collect', 'kind' => 'bulk', 'phase' => 'run', 'records' => [[liveRow('A')->id]],
    ])->assertNotFound();

    expect(BulkTable::$calls)->toBe([]);
});

it('runs a policy on every record, and refuses them all when one fails', function () {
    $yes = liveRow('Yes');
    $no = liveRow('No');

    Gate::define('delete', fn (?User $user, Product $product): bool => $product->name === 'Yes');

    $this->runBulkTableAction(BulkTable::make(), 'remove', [$yes->id, $no->id])->assertForbidden();

    expect(Product::query()->count())->toBe(2);

    $this->runBulkTableAction(BulkTable::make(), 'remove', [$yes->id])->assertOk();

    expect(Product::query()->pluck('name')->all())->toBe(['No']);
});

it('shows a policy action before anything is selected', function () {
    $names = array_column(json_decode(json_encode(BulkTable::make()->toSchema()), true)['bulkActions'], 'name');

    expect($names)->toContain('remove');
});

it('passes the rows to an authorize closure', function () {
    $cheap = liveRow('Cheap', ['price' => 1]);
    $dear = liveRow('Dear', ['price' => 50]);

    $this->runBulkTableAction(BulkTable::make(), 'by_closure', [$cheap->id, $dear->id])->assertForbidden();
    $this->runBulkTableAction(BulkTable::make(), 'by_closure', [$cheap->id])->assertOk();
});

it('denies an action with no authorize', function () {
    $this->runBulkTableAction(BulkTable::make(), 'denied', [liveRow('A')->id])->assertForbidden();

    expect(BulkTable::$calls)->toBe([]);
});

it('refuses a bulk action sent as another kind', function () {
    $row = liveRow('A');

    $this->runTableAction(BulkTable::make(), 'collect', $row->id)->assertNotFound();
    $this->runTableAction(BulkTable::make(), 'collect')->assertNotFound();
});

it('stops when the viewer loses the table', function () {
    $table = BulkTable::make();
    $token = $table->makeToken();

    BulkTable::$allowed = false;

    $this->postJson(route('xelqun.tables.action'), [
        'token' => $token, 'action' => 'collect', 'kind' => 'bulk', 'phase' => 'run', 'records' => [(string) liveRow('A')->id],
    ])->assertForbidden();
});

function mountBulk(array $records): TestResponse
{
    return test()->postJson(route('xelqun.tables.action'), [
        'token' => BulkTable::make()->makeToken(),
        'action' => 'rename',
        'kind' => 'bulk',
        'phase' => 'mount',
        'records' => array_map(strval(...), $records),
    ]);
}

it('runs a modal for the selection, and checks it on the server', function () {
    $first = liveRow('A');
    $second = liveRow('B');

    mountBulk([$first->id])->assertOk()->assertJsonPath('form.id', 'action-rename');

    $this->runBulkTableAction(BulkTable::make(), 'rename', [$first->id, $second->id], ['name' => ''])->assertStatus(422);
    $this->runBulkTableAction(BulkTable::make(), 'rename', [$first->id, $second->id], ['name' => 'Same'])->assertOk();

    expect(Product::query()->pluck('name')->unique()->all())->toBe(['Same']);
});

it('keeps a modal\'s selection on its own requests', function () {
    $token = mountBulk([liveRow('A')->id])->json('form.token');

    $this->postJson(route('xelqun.forms.update'), [
        'token' => $token,
        'seq' => 1,
        'state' => ['data' => ['name' => null, 'code' => 'b']],
        'changed' => ['data.code'],
        'deps' => (object) [],
    ])->assertOk()->assertJsonPath('set', ['data.name' => 'xb']);
});

it('re-checks a policy on a modal\'s own requests', function () {
    $table = new class extends BulkTable
    {
        public function bulkActions(): array
        {
            return [BulkAction::make('rename')->authorize('edit')->schema([TextInput::make('code')->live()])->action(fn () => null)];
        }
    };

    Gate::define('edit', fn (?User $user, Product $product): bool => $product->name === 'Allowed');

    $row = liveRow('Allowed');

    $token = $this->postJson(route('xelqun.tables.action'), [
        'token' => $table->makeToken(), 'action' => 'rename', 'kind' => 'bulk', 'phase' => 'mount', 'records' => [(string) $row->id],
    ])->assertOk()->json('form.token');

    $row->update(['name' => 'Changed']);

    $this->postJson(route('xelqun.forms.update'), [
        'token' => $token, 'seq' => 1, 'state' => ['data' => ['code' => 'b']], 'changed' => ['data.code'], 'deps' => (object) [],
    ])->assertForbidden();
});

it('lists every row of the search and filters for select all', function () {
    $wanted = liveRow('Wanted', ['price' => 1]);
    liveRow('Other', ['price' => 2]);
    Product::create(['name' => 'Wanted too', 'status' => 'private', 'price' => 1]);

    $response = requestKeys(BulkTable::make(), ['search' => 'want', 'filters' => ['price' => '1']])->assertOk();

    expect($response->json('keys'))->toBe([(string) $wanted->id]);
});

it('lists rows past the current page', function () {
    collect(range(1, 3))->each(fn (int $number) => liveRow("Row {$number}"));

    expect(requestKeys(BulkTable::make())->json('keys'))->toHaveCount(3);
});

it('refuses to list more than the limit', function () {
    collect(range(1, 4))->each(fn (int $number) => liveRow("Row {$number}"));

    requestKeys(BulkTable::make())->assertStatus(422);
});

it('says plainly that it needs a table', function () {
    Form::make([Actions::make([BulkAction::make('a')->authorize(true)])])->toSchema();
})->throws(LogicException::class);
