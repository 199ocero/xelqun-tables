<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Xelqun\Forms\Components\TextInput;
use Xelqun\Forms\Form;
use Xelqun\Forms\Security\FormToken;
use Xelqun\Forms\Security\FormTokenEncrypter;
use Xelqun\Tables\Columns\TextColumn;
use Xelqun\Tables\Table;
use Xelqun\Tables\Tests\Fixtures\Product;
use Xelqun\Tables\Tests\Fixtures\ProductsTable;

class ContextTable extends Table
{
    public function query(): Builder
    {
        return Product::query()->where('name', $this->context['name']);
    }

    public function columns(): array
    {
        return [TextColumn::make('name')];
    }
}

class QueryEndpointForm extends Form
{
    public function schema(): array
    {
        return [TextInput::make('name')];
    }
}

function token(string $class, int|string|null $userId = null, ?int $issuedAt = null): string
{
    return app(FormTokenEncrypter::class)->encrypt(new FormToken($class, null, $userId, issuedAt: $issuedAt));
}

function queryWithToken(string $token, array $query = []): TestResponse
{
    return test()->postJson(route('xelqun.tables.query'), ['token' => $token, 'query' => $query]);
}

beforeEach(function () {
    ProductsTable::$allowed = true;
    collect(range(1, 5))->each(fn (int $number) => Product::create(['name' => "Product {$number}"]));
});

it('returns the page it was asked for', function () {
    $response = $this->queryTable(ProductsTable::make(), ['page' => 2])->assertOk();

    expect($response->json('rows.0.cells.name.state'))->toBe('Product 3')
        ->and($response->json('pagination'))->toMatchArray(['page' => 2, 'perPage' => 2, 'total' => 5, 'lastPage' => 3])
        ->and($response->json('query'))->toBe(['page' => 2]);
});

it('honours an allowed page size and leaves defaults out of the query', function () {
    expect($this->queryTable(ProductsTable::make(), ['per_page' => 5])->json('query'))->toBe(['per_page' => 5])
        ->and($this->queryTable(ProductsTable::make(), ['per_page' => 2, 'page' => 1])->json('query'))->toBe([]);
});

it('falls back on a value it can\'t use', function (array $query) {
    $this->queryTable(ProductsTable::make(), $query)
        ->assertOk()
        ->assertJsonPath('pagination.page', 1)
        ->assertJsonPath('pagination.perPage', 2);
})->with([
    'a size nobody offered' => [['per_page' => 100000]],
    'a size as text' => [['per_page' => 'lots']],
    'a size as a list' => [['per_page' => [2]]],
    'page zero' => [['page' => 0]],
    'a negative page' => [['page' => -3]],
    'a page as text' => [['page' => 'first']],
    'a page as a list' => [['page' => [2]]],
    'a fraction' => [['page' => '1.5']],
]);

it('moves back to the last page when the viewer is past it', function () {
    $response = $this->queryTable(ProductsTable::make(), ['page' => 99])->assertOk();

    expect($response->json('pagination.page'))->toBe(3)
        ->and($response->json('rows'))->toHaveCount(1)
        ->and($response->json('query'))->toBe(['page' => 3]);
});

it('shows an empty table on page one', function () {
    Product::query()->delete();

    $this->queryTable(ProductsTable::make(), ['page' => 4])
        ->assertOk()
        ->assertJsonPath('pagination.page', 1)
        ->assertJsonPath('pagination.lastPage', 1)
        ->assertJsonPath('rows', []);
});

it('reads the first render from the url', function () {
    Route::get('/products', fn () => response()->json(ProductsTable::make()->toSchema()));

    $this->getJson('/products?products-table[page]=2&products-table[per_page]=5&products-table[nope]=1')
        ->assertJsonPath('pagination.perPage', 5)
        ->assertJsonPath('query.per_page', 5);

    $this->getJson('/products?products-table[page]=2')->assertJsonPath('pagination.page', 2);
    $this->getJson('/products?other-table[page]=2')->assertJsonPath('pagination.page', 1);
    $this->getJson('/products?products-table=2')->assertJsonPath('pagination.page', 1);
});

it('keeps two tables on a page apart', function () {
    $request = Request::create('/', 'GET', ['first' => ['page' => 2], 'second' => ['page' => 3]]);

    app()->instance('request', $request);

    expect(ProductsTable::make()->id('first')->toSchema()['pagination']['page'])->toBe(2)
        ->and(ProductsTable::make()->id('second')->toSchema()['pagination']['page'])->toBe(3);
});

it('rebuilds the table with its context and id', function () {
    $table = ContextTable::make(['name' => 'Product 4'])->id('mine');

    expect($this->queryTable($table)->json('rows.0.cells.name.state'))->toBe('Product 4');
});

it('never reads the context from the browser', function () {
    $response = $this->queryTable(ContextTable::make(['name' => 'Product 4']), ['context' => ['name' => 'Product 1']]);

    expect($response->json('rows.0.cells.name.state'))->toBe('Product 4');
});

it('refuses a request without a token or query', function (array $body) {
    $this->postJson(route('xelqun.tables.query'), $body)->assertStatus(422);
})->with([
    'no token' => [['query' => []]],
    'no query' => [['token' => 'x']],
    'a query that is not a list' => [['token' => 'x', 'query' => 'page=2']],
]);

it('refuses a token that can\'t be read', function () {
    queryWithToken('not-a-token')->assertForbidden();
});

it('refuses a token of a class that is no table', function (string $class) {
    queryWithToken(token($class))->assertForbidden();
})->with([User::class, Table::class, 'Nope\\Missing']);

it('refuses a forms token here', function () {
    queryWithToken(QueryEndpointForm::make()->makeToken())->assertForbidden();
});

it('refuses a table token on the forms endpoints', function () {
    $this->postJson(route('xelqun.forms.update'), [
        'token' => ProductsTable::make()->makeToken(),
        'seq' => 1,
        'state' => ['data' => []],
        'changed' => [],
        'deps' => (object) [],
    ])->assertForbidden();
});

it('refuses a token issued to someone else', function () {
    $this->actingAs((new User)->forceFill(['id' => 1]));

    queryWithToken(token(ProductsTable::class, 2))->assertForbidden();
});

it('refuses a guest\'s token for a signed-in user', function () {
    $token = ProductsTable::make()->makeToken();

    $this->actingAs((new User)->forceFill(['id' => 1]));

    queryWithToken($token)->assertForbidden();
});

it('tells the browser when a token has expired', function () {
    config(['xelqun-tables.token_ttl' => 1]);

    queryWithToken(token(ProductsTable::class, issuedAt: time() - 120))->assertStatus(419);
});

it('checks authorize on every request', function () {
    $token = ProductsTable::make()->makeToken();

    ProductsTable::$allowed = false;

    queryWithToken($token)->assertForbidden();
});

it('counts each endpoint against its own limit', function () {
    // The action route allows 60 a minute, so a shared counter would refuse it here.
    foreach (range(1, 61) as $ignored) {
        $this->queryTable(ProductsTable::make())->assertOk();
    }

    expect($this->mountTableAction(ProductsTable::make(), 'missing', Product::first()->id)->status())->not->toBe(429);
});
