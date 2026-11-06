<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Xelqun\Actions\Action;
use Xelqun\Forms\Components\FileUpload;
use Xelqun\Forms\Components\Select;
use Xelqun\Forms\Components\TextInput;
use Xelqun\Forms\State\Get;
use Xelqun\Forms\State\Set;
use Xelqun\Tables\Actions\TableActionHost;
use Xelqun\Tables\Columns\TextColumn;
use Xelqun\Tables\Exceptions\TableDefinitionException;
use Xelqun\Tables\Table;
use Xelqun\Tables\Tests\Fixtures\Product;
use Xelqun\Tables\Tests\Fixtures\TrashableProduct;

class ActionsTable extends Table
{
    public static array $calls = [];

    public static bool $allowed = true;

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
        return [TextColumn::make('name')];
    }

    public function recordActions(): array
    {
        return [
            Action::make('touch')
                ->authorize(true)
                ->action(function (Product $record) {
                    self::$calls[] = $record->id;
                }),
            Action::make('rename')
                ->authorize(true)
                ->schema([
                    TextInput::make('name')->required(),
                    TextInput::make('code')->live()->afterStateUpdated(fn (Get $get, Set $set) => $set('name', 'x'.$get('code'))),
                    Select::make('tag')->searchable()->getSearchResultsUsing(fn () => ['a' => 'A']),
                    FileUpload::make('photo')->disk('local')->image(),
                ])
                ->action(fn (array $data, Product $record) => $record->update(['name' => $data['name']])),
            Action::make('explode')
                ->authorize(true)
                ->action(function (Product $record) {
                    $record->update(['name' => 'changed']);

                    throw ValidationException::withMessages(['data.name' => 'Not today.']);
                }),
            Action::make('answer')->authorize(true)->sendsResult()->action(fn (Product $record): string => "row {$record->id}"),
            Action::make('denied')->action(fn () => self::$calls[] = 'denied'),
            Action::make('disabled')->authorize(true)->disabled()->action(fn () => self::$calls[] = 'disabled'),
            Action::make('only_cheap')
                ->authorize(true)
                ->hidden(fn (Product $record): bool => $record->price > 10)
                ->action(fn () => self::$calls[] = 'only_cheap'),
            Action::make('policy')->authorize('update')->action(fn () => self::$calls[] = 'policy'),
            Action::make('link')->authorize(true)->url(fn (Product $record): string => "/products/{$record->id}"),
        ];
    }

    public function headerActions(): array
    {
        return [
            Action::make('create')
                ->authorize(true)
                ->schema([TextInput::make('name')->required()])
                ->action(fn (array $data) => Product::create(['name' => $data['name'], 'status' => 'live'])),
            Action::make('with_policy')->authorize('create')->action(fn () => self::$calls[] = 'with_policy'),
            Action::make('no_record')->authorize(true)->action(function (mixed $record) {
                self::$calls[] = $record;
            }),
        ];
    }
}

class EitherActionsTable extends ActionsTable
{
    public function query(): Builder
    {
        return Product::query()->where('name', 'Alpha')->orWhere('name', 'Beta');
    }
}

class TrashedActionsTable extends ActionsTable
{
    public function query(): Builder
    {
        return TrashableProduct::query()->withTrashed();
    }

    public function recordActions(): array
    {
        return [
            Action::make('restore')
                ->authorize(true)
                ->schema([TextInput::make('reason')->live()])
                ->action(fn (TrashableProduct $record) => $record->restore()),
        ];
    }
}

class OtherUploadTable extends ActionsTable
{
    public function recordActions(): array
    {
        // The same position and a laxer field than ActionsTable, so only the scope stops an upload crossing over.
        return [
            Action::make('touch')->authorize(true),
            Action::make('rename')->authorize(true)->schema([
                TextInput::make('name'),
                TextInput::make('code'),
                Select::make('tag'),
                FileUpload::make('photo')->disk('local'),
            ])->action(fn () => null),
        ];
    }
}

class SameNameActionsTable extends ActionsTable
{
    public function recordActions(): array
    {
        return [Action::make('a')->authorize(true), Action::make('a')->authorize(true)];
    }
}

function live(array $attributes = []): Product
{
    return Product::create(['name' => 'Keyboard', 'status' => 'live', ...$attributes]);
}

function hidden(array $attributes = []): Product
{
    return Product::create(['name' => 'Hidden', 'status' => 'private', ...$attributes]);
}

/** The token a real modal would use for its update, upload and search requests. */
function modalToken(Table $table, Product $record, string $action = 'rename'): string
{
    return test()->mountTableAction($table, $action, $record->id)->assertOk()->json('form.token');
}

function updateModal(string $token, array $data, array $changed = []): TestResponse
{
    return test()->postJson(route('xelqun.forms.update'), [
        'token' => $token,
        'seq' => 1,
        'state' => ['data' => $data],
        'changed' => $changed,
        'deps' => (object) [],
    ]);
}

beforeEach(function () {
    ActionsTable::$calls = [];
    ActionsTable::$allowed = true;
    Storage::fake('local');
});

it('sends each row the actions the viewer can see, and only what a button needs', function () {
    live(['price' => 5]);
    live(['price' => 50]);

    $rows = json_decode(json_encode(ActionsTable::make()->toSchema()), true)['rows'];
    $names = fn (array $row): array => array_column($row['actions'], 'name');

    expect($names($rows[0]))->toBe(['touch', 'rename', 'explode', 'answer', 'disabled', 'only_cheap', 'link'])
        ->and($names($rows[1]))->not->toContain('only_cheap')
        ->and(array_keys($rows[0]['actions'][0]))->toBe(['name', 'props'])
        ->and($rows[0]['actions'][6]['props']['url'])->toBe('/products/'.Product::query()->first()->id);
});

it('sends the header actions the viewer can see', function () {
    $schema = json_decode(json_encode(ActionsTable::make()->toSchema()), true);

    expect(array_column($schema['headerActions'], 'name'))->toBe(['create', 'no_record']);
});

it('runs an action on the row it was asked for', function () {
    live();
    $record = live();

    $this->runTableAction(ActionsTable::make(), 'touch', $record->id)->assertOk();

    expect(ActionsTable::$calls)->toBe([$record->id]);
});

it('opens a modal filled from the row', function () {
    $record = live(['name' => 'Original']);

    $form = $this->mountTableAction(ActionsTable::make(), 'rename', $record->id)->assertOk()->json('form');

    expect($form['state']['data']['name'])->toBe('Original')
        ->and($form['id'])->toBe('action-rename');
});

it('checks the modal on the server and saves', function () {
    $record = live();

    $this->runTableAction(ActionsTable::make(), 'rename', $record->id, ['name' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors('data.name');

    $this->runTableAction(ActionsTable::make(), 'rename', $record->id, ['name' => 'New'])->assertOk();

    expect($record->refresh()->name)->toBe('New');
});

it('rolls an action back when it fails', function () {
    $record = live(['name' => 'Original']);

    $this->runTableAction(ActionsTable::make(), 'explode', $record->id)->assertStatus(422);

    expect($record->refresh()->name)->toBe('Original');
});

it('sends a result only when the action says so', function () {
    $record = live();

    $this->runTableAction(ActionsTable::make(), 'answer', $record->id)->assertJsonPath('result', "row {$record->id}");
    $this->runTableAction(ActionsTable::make(), 'touch', $record->id)->assertJsonPath('result', null);
});

it('sends the user away when the action says so, and ignores an unsafe address', function () {
    $table = new class extends ActionsTable
    {
        public function headerActions(): array
        {
            return [
                Action::make('safe')->authorize(true)->successRedirectUrl('/products')->action(fn () => null),
                Action::make('unsafe')->authorize(true)->successRedirectUrl('javascript:alert(1)')->action(fn () => null),
            ];
        }
    };
    $call = fn (string $name) => $this->postJson(route('xelqun.tables.action'), [
        'token' => $table->toSchema()['token'],
        'kind' => 'header',
        'action' => $name,
        'phase' => 'run',
    ]);

    $call('safe')->assertOk()->assertJsonPath('redirect', '/products');
    $call('unsafe')->assertOk()->assertJsonPath('redirect', null);
});

it('creates from a header action, with no record', function () {
    $this->runTableAction(ActionsTable::make(), 'create', data: ['name' => 'Made'])->assertOk();

    expect(Product::query()->where('name', 'Made')->exists())->toBeTrue();

    $this->runTableAction(ActionsTable::make(), 'no_record')->assertOk();

    expect(ActionsTable::$calls)->toBe([null]);
});

it('never reaches a row outside the table\'s query', function (string $phase) {
    $outside = hidden();

    $this->postJson(route('xelqun.tables.action'), [
        'token' => ActionsTable::make()->makeToken(),
        'action' => 'touch',
        'kind' => 'record',
        'record' => (string) $outside->id,
        'phase' => $phase,
    ])->assertNotFound();

    expect(ActionsTable::$calls)->toBe([]);
})->with(['mount', 'run']);

it('stays inside a query that uses orWhere', function () {
    $alpha = Product::create(['name' => 'Alpha']);
    $outside = Product::create(['name' => 'Outside']);

    $this->runTableAction(EitherActionsTable::make(), 'touch', $outside->id)->assertNotFound();
    $this->runTableAction(EitherActionsTable::make(), 'touch', $alpha->id)->assertOk();

    expect(ActionsTable::$calls)->toBe([$alpha->id]);
});

it('stops when the row leaves the table after the page loaded', function () {
    $record = live();
    $table = ActionsTable::make();

    $this->mountTableAction($table, 'rename', $record->id)->assertOk();
    $record->update(['status' => 'private']);

    $this->runTableAction($table, 'rename', $record->id, ['name' => 'New'])->assertNotFound();

    expect($record->refresh()->name)->toBe('Keyboard');
});

it('stops a modal\'s own requests when the row leaves the table', function () {
    $record = live();
    $token = modalToken(ActionsTable::make(), $record);

    updateModal($token, ['name' => 'a', 'code' => 'b'], ['data.code'])->assertOk();

    $record->update(['status' => 'private']);

    updateModal($token, ['name' => 'a', 'code' => 'b'], ['data.code'])->assertNotFound();
});

it('runs a modal\'s live fields and search through the forms endpoints', function () {
    $token = modalToken(ActionsTable::make(), live());

    updateModal($token, ['name' => null, 'code' => 'b'], ['data.code'])
        ->assertOk()
        ->assertJsonPath('set', ['data.name' => 'xb']);

    $this->postJson(route('xelqun.forms.search'), [
        'token' => $token,
        'path' => 'data.tag',
        'search' => 'a',
        'state' => ['data' => ['name' => null]],
    ])->assertOk()->assertJsonPath('options.0.value', 'a');
});

it('uploads from a modal, and the upload stays with that modal', function () {
    $record = live();
    $upload = function () use ($record): string {
        return $this->post(route('xelqun.forms.upload'), [
            'token' => modalToken(ActionsTable::make(), $record),
            'path' => 'data.photo',
            'file' => UploadedFile::fake()->image('a.png'),
            'state' => json_encode(['data' => []]),
        ], ['Accept' => 'application/json'])->assertOk()->json('token');
    };

    // Uploads belong to a session, and a test client gets a new one for each request.
    $this->withCredentials()->withCookie(config('session.cookie'), str_repeat('a', 40));

    $this->runTableAction(OtherUploadTable::make(), 'rename', $record->id, ['photo' => $upload()])
        ->assertStatus(422)
        ->assertJsonValidationErrors('data.photo');

    $this->runTableAction(ActionsTable::make(), 'rename', $record->id, ['name' => 'With photo', 'photo' => $upload()])->assertOk();

    expect($record->refresh()->name)->toBe('With photo');
});

it('keeps an upload to its table when one class serves several', function () {
    $record = live();
    $this->withCredentials()->withCookie(config('session.cookie'), str_repeat('a', 40));

    $upload = $this->post(route('xelqun.forms.upload'), [
        'token' => modalToken(ActionsTable::make()->id('lax'), $record),
        'path' => 'data.photo',
        'file' => UploadedFile::fake()->image('a.png'),
        'state' => json_encode(['data' => []]),
    ], ['Accept' => 'application/json'])->assertOk()->json('token');

    $this->runTableAction(ActionsTable::make()->id('strict'), 'rename', $record->id, ['photo' => $upload])
        ->assertStatus(422)
        ->assertJsonValidationErrors('data.photo');
});

it('keeps a table that shows trashed rows working in its modals', function () {
    $record = TrashableProduct::create(['name' => 'Gone']);
    $record->delete();

    $table = TrashedActionsTable::make();
    $token = test()->mountTableAction($table, 'restore', $record->id)->assertOk()->json('form.token');

    updateModal($token, ['reason' => 'x'], ['data.reason'])->assertOk();

    $this->runTableAction($table, 'restore', $record->id, ['reason' => 'x'])->assertOk();

    expect($record->refresh()->trashed())->toBeFalse();
});

it('refuses an action of the wrong kind', function () {
    $record = live();
    $table = ActionsTable::make();

    $this->postJson(route('xelqun.tables.action'), [
        'token' => $table->makeToken(), 'action' => 'touch', 'kind' => 'header', 'phase' => 'run',
    ])->assertNotFound();

    $this->postJson(route('xelqun.tables.action'), [
        'token' => $table->makeToken(), 'action' => 'create', 'kind' => 'record', 'record' => (string) $record->id, 'phase' => 'run', 'data' => ['name' => 'x'],
    ])->assertNotFound();

    expect(ActionsTable::$calls)->toBe([])
        ->and(Product::query()->where('name', 'x')->exists())->toBeFalse();
});

it('refuses a bad request', function (array $body) {
    $record = live();

    $this->postJson(route('xelqun.tables.action'), [
        'token' => ActionsTable::make()->makeToken(),
        'action' => 'touch',
        'kind' => 'record',
        'record' => (string) $record->id,
        'phase' => 'run',
        ...$body,
    ])->assertStatus(422);
})->with([
    'no record' => [['record' => null]],
    'a list of records' => [['record' => [1, 2]]],
    'an unknown kind' => [['kind' => 'bulk-delete']],
    'an unknown phase' => [['phase' => 'destroy']],
    'no action' => [['action' => null]],
]);

it('answers 404 for a key that can\'t be a record', function (string $key) {
    live();

    $this->postJson(route('xelqun.tables.action'), [
        'token' => ActionsTable::make()->makeToken(),
        'action' => 'touch',
        'kind' => 'record',
        'record' => $key,
        'phase' => 'run',
    ])->assertNotFound();
})->with(['abc', '1 or 1=1', '1.5', '-1']);

it('refuses an action the table does not declare', function () {
    $this->runTableAction(ActionsTable::make(), 'nope', live()->id)->assertNotFound();
});

it('finds an action by name, never by position', function () {
    $this->runTableAction(ActionsTable::make(), 'data#0', live()->id)->assertNotFound();

    expect(ActionsTable::$calls)->toBe([]);
});

it('refuses an action the viewer may not run', function (string $action) {
    $this->runTableAction(ActionsTable::make(), $action, live(['price' => 50])->id)->assertForbidden();

    expect(ActionsTable::$calls)->toBe([]);
})->with(['denied', 'disabled', 'only_cheap', 'policy']);

it('checks a policy against the row', function () {
    $allowed = live(['name' => 'Yes']);
    $denied = live(['name' => 'No']);

    Gate::define('update', fn (?User $user, Product $product): bool => $product->name === 'Yes');

    $this->runTableAction(ActionsTable::make(), 'policy', $allowed->id)->assertOk();
    $this->runTableAction(ActionsTable::make(), 'policy', $denied->id)->assertForbidden();
});

it('checks a policy against the table\'s model when there is no row', function () {
    Gate::define('create', fn (?User $user, string $class): bool => $class === Product::class);

    $this->runTableAction(ActionsTable::make(), 'with_policy')->assertOk();

    expect(ActionsTable::$calls)->toBe(['with_policy']);
});

it('stops when the viewer loses the table', function () {
    $record = live();
    $table = ActionsTable::make();
    $token = modalToken($table, $record);
    $tableToken = $table->makeToken();

    ActionsTable::$allowed = false;

    $this->postJson(route('xelqun.tables.action'), [
        'token' => $tableToken, 'action' => 'touch', 'kind' => 'record', 'record' => (string) $record->id, 'phase' => 'run',
    ])->assertForbidden();

    updateModal($token, [])->assertForbidden();
});

it('refuses a token of another user', function () {
    $token = ActionsTable::make()->makeToken();

    $this->actingAs((new User)->forceFill(['id' => 1]));

    $this->postJson(route('xelqun.tables.action'), [
        'token' => $token, 'action' => 'create', 'kind' => 'header', 'phase' => 'run', 'data' => ['name' => 'x'],
    ])->assertForbidden();
});

it('keeps a table token off the forms action endpoint', function () {
    $this->postJson(route('xelqun.forms.action'), [
        'token' => ActionsTable::make()->makeToken(),
        'action' => 'data#0',
        'phase' => 'run',
        'state' => ['data' => []],
        'deps' => (object) [],
    ])->assertForbidden();
});

it('keeps a host token off the table endpoints', function () {
    $hostToken = TableActionHost::for(ActionsTable::make(), TableActionHost::HEADER)->makeToken();

    $this->postJson(route('xelqun.tables.action'), [
        'token' => $hostToken, 'action' => 'create', 'kind' => 'header', 'phase' => 'run', 'data' => ['name' => 'x'],
    ])->assertForbidden();

    $this->postJson(route('xelqun.tables.query'), ['token' => $hostToken, 'query' => []])->assertForbidden();
});

it('refuses two actions with one name', function () {
    live();

    $this->withoutExceptionHandling();
    SameNameActionsTable::make()->toSchema();
})->throws(TableDefinitionException::class);

it('keeps the cost of rows flat', function () {
    collect(range(1, 100))->each(fn (int $number) => live(['name' => "Row {$number}"]));

    $table = ActionsTable::make()->paginationPageOptions([100]);

    DB::enableQueryLog();
    $response = $this->queryTable($table)->assertOk();

    expect(DB::getQueryLog())->toHaveCount(2)
        ->and(strlen($response->getContent()))->toBeLessThan(220_000);
});
