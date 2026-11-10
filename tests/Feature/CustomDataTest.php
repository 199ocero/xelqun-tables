<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Xelqun\Actions\Action;
use Xelqun\Tables\Actions\BulkAction;
use Xelqun\Tables\ArrayRecord;
use Xelqun\Tables\Columns\TextColumn;
use Xelqun\Tables\Exceptions\TableDefinitionException;
use Xelqun\Tables\Filters\Constraints\TextConstraint;
use Xelqun\Tables\Filters\QueryBuilder;
use Xelqun\Tables\Filters\SelectFilter;
use Xelqun\Tables\Summarizers\Sum;
use Xelqun\Tables\Table;

class BooksTable extends Table
{
    public static array $calls = [];

    /** @var array<string, array<string, mixed>> */
    public static array $books = [
        'b1' => ['title' => 'Dune', 'genre' => 'scifi', 'pages' => 412],
        'b2' => ['title' => 'Emma', 'genre' => 'classic', 'pages' => 474],
        'b3' => ['title' => 'Ubik', 'genre' => 'scifi', 'pages' => 202],
    ];

    protected function boot(): void
    {
        $this->paginationPageOptions([2])->records(function (?string $search, array $filters, ?string $sortColumn, ?string $sortDirection, int $page, ?int $recordsPerPage): array {
            self::$calls[] = compact('search', 'filters', 'sortColumn', 'sortDirection', 'page', 'recordsPerPage');

            return collect(self::$books)
                ->filter(fn (array $book): bool => $search === null || str_contains(strtolower($book['title']), strtolower($search)))
                ->filter(fn (array $book): bool => ! isset($filters['genre']['genre']) || $book['genre'] === $filters['genre']['genre'])
                ->when($sortColumn !== null, fn (Collection $books) => $books->sortBy($sortColumn, descending: $sortDirection === 'desc'))
                ->all();
        });
    }

    public function authorize(?Authenticatable $user): bool
    {
        return true;
    }

    public function columns(): array
    {
        return [TextColumn::make('title')->searchable()->sortable(), TextColumn::make('pages')];
    }

    public function filters(): array
    {
        return [SelectFilter::make('genre')->options(['scifi' => 'Sci-fi', 'classic' => 'Classic'])];
    }

    public function recordActions(): array
    {
        return [
            Action::make('read')->authorize(true)->action(fn (ArrayRecord $record) => self::$calls[] = ['read' => $record['title'], 'array' => $record->toArray()]),
            Action::make('save')->authorize(true)->action(fn (ArrayRecord $record) => $record->save()),
            Action::make('policy')->authorize('update')->action(fn () => self::$calls[] = 'policy'),
        ];
    }

    public function bulkActions(): array
    {
        return [BulkAction::make('collect')->authorize(true)->action(fn (Collection $records) => self::$calls[] = $records->map->getKey()->all())];
    }
}

beforeEach(fn () => BooksTable::$calls = []);

it('shows custom rows, paginated in memory, keyed as records() keyed them', function () {
    $response = $this->queryTable(BooksTable::make(), ['page' => 2])->assertOk();

    expect($response->json('rows.0.key'))->toBe('b3')
        ->and($response->json('rows.0.cells.title.state'))->toBe('Ubik')
        ->and($response->json('pagination.total'))->toBe(3)
        ->and($response->json('pagination.lastPage'))->toBe(2);
});

it('gives records() the search, sort, active filters and page', function () {
    $response = $this->queryTable(BooksTable::make(), ['search' => 'u', 'sort' => '-title', 'filters' => ['genre' => 'scifi']])->assertOk();

    expect(array_column($response->json('rows'), 'key'))->toBe(['b3', 'b1'])
        ->and(BooksTable::$calls[0])->toBe([
            'search' => 'u',
            'filters' => ['genre' => ['genre' => 'scifi']],
            'sortColumn' => 'title',
            'sortDirection' => 'desc',
            'page' => 1,
            'recordsPerPage' => 2,
        ]);
});

it('uses a paginator from records() as given', function () {
    $table = new class extends BooksTable
    {
        protected function boot(): void
        {
            $this->records(fn (int $page, ?int $recordsPerPage) => new LengthAwarePaginator(['x9' => ['title' => 'Kindred', 'pages' => 1]], 41, $recordsPerPage, $page));
        }
    };

    $response = $this->queryTable($table, ['page' => 3])->assertOk();

    expect($response->json('rows.0.key'))->toBe('x9')
        ->and($response->json('pagination.total'))->toBe(41)
        ->and($response->json('pagination.page'))->toBe(3);
});

it('pages with a simple paginator from records()', function () {
    $table = new class extends BooksTable
    {
        protected function boot(): void
        {
            $this->records(fn (int $page) => new Paginator(['x1' => ['title' => 'Kindred', 'pages' => 1], 'x2' => ['title' => 'Next', 'pages' => 1]], 1, $page));
        }
    };

    $response = $this->queryTable($table, ['page' => 2])->assertOk();

    expect($response->json('pagination'))->toMatchArray(['mode' => 'simple', 'page' => 2, 'hasMore' => true])
        ->and($response->json('rows.0.key'))->toBe('x1');
});

it('finds a row for an action whatever a filter\'s default would show', function () {
    $table = new class extends BooksTable
    {
        public function filters(): array
        {
            return [SelectFilter::make('genre')->options(['scifi' => 'Sci-fi', 'classic' => 'Classic'])->default('scifi')];
        }
    };

    $this->runTableAction($table, 'read', 'b2')->assertOk();

    expect(end(BooksTable::$calls))->toMatchArray(['read' => 'Emma']);
});

it('says to use $records when a bulk action on custom data asks for $query', function () {
    $table = new class extends BooksTable
    {
        public function bulkActions(): array
        {
            return [BulkAction::make('chunked')->authorize(true)->action(fn (Builder $query) => $query->count())];
        }
    };

    $this->withoutExceptionHandling();
    $this->runBulkTableAction($table, 'chunked', ['b1']);
})->throws(TableDefinitionException::class, 'Use $records instead of $query');

it('runs a record action on the row as an array, and never saves it', function () {
    $this->runTableAction(BooksTable::make(), 'read', 'b2')->assertOk();

    expect(BooksTable::$calls[1])->toBe(['read' => 'Emma', 'array' => BooksTable::$books['b2']]);

    $this->withoutExceptionHandling();
    $this->runTableAction(BooksTable::make(), 'save', 'b2');
})->throws(LogicException::class, "can't be saved");

it('refuses a key that records() never gave', function () {
    $this->runTableAction(BooksTable::make(), 'read', 'nope')->assertNotFound();
});

it('denies a policy ability, because no policy exists for custom rows', function () {
    $this->runTableAction(BooksTable::make(), 'policy', 'b1')->assertForbidden();

    expect(BooksTable::$calls)->not->toContain('policy');
});

it('runs a bulk action on the selected rows, and only on keys the resolver was asked for', function () {
    $table = new class extends BooksTable
    {
        protected function boot(): void
        {
            parent::boot();
            // A careless resolver that returns more than it was asked for.
            $this->resolveSelectedRecordsUsing(fn (array $keys): array => BooksTable::$books);
        }
    };

    $this->runBulkTableAction($table, 'collect', ['b1', 'b3', 'nope'])->assertOk();

    expect(end(BooksTable::$calls))->toBe(['b1', 'b3']);
});

it('selects all matching rows', function () {
    $this->postJson(route('xelqun.tables.query'), ['token' => BooksTable::make()->makeToken(), 'query' => ['filters' => ['genre' => 'scifi']], 'keys' => true])
        ->assertOk()
        ->assertJsonPath('keys', ['b1', 'b3']);
});

it('refuses what needs a database query', function (Closure $configure) {
    $table = new class extends BooksTable
    {
        public static ?Closure $configure = null;

        public function columns(): array
        {
            return (self::$configure)('columns') ?? parent::columns();
        }

        public function filters(): array
        {
            return (self::$configure)('filters') ?? parent::filters();
        }
    };
    $table::$configure = $configure;

    $table->getColumns();
})->with([
    'a summary' => fn (string $part) => $part === 'columns' ? [TextColumn::make('pages')->summarize(Sum::make())] : null,
    'the query builder' => fn (string $part) => $part === 'filters' ? [QueryBuilder::make('rules')->constraints([TextConstraint::make('title')])] : null,
    'a relationship filter' => fn (string $part) => $part === 'filters' ? [SelectFilter::make('author')->relationship('author', 'name')] : null,
])->throws(TableDefinitionException::class, 'need a database query');
