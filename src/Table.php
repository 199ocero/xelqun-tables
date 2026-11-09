<?php

namespace Xelqun\Tables;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Xelqun\Actions\Action;
use Xelqun\Actions\ActionGroup;
use Xelqun\Forms\Security\FormToken;
use Xelqun\Forms\Security\FormTokenEncrypter;
use Xelqun\Forms\Support\SafeUrl;
use Xelqun\Tables\Actions\BulkAction;
use Xelqun\Tables\Actions\TableActionHost;
use Xelqun\Tables\Columns\Column;
use Xelqun\Tables\Columns\ColumnGroup;
use Xelqun\Tables\Columns\Contracts\Editable;
use Xelqun\Tables\Exceptions\TableDefinitionException;
use Xelqun\Tables\Filters\BaseFilter;
use Xelqun\Tables\Filters\TableFiltersForm;
use Xelqun\Tables\Schema\SchemaVersion;
use Xelqun\Tables\State\TableState;

/**
 * A table of records, defined in PHP and rendered by XqTable.
 *
 * Every request rebuilds a table from its class, so query() can't read the page
 * it first appeared on: route parameters are gone on later requests. Pass what
 * it needs through $context.
 *
 * @api Anything public or protected here, unless it says @internal.
 */
abstract class Table
{
    private ?string $groupColumn = null;

    private ?int $contentGridColumns = null;

    private ?int $pollSeconds = null;

    private bool $isStriped = false;

    private ?string $reorderColumn = null;

    private bool|Closure $reorderAuthorization = false;

    private ?string $tableId = null;

    /** @var array<int, int> */
    private array $paginationPageOptions = [10, 25, 50];

    private ?string $defaultSort = null;

    private int $maxSelectableRecords = 1000;

    private bool $isPaginated = true;

    private string $paginationMode = 'default';

    private bool $isLoadingDeferred = false;

    private bool $hasDeferredFilters = true;

    /** @var array<int, string> The parts of the state the session keeps: search, sort, filters, column_search. */
    private array $persistedInSession = [];

    /** @var array{heading: string, description: string|null}|null */
    private ?array $emptyState = null;

    private ?string $heading = null;

    private ?Closure $isRecordSelectable = null;

    private ?string $description = null;

    /** @var array<int, Column>|null */
    private ?array $mountedColumns = null;

    /** @var array<int, BaseFilter>|null */
    private ?array $mountedFilters = null;

    private ?TableFiltersForm $filtersForm = null;

    /** @var array<string, mixed>|null */
    private ?array $filterDefaults = null;

    /**
     * @param  array<string, mixed>  $context  Scalar values that are needed to rebuild the table.
     */
    final public function __construct(protected array $context = [])
    {
        $this->boot();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function make(array $context = []): static
    {
        return new static($context);
    }

    /**
     * Fluent settings go here, such as paginationPageOptions().
     */
    protected function boot(): void {}

    /**
     * The records this table can show. Every read and every action stays inside it.
     *
     * @return Builder<covariant Model>
     */
    abstract public function query(): Builder;

    /**
     * @return array<int, Column|ColumnGroup>
     */
    abstract public function columns(): array;

    /**
     * @return array<int, BaseFilter>
     */
    public function filters(): array
    {
        return [];
    }

    /**
     * Buttons on each row. An action does nothing until its own authorize()
     * allows it, because being allowed to see the table isn't being allowed to
     * do this. A closure gets the row as $record, so name the parameter that.
     * Put some in an ActionGroup to show them as a menu.
     *
     * @return array<int, Action|ActionGroup>
     */
    public function recordActions(): array
    {
        return [];
    }

    /**
     * Buttons above the table, with no record.
     *
     * @return array<int, Action|ActionGroup>
     */
    public function headerActions(): array
    {
        return [];
    }

    /**
     * Buttons for the selected rows, which reach their closures as $records.
     *
     * @return array<int, BulkAction|ActionGroup>
     */
    public function bulkActions(): array
    {
        return [];
    }

    public function authorize(?Authenticatable $user): bool
    {
        return true;
    }

    /**
     * Find a row, staying inside query(). A global scope, because a where added
     * here would escape an orWhere in query() and reach other rows.
     */
    public function resolveRecord(int|string $key): Model
    {
        $query = $this->query();

        // A word compared with an integer column is an error on some databases.
        abort_if($query->getModel()->getKeyType() === 'int' && ! ctype_digit((string) $key), 404);

        return $query
            ->withGlobalScope('xelqun-record', fn (Builder $scoped): Builder => $scoped->whereKey($key))
            ->firstOrFail();
    }

    /**
     * @internal
     *
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * The filters the viewer may use. A hidden one is left out of everything.
     *
     * @return array<int, BaseFilter>
     */
    public function getFilters(): array
    {
        return $this->mountedFilters ??= array_values(array_filter(
            array_map(fn (BaseFilter $filter): BaseFilter => $filter->mount($this), $this->filters()),
            fn (BaseFilter $filter): bool => ! $filter->isHidden(),
        ));
    }

    /**
     * @internal
     */
    public function getFiltersForm(): TableFiltersForm
    {
        return $this->filtersForm ??= TableFiltersForm::for($this);
    }

    /**
     * What each filter field holds before the viewer changes it.
     *
     * @internal
     *
     * @return array<string, mixed>
     */
    public function getFilterDefaults(): array
    {
        return $this->filterDefaults ??= $this->getFilters() === []
            ? []
            // The same form, so its options load once. This runs before a request's values reach it.
            : $this->getFiltersForm()->getData();
    }

    /**
     * Find rows, staying inside query(). A key that isn't there is skipped,
     * because another viewer may have removed that row.
     *
     * @param  array<int, mixed>  $keys
     * @return Collection<int, Model>
     */
    public function resolveRecords(array $keys): Collection
    {
        // Nothing selected needs no query.
        return $keys === [] ? $this->query()->getModel()->newCollection() : $this->queryRecords($keys)->get();
    }

    /**
     * The same rows as resolveRecords(), as a query, so a big selection can be
     * worked through in chunks rather than loaded at once.
     *
     * @param  array<int, mixed>  $keys
     * @return Builder<covariant Model>
     */
    public function queryRecords(array $keys): Builder
    {
        $query = $this->query();
        $model = $query->getModel();
        $keys = array_values(array_unique(array_filter(
            $keys,
            fn (mixed $key): bool => is_scalar($key) && ($model->getKeyType() !== 'int' || ctype_digit((string) $key)),
        )));

        if ($this->isRecordSelectable !== null) {
            // Checked one at a time, because the rule is PHP and not SQL.
            $keys = $this->query()->whereKey($keys)->cursor()
                ->filter(fn (Model $record): bool => $this->isRecordSelectable($record))
                ->map(fn (Model $record): mixed => $record->getKey())
                ->values()
                ->all();
        }

        return $query->withGlobalScope('xelqun-records', fn (Builder $scoped): Builder => $scoped->whereKey($keys));
    }

    /**
     * The keys of every row in the current search and filters, for "select all".
     * It stops at one past the limit, so the answer can say it was too many.
     *
     * @internal
     *
     * @return array<int, string>|null Null when there are more than the limit.
     */
    public function getSelectableKeys(TableState $state): ?array
    {
        $query = $this->buildQuery($state);
        // shortcut: with a selectable rule, select all checks rows one at a time in PHP until it has enough; give the rule a query scope if tables with many unselectable rows get slow.
        $keys = $this->isRecordSelectable === null
            ? $query->limit($this->maxSelectableRecords + 1)->pluck($query->getModel()->getQualifiedKeyName())
            : $query->cursor()->filter(fn (Model $record): bool => $this->isRecordSelectable($record))->take($this->maxSelectableRecords + 1)->map(fn (Model $record): mixed => $record->getKey())->collect();

        return $keys->count() > $this->maxSelectableRecords ? null : $keys->map(strval(...))->all();
    }

    /**
     * Name this table when a page shows the same class twice.
     */
    public function id(string $id): static
    {
        $this->tableId = $id;

        return $this;
    }

    public function getId(): string
    {
        return $this->tableId ?? Str::kebab(class_basename(static::class));
    }

    /**
     * The page sizes the viewer picks from. 'all' puts every row on one page.
     *
     * @param  array<int, int|string>  $options
     */
    public function paginationPageOptions(array $options): static
    {
        foreach ($options as $option) {
            if ($option !== 'all' && (! is_int($option) || $option < 1)) {
                throw new TableDefinitionException('The table ['.static::class.'] needs at least one page size, and each a number from 1 up, or \'all\'.');
            }
        }

        if ($options === []) {
            throw new TableDefinitionException('The table ['.static::class.'] needs at least one page size, and each a number from 1 up, or \'all\'.');
        }

        $this->paginationPageOptions = array_values($options);

        return $this;
    }

    /**
     * false shows every row with no pager. An array sets the page sizes, like
     * paginationPageOptions().
     *
     * @param  bool|array<int, int|string>  $condition
     */
    public function paginated(bool|array $condition = true): static
    {
        if (is_array($condition)) {
            return $this->paginationPageOptions($condition);
        }

        $this->isPaginated = $condition;

        return $condition ? $this : $this->paginationPageOptions(['all']);
    }

    /**
     * default counts the rows for page numbers. simple skips the count and
     * offers only previous and next, and cursor does too, from where the last
     * page ended, which stays fast deep into a big table.
     */
    public function paginationMode(string $mode): static
    {
        if (! in_array($mode, ['default', 'simple', 'cursor'], true)) {
            throw new TableDefinitionException('The table ['.static::class."] has no pagination mode [{$mode}]. Use default, simple or cursor.");
        }

        $this->paginationMode = $mode;

        return $this;
    }

    /**
     * Render the page without rows, and load them right after. For a slow query
     * on a page that shows more than the table.
     */
    public function deferLoading(bool $condition = true): static
    {
        $this->isLoadingDeferred = $condition;

        return $this;
    }

    /**
     * false applies each filter as it changes, with no Apply button.
     */
    public function deferFilters(bool $condition = true): static
    {
        $this->hasDeferredFilters = $condition;

        return $this;
    }

    public function persistSearchInSession(bool $condition = true): static
    {
        return $this->persistInSession('search', $condition);
    }

    public function persistSortInSession(bool $condition = true): static
    {
        return $this->persistInSession('sort', $condition);
    }

    public function persistFiltersInSession(bool $condition = true): static
    {
        return $this->persistInSession('filters', $condition);
    }

    public function persistColumnSearchesInSession(bool $condition = true): static
    {
        return $this->persistInSession('column_search', $condition);
    }

    private function persistInSession(string $part, bool $condition): static
    {
        $this->persistedInSession = array_values(array_diff($this->persistedInSession, [$part]));

        if ($condition) {
            $this->persistedInSession[] = $part;
        }

        return $this;
    }

    /**
     * Keep the parts of the state this table persists, for the next visit.
     *
     * @internal
     */
    public function rememberState(TableState $state): void
    {
        if ($this->persistedInSession !== []) {
            session()->put($this->getSessionKey(), Arr::only($state->toQuery($this), $this->persistedInSession));
        }
    }

    private function getSessionKey(): string
    {
        return 'xelqun-tables.'.static::class.'.'.$this->getId().'.'.md5((string) json_encode($this->context));
    }

    /**
     * @return array<int, int|string>
     */
    public function getPaginationPageOptions(): array
    {
        return $this->paginationPageOptions;
    }

    /**
     * The order before the viewer picks one. The column doesn't have to be
     * sortable, or be one of the table's columns.
     */
    public function defaultSort(string $column, string $direction = 'asc'): static
    {
        $this->defaultSort = ($direction === 'desc' ? '-' : '').$column;

        return $this;
    }

    public function getDefaultSort(): ?string
    {
        return $this->defaultSort;
    }

    public function isSearchable(): bool
    {
        return collect($this->getColumns())->contains(fn (Column $column): bool => $column->isGloballySearchable());
    }

    /**
     * @internal
     */
    public function findEditableColumn(string $name): (Column&Editable)|null
    {
        foreach ($this->getColumns() as $column) {
            if ($column->getName() === $name && $column instanceof Editable) {
                return $column;
            }
        }

        return null;
    }

    /**
     * Group rows by a column, with a heading over each group. Rows sort by it first.
     */
    public function defaultGroup(?string $column): static
    {
        $this->groupColumn = $column;

        return $this;
    }

    /**
     * Show rows as cards, this many across on a wide screen.
     */
    public function contentGrid(int $columns): static
    {
        $this->contentGridColumns = $columns;

        return $this;
    }

    /**
     * Check for changes this often, in seconds or minutes, as in '10s' or '1m'.
     */
    public function poll(string $interval): static
    {
        if (! preg_match('/^(\d+)\s*(s|m)$/', $interval, $match) || (int) $match[1] === 0) {
            throw new TableDefinitionException("Poll every [{$interval}] isn't an interval. Use seconds or minutes, as in '10s' or '1m'.");
        }

        $this->pollSeconds = (int) $match[1] * ($match[2] === 'm' ? 60 : 1);

        return $this;
    }

    public function striped(bool $condition = true): static
    {
        $this->isStriped = $condition;

        return $this;
    }

    /**
     * Let rows move up and down, by a number column. Nobody can until $authorization
     * says so. The table sorts by that column.
     */
    public function reorderable(string $column, bool|Closure $authorization): static
    {
        $this->reorderColumn = $column;
        $this->reorderAuthorization = $authorization;
        $this->defaultSort($column);

        return $this;
    }

    public function getReorderColumn(): ?string
    {
        return $this->reorderColumn;
    }

    /**
     * The rows a row can swap places with.
     *
     * @return Builder<covariant Model>
     */
    public function getReorderQuery(): Builder
    {
        return $this->query();
    }

    public function canReorder(): bool
    {
        return $this->reorderColumn !== null && (bool) value($this->reorderAuthorization);
    }

    public function findSortableColumn(string $name): ?Column
    {
        return collect($this->getColumns())->first(fn (Column $column): bool => $column->isSortable() && $column->getName() === $name);
    }

    /**
     * The most rows one bulk action can take. The server enforces it.
     */
    public function maxSelectableRecords(int $count): static
    {
        $this->maxSelectableRecords = $count;

        return $this;
    }

    public function getMaxSelectableRecords(): int
    {
        return $this->maxSelectableRecords;
    }

    /**
     * What to show when there are no rows, in place of "No records found."
     */
    public function emptyState(string $heading, ?string $description = null, ?string $icon = null): static
    {
        $this->emptyState = array_filter(['heading' => $heading, 'description' => $description, 'icon' => $icon], fn (?string $value): bool => $value !== null);

        return $this;
    }

    /**
     * Buttons in the empty state, such as one that creates the first record.
     *
     * @return array<int, Action|ActionGroup>
     */
    public function emptyStateActions(): array
    {
        return [];
    }

    /**
     * A title above the table, as on a dashboard.
     */
    public function heading(?string $heading): static
    {
        $this->heading = $heading;

        return $this;
    }

    /**
     * Smaller text under the heading.
     */
    public function description(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Where clicking a row goes. A link of a cell's own wins over this.
     */
    public function recordUrl(Model $record): ?string
    {
        return null;
    }

    /**
     * The record action clicking a row opens, by name. A link wins over this.
     */
    public function recordAction(Model $record): ?string
    {
        return null;
    }

    /**
     * Which rows can be selected for bulk actions. It gets $record. The server
     * checks it too, so a row it refuses never reaches a bulk action.
     */
    public function checkIfRecordIsSelectableUsing(?Closure $callback): static
    {
        $this->isRecordSelectable = $callback;

        return $this;
    }

    public function isRecordSelectable(Model $record): bool
    {
        return $this->isRecordSelectable === null || (bool) app()->call($this->isRecordSelectable, ['record' => $record]);
    }

    /**
     * The columns the viewer may see. A hidden one is left out of everything,
     * search and sorting included, so a viewer can't work out its values.
     *
     * @return array<int, Column>
     */
    public function getColumns(): array
    {
        if ($this->mountedColumns !== null) {
            return $this->mountedColumns;
        }

        $columns = array_values(array_filter(
            array_map(fn (Column $column): Column => $column->mount($this), array_merge(...array_map(
                fn (Column|ColumnGroup $item): array => $item instanceof Column ? [$item] : $item->getColumns(),
                $this->columns(),
            ))),
            fn (Column $column): bool => ! $column->isHidden(),
        ));
        $names = array_map(fn (Column $column): string => $column->getName(), $columns);

        if (count($names) !== count(array_unique($names))) {
            throw new TableDefinitionException('The table ['.static::class.'] has two columns with the same name. Cells are keyed by name, so one would overwrite the other.');
        }

        $model = $this->query()->getModel();

        foreach ($columns as $column) {
            $column->assertCanBeQueried($model);
        }

        return $this->mountedColumns = $columns;
    }

    /**
     * The first render. It reads the page's URL, so a link or a refresh shows
     * the same page of records.
     *
     * @return array<string, mixed>
     */
    public function toSchema(): array
    {
        abort_unless($this->authorize(auth()->user()), 403);

        $input = request()->query($this->getId());
        // A link or a refresh with state of its own wins over what the session kept.
        $input = is_array($input) && $input !== [] ? $input : ($this->persistedInSession === [] ? [] : (array) session($this->getSessionKey(), []));
        $state = TableState::from($input, $this);
        $result = $this->isLoadingDeferred ? $this->toDeferredResult($state) : $this->toResult($state);

        return [
            '$xq' => SchemaVersion::CURRENT,
            'pkg' => SchemaVersion::PACKAGE,
            'id' => $this->getId(),
            'token' => $this->makeToken(),
            'endpoints' => [
                'query' => route('xelqun.tables.query', absolute: false),
                'update' => route('xelqun.tables.update', absolute: false),
                'reorder' => route('xelqun.tables.reorder', absolute: false),
                'action' => route('xelqun.tables.action', absolute: false),
            ],
            'searchable' => $this->isSearchable(),
            ...$this->getClientMessages(),
            'columns' => array_map(fn (Column $column): array => $column->toDefinition(), $this->getColumns()),
            'headerActions' => TableActionHost::for($this, TableActionHost::HEADER)->toActionNodes(),
            'maxSelectable' => $this->maxSelectableRecords,
            'layout' => ['grid' => $this->contentGridColumns, 'striped' => $this->isStriped, 'pollSeconds' => $this->pollSeconds, 'reorderable' => $this->canReorder()],
            ...($this->emptyState === null ? [] : ['emptyState' => $this->emptyState]),
            ...array_filter(['heading' => $this->heading, 'description' => $this->description]),
            ...($this->emptyStateActions() === [] ? [] : ['emptyStateActions' => TableActionHost::for($this, TableActionHost::EMPTY)->toActionNodes()]),
            'filters' => $this->getFilters() === [] ? null : $this->getFiltersForm()->toSchema(),
            'filterDefaults' => (object) $this->getFilterDefaults(),
            ...($this->hasDeferredFilters ? [] : ['liveFilters' => true]),
            ...($this->isLoadingDeferred ? ['deferred' => true] : []),
            ...$result,
        ];
    }

    /**
     * The part of the schema that changes with the viewer's state.
     *
     * @internal
     *
     * @return array{query: object, sort: string|null, activeFilters: int, bulkActions: array<int, mixed>, rows: array<int, array<string, mixed>>, pagination: array<string, mixed>}
     */
    public function toResult(TableState $state): array
    {
        $columns = $this->getColumns();
        $hasRecordActions = $this->recordActions() !== [];
        $query = $this->buildQuery($state);
        [$records, $state, $pagination] = $this->paginate($query, $state);

        return [
            'query' => (object) $state->toQuery($this),
            'sort' => $state->sort,
            'activeFilters' => count($this->getActiveFilters($state)),
            'indicators' => $this->getIndicators($state),
            // Here and not in the first render, so a filter can change which bulk actions make sense.
            'bulkActions' => TableActionHost::for($this, TableActionHost::BULK)->toActionNodes(),
            'summaries' => $this->getSummaries($state),
            'rows' => $records->map(fn (Model $record): array => [
                'key' => (string) $record->getKey(),
                ...array_filter([
                    'url' => SafeUrl::filter($this->recordUrl($record)),
                    'group' => $this->getRowGroup($record),
                    'action' => $hasRecordActions ? $this->recordAction($record) : null,
                ]),
                ...($this->isRecordSelectable($record) ? [] : ['selectable' => false]),
                'cells' => collect($columns)
                    ->mapWithKeys(fn (Column $column): array => [$column->getName() => $column->toCell($record)])
                    ->all(),
                'actions' => $hasRecordActions ? TableActionHost::for($this, TableActionHost::RECORD, $record)->toActionNodes() : [],
            ])->all(),
            'pagination' => $pagination,
        ];
    }

    /**
     * The rows of this page, the state with the page it really is, and what
     * the pager shows.
     *
     * @param  Builder<covariant Model>  $query
     * @return array{0: Collection<int, Model>, 1: TableState, 2: array<string, mixed>}
     */
    private function paginate(Builder $query, TableState $state): array
    {
        $shared = [
            'perPage' => $state->perPage === 0 ? 'all' : $state->perPage,
            'options' => $this->paginationPageOptions,
            ...($this->isPaginated ? [] : ['hidden' => true]),
        ];

        if ($this->paginationMode === 'cursor' && $state->perPage > 0) {
            // A cursor needs a unique order, so the key breaks ties.
            // shortcut: Laravel's cursor reads plain columns, so a sort through a relation or on counts()/sum() fails here; use default pagination for those tables, or teach the cursor the expression if one needs it.
            $records = $query->orderBy($query->getModel()->getQualifiedKeyName())->cursorPaginate($state->perPage, cursor: $state->cursor);

            return [$records->getCollection(), $state, [...$shared, 'mode' => 'cursor', 'page' => 1, 'next' => $records->nextCursor()?->encode(), 'previous' => $records->previousCursor()?->encode()]];
        }

        if ($this->paginationMode === 'simple' && $state->perPage > 0) {
            $records = $query->simplePaginate($state->perPage, page: $state->page);

            return [$records->getCollection(), $state, [...$shared, 'mode' => 'simple', 'page' => $records->currentPage(), 'hasMore' => $records->hasMorePages()]];
        }

        $total = $query->toBase()->getCountForPagination();
        $perPage = $state->perPage === 0 ? max($total, 1) : $state->perPage;

        // Counted first, because deleting the last row of the last page leaves the viewer past the end.
        $state = $state->withPage(min($state->page, max((int) ceil($total / $perPage), 1)));
        $records = $query->paginate($perPage, page: $state->page, total: $total);

        return [$records->getCollection(), $state, ['page' => $records->currentPage(), 'perPage' => $shared['perPage'], 'total' => $total, 'lastPage' => $records->lastPage(), ...$shared]];
    }

    /**
     * What the first render sends when the rows load afterwards: the state,
     * with nothing that needs the query.
     *
     * @return array<string, mixed>
     */
    private function toDeferredResult(TableState $state): array
    {
        return [
            'query' => (object) $state->toQuery($this),
            'sort' => $state->sort,
            'activeFilters' => count($this->getActiveFilters($state)),
            'indicators' => [],
            'bulkActions' => TableActionHost::for($this, TableActionHost::BULK)->toActionNodes(),
            'summaries' => [],
            'rows' => [],
            'pagination' => ['page' => $state->page, 'perPage' => $state->perPage === 0 ? 'all' : $state->perPage, 'total' => 0, 'lastPage' => 1, 'options' => $this->paginationPageOptions],
        ];
    }

    /**
     * Over all the rows of this search and these filters.
     *
     * @return array<string, array<int, array{label: string|null, value: mixed}>>
     */
    private function getSummaries(TableState $state): array
    {
        $query = $this->buildQuery($state);
        $summaries = [];

        foreach ($this->getColumns() as $column) {
            $lines = $column->toSummaries($query);

            if ($lines !== []) {
                $summaries[$column->getName()] = $lines;
            }
        }

        return $summaries;
    }

    /**
     * A related value sorts the way its column does, so `category.name` groups like it sorts.
     *
     * @param  Builder<covariant Model>  $query
     */
    private function applyGroupOrder(Builder $query): void
    {
        if (! str_contains((string) $this->groupColumn, '.')) {
            $query->orderBy($query->qualifyColumn((string) $this->groupColumn));

            return;
        }

        $column = array_find($this->getColumns(), fn (Column $column): bool => $column->getName() === $this->groupColumn);

        if ($column === null) {
            throw new TableDefinitionException("Group by [{$this->groupColumn}] needs a column with that name.");
        }

        $column->applySort($query, 'asc');
    }

    private function getRowGroup(Model $record): ?string
    {
        if ($this->groupColumn === null) {
            return null;
        }

        foreach ($this->getColumns() as $column) {
            if ($column->getName() === $this->groupColumn) {
                return (string) ($column->toCell($record)['state'] ?? '');
            }
        }

        return (string) $record->getAttribute($this->groupColumn);
    }

    /**
     * A chip for each active filter, with the fields that removing it clears.
     *
     * @return array<int, array{label: string, fields: array<int, string>}>
     */
    private function getIndicators(TableState $state): array
    {
        $indicators = [];

        foreach ($this->getActiveFilters($state) as $filter) {
            $fields = $filter->getFieldNames();

            foreach ($filter->getIndicators(Arr::only($state->filters, $fields)) as $label) {
                $indicators[] = ['label' => $label, 'fields' => $fields];
            }
        }

        return $indicators;
    }

    /**
     * @return array<int, BaseFilter>
     */
    private function getActiveFilters(TableState $state): array
    {
        return array_values(array_filter(
            $this->getFilters(),
            fn (BaseFilter $filter): bool => $filter->isActive(Arr::only($state->filters, $filter->getFieldNames())),
        ));
    }

    /**
     * @return Builder<covariant Model>
     */
    private function buildQuery(TableState $state): Builder
    {
        $query = $this->query();
        $model = $query->getModel();

        $query->with(array_values(array_filter(
            array_map(fn (Column $column): ?string => $column->getEagerLoad($model), $this->getColumns()),
        )));

        if ($state->search !== '') {
            // A global scope, because a where added here would escape an orWhere in query().
            $query->withGlobalScope('xelqun-search', fn (Builder $scoped): Builder => $scoped->where(function (Builder $group) use ($state): void {
                foreach ($this->getColumns() as $column) {
                    if ($column->isGloballySearchable()) {
                        $column->applySearch($group, $state->search);
                    }
                }
            }));
        }

        if ($state->columnSearches !== []) {
            $query->withGlobalScope('xelqun-column-search', function (Builder $scoped) use ($state): void {
                foreach ($this->getColumns() as $column) {
                    if (isset($state->columnSearches[$column->getName()])) {
                        $scoped->where(fn (Builder $group) => $column->applySearch($group, $state->columnSearches[$column->getName()]));
                    }
                }
            });
        }

        foreach ($this->getColumns() as $column) {
            $column->applyAggregate($query);
        }

        $activeFilters = $this->getActiveFilters($state);

        // A global scope, like the others, so an orWhere in query() can't get around it.
        $query->withGlobalScope('xelqun-inactive-filters', function (Builder $scoped) use ($activeFilters): void {
            foreach ($this->getFilters() as $filter) {
                if (! in_array($filter, $activeFilters, true)) {
                    $scoped->where(fn (Builder $group) => $filter->applyWhenInactive($group));
                }
            }
        });

        if ($activeFilters !== []) {
            // One group for each, so an orWhere in a filter's closure stays inside that filter.
            $query->withGlobalScope('xelqun-filters', function (Builder $scoped) use ($activeFilters, $state): void {
                foreach ($activeFilters as $filter) {
                    $scoped->where(fn (Builder $group) => $filter->apply($group, Arr::only($state->filters, $filter->getFieldNames())));
                }
            });
        }

        if ($state->sort !== null) {
            $name = ltrim($state->sort, '-');
            $direction = str_starts_with($state->sort, '-') ? 'desc' : 'asc';
            $column = $this->findSortableColumn($name);

            $query->reorder();

            if ($column === null) {
                $query->orderBy($query->qualifyColumn($name), $direction);
            } else {
                $column->applySort($query, $direction);
            }
        }

        if ($this->groupColumn !== null) {
            // Ahead of any sort, so a group's rows stay together.
            $orders = $query->getQuery()->orders ?? [];
            $orderBindings = $query->getQuery()->bindings['order'];
            $query->reorder();
            $this->applyGroupOrder($query);
            $query->getQuery()->orders = [...$query->getQuery()->orders ?? [], ...$orders];
            $query->getQuery()->bindings['order'] = [...$query->getQuery()->bindings['order'], ...$orderBindings];
        }

        // Rows with equal values have no order of their own, so they could repeat across pages.
        return $query->orderBy($model->getQualifiedKeyName());
    }

    public function makeToken(): string
    {
        return app(FormTokenEncrypter::class)->encrypt(new FormToken(
            formClass: static::class,
            recordKey: null,
            userId: auth()->id(),
            context: ['context' => $this->context, 'id' => $this->tableId],
        ));
    }

    /**
     * Text the browser shows, in this locale. It is written in English and uses
     * that as the key, so only lines that differ have to travel.
     *
     * @return array{messages?: array<string, string>}
     */
    private function getClientMessages(): array
    {
        $lines = trans('xelqun-tables::client');
        $messages = is_array($lines)
            ? array_filter($lines, fn (string $text, string $key): bool => $text !== $key, ARRAY_FILTER_USE_BOTH)
            : [];

        return $messages === [] ? [] : ['messages' => $messages];
    }
}
