<?php

namespace Xelqun\Tables;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Xelqun\Forms\Security\FormToken;
use Xelqun\Forms\Security\FormTokenEncrypter;
use Xelqun\Tables\Columns\Column;
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
    private ?string $tableId = null;

    /** @var array<int, int> */
    private array $paginationPageOptions = [10, 25, 50];

    private ?string $defaultSort = null;

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
     * @return array<int, Column>
     */
    abstract public function columns(): array;

    /**
     * @return array<int, BaseFilter>
     */
    public function filters(): array
    {
        return [];
    }

    public function authorize(?Authenticatable $user): bool
    {
        return true;
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
            : TableFiltersForm::for($this)->getData();
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
     * @param  array<int, int>  $options
     */
    public function paginationPageOptions(array $options): static
    {
        if ($options === [] || min($options) < 1) {
            throw new TableDefinitionException('The table ['.static::class.'] needs at least one page size, and none below 1.');
        }

        $this->paginationPageOptions = array_values($options);

        return $this;
    }

    /**
     * @return array<int, int>
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
        return collect($this->getColumns())->contains(fn (Column $column): bool => $column->isSearchable());
    }

    public function findSortableColumn(string $name): ?Column
    {
        return collect($this->getColumns())->first(fn (Column $column): bool => $column->isSortable() && $column->getName() === $name);
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
            array_map(fn (Column $column): Column => $column->mount($this), $this->columns()),
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
        $result = $this->toResult(TableState::from(is_array($input) ? $input : [], $this));

        return [
            '$xq' => SchemaVersion::CURRENT,
            'pkg' => SchemaVersion::PACKAGE,
            'id' => $this->getId(),
            'token' => $this->makeToken(),
            'endpoints' => ['query' => route('xelqun.tables.query', absolute: false)],
            'searchable' => $this->isSearchable(),
            ...$this->getClientMessages(),
            'columns' => array_map(fn (Column $column): array => $column->toDefinition(), $this->getColumns()),
            'filters' => $this->getFilters() === [] ? null : $this->getFiltersForm()->toSchema(),
            'filterDefaults' => (object) $this->getFilterDefaults(),
            ...$result,
        ];
    }

    /**
     * The part of the schema that changes with the viewer's state.
     *
     * @internal
     *
     * @return array{query: object, sort: string|null, activeFilters: int, rows: array<int, array<string, mixed>>, pagination: array<string, mixed>}
     */
    public function toResult(TableState $state): array
    {
        $columns = $this->getColumns();
        $query = $this->buildQuery($state);
        $total = $query->toBase()->getCountForPagination();

        // Counted first, because deleting the last row of the last page leaves the viewer past the end.
        $state = $state->withPage(min($state->page, max((int) ceil($total / $state->perPage), 1)));
        $records = $query->paginate($state->perPage, page: $state->page, total: $total);

        return [
            'query' => (object) $state->toQuery($this),
            'sort' => $state->sort,
            'activeFilters' => count($this->getActiveFilters($state)),
            'rows' => $records->getCollection()->map(fn (Model $record): array => [
                'key' => (string) $record->getKey(),
                'cells' => collect($columns)
                    ->mapWithKeys(fn (Column $column): array => [$column->getName() => $column->toCell($record)])
                    ->all(),
            ])->all(),
            'pagination' => [
                'page' => $records->currentPage(),
                'perPage' => $records->perPage(),
                'total' => $records->total(),
                'lastPage' => $records->lastPage(),
                'options' => $this->paginationPageOptions,
            ],
        ];
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
                    if ($column->isSearchable()) {
                        $column->applySearch($group, $state->search);
                    }
                }
            }));
        }

        $activeFilters = $this->getActiveFilters($state);

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
