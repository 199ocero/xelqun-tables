<?php

namespace Xelqun\Tables\Columns;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Macroable;
use Xelqun\Tables\Exceptions\TableDefinitionException;
use Xelqun\Tables\Table;

/**
 * One column of a table. A column isn't a form Component, because a form
 * component belongs to a form's state and a column reads records.
 *
 * @api Anything public or protected here, unless it says @internal.
 */
abstract class Column
{
    use Macroable;

    /** @var array<class-string<Column>, array<int, Closure>> */
    private static array $configurations = [];

    protected ?Table $table = null;

    protected string|Closure|null $label = null;

    protected bool|Closure $hidden = false;

    protected ?Closure $stateUsing = null;

    protected bool $isSortable = false;

    protected ?Closure $sortUsing = null;

    protected bool $isSearchable = false;

    protected ?Closure $searchUsing = null;

    final public function __construct(protected string $name) {}

    public static function make(string $name): static
    {
        $column = new static($name);
        $column->setUp();

        return $column->configure();
    }

    /**
     * Run a callback on every new instance of this class and its subclasses,
     * after setUp() and before the caller's own settings. Call it from a
     * service provider: it lasts as long as the process.
     *
     * @param  Closure(static): mixed  $callback
     */
    public static function configureUsing(Closure $callback): void
    {
        self::$configurations[static::class][] = $callback;
    }

    /**
     * Forget every configureUsing() callback, so tests don't leak into each other.
     *
     * @internal
     */
    public static function flushConfigurations(): void
    {
        self::$configurations = [];
    }

    protected function setUp(): void {}

    protected function configure(): static
    {
        foreach (self::$configurations as $class => $callbacks) {
            if (! $this instanceof $class) {
                continue;
            }

            foreach ($callbacks as $callback) {
                $callback($this);
            }
        }

        return $this;
    }

    /**
     * The renderer key of this column, e.g. "text-column". Plugins use a vendor
     * prefix. Forms shares the same registry, so built-ins end in "-column".
     */
    abstract public function getType(): string;

    /**
     * What the browser needs for the whole column.
     *
     * @return array<string, mixed>
     */
    protected function getProps(): array
    {
        return [];
    }

    /**
     * What the browser needs for one cell, beside its state.
     *
     * @return array<string, mixed>
     */
    protected function getCellProps(Model $record, mixed $state): array
    {
        return [];
    }

    public function label(string|Closure|null $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function hidden(bool|Closure $condition = true): static
    {
        $this->hidden = $condition;

        return $this;
    }

    public function visible(bool|Closure $condition = true): static
    {
        $this->hidden = $condition instanceof Closure
            ? fn (): bool => ! $this->evaluate($condition)
            : ! $condition;

        return $this;
    }

    /**
     * Work out the cell's value instead of reading it from the record.
     */
    public function state(?Closure $using): static
    {
        $this->stateUsing = $using;

        return $this;
    }

    /**
     * Let the viewer sort by this column. A relation works over one BelongsTo
     * or HasOne. Anything else needs a closure, which receives $query and
     * $direction.
     */
    public function sortable(bool $condition = true, ?Closure $query = null): static
    {
        $this->isSortable = $condition;
        $this->sortUsing = $query;

        return $this;
    }

    /**
     * Let the viewer search this column, through relations too. A JSON path
     * needs a closure, which receives $query and $search and should add
     * orWhere conditions.
     */
    public function searchable(bool $condition = true, ?Closure $query = null): static
    {
        $this->isSearchable = $condition;
        $this->searchUsing = $query;

        return $this;
    }

    public function isSortable(): bool
    {
        return $this->isSortable;
    }

    public function isSearchable(): bool
    {
        return $this->isSearchable;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        return $this->evaluate($this->label) ?? Str::headline(str_replace('.', ' ', $this->name));
    }

    public function isHidden(): bool
    {
        return (bool) $this->evaluate($this->hidden);
    }

    public function getState(Model $record): mixed
    {
        if ($this->stateUsing !== null) {
            return $this->evaluate($this->stateUsing, ['record' => $record]);
        }

        $state = $record;

        foreach (explode('.', $this->name) as $segment) {
            $state = $state instanceof Collection
                ? $state->map(fn (mixed $item): mixed => data_get($item, $segment))->flatten(1)
                : data_get($state, $segment);
        }

        return $state;
    }

    /**
     * Split the name into the relations it walks and what is left on the last
     * model: "category.owner.name" is [['category', 'owner'], 'name'].
     *
     * @return array{0: array<int, string>, 1: string}
     */
    protected function getPath(Model $model): array
    {
        $relations = [];
        $segments = explode('.', $this->name);

        foreach ($segments as $segment) {
            // isRelation() is true for any method, and calling save() here would write a row.
            if (method_exists(Model::class, $segment) || ! $model->isRelation($segment)) {
                break;
            }

            $relation = $model->{$segment}();

            if (! $relation instanceof Relation) {
                break;
            }

            $relations[] = $segment;
            $model = $relation->getRelated();
        }

        return [$relations, implode('.', array_slice($segments, count($relations)))];
    }

    /**
     * The relation to load up front, so rows don't each run a query.
     */
    public function getEagerLoad(Model $model): ?string
    {
        if ($this->stateUsing !== null) {
            return null;
        }

        return implode('.', $this->getPath($model)[0]) ?: null;
    }

    /**
     * Fail while the page renders, not on the viewer's first click.
     *
     * @internal
     */
    public function assertCanBeQueried(Model $model): void
    {
        [$relations, $attribute] = $this->getPath($model);
        $first = $relations === [] ? null : $model->{$relations[0]}();
        $isPlainColumn = $attribute !== '' && ! str_contains($attribute, '.');

        if ($this->isSearchable && $this->searchUsing === null && (! $isPlainColumn || $first instanceof MorphTo)) {
            throw new TableDefinitionException("The column [{$this->name}] can't be searched by itself. Pass a closure to searchable().");
        }

        $isSingleRecord = $first === null
            || (count($relations) === 1 && ($first instanceof BelongsTo || $first instanceof HasOne) && ! $first instanceof MorphTo);

        if ($this->isSortable && $this->sortUsing === null && (! $isPlainColumn || ! $isSingleRecord)) {
            throw new TableDefinitionException("The column [{$this->name}] can't be sorted by itself. Pass a closure to sortable().");
        }
    }

    /**
     * Add this column to the search. It runs inside one where group, so each
     * condition has to be an "or".
     *
     * @internal
     *
     * @param  Builder<covariant Model>  $query
     */
    public function applySearch(Builder $query, string $search): void
    {
        if ($this->searchUsing !== null) {
            $this->evaluate($this->searchUsing, ['query' => $query, 'search' => $search]);

            return;
        }

        [$relations, $attribute] = $this->getPath($query->getModel());

        // shortcut: % and _ in a search act as wildcards, because whereLike can't escape them on every database. Add a raw per-driver clause if someone needs them literally.
        $pattern = "%{$search}%";

        if ($relations === []) {
            $query->orWhereLike($query->qualifyColumn($attribute), $pattern);

            return;
        }

        $query->orWhereHas(
            implode('.', $relations),
            fn (Builder $related): Builder => $related->whereLike($related->qualifyColumn($attribute), $pattern),
        );
    }

    /**
     * @internal
     *
     * @param  Builder<covariant Model>  $query
     */
    public function applySort(Builder $query, string $direction): void
    {
        if ($this->sortUsing !== null) {
            $this->evaluate($this->sortUsing, ['query' => $query, 'direction' => $direction]);

            return;
        }

        [$relations, $attribute] = $this->getPath($query->getModel());

        if ($relations === []) {
            $query->orderBy($query->qualifyColumn($attribute), $direction);

            return;
        }

        $relation = $query->getModel()->{$relations[0]}();

        $query->orderBy(
            $relation->getRelationExistenceQuery($relation->getRelated()->newQuery(), $query, [$attribute])->limit(1),
            $direction,
        );
    }

    /**
     * @internal
     */
    public function mount(Table $table): static
    {
        $this->table = $table;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function evaluate(mixed $value, array $parameters = []): mixed
    {
        return $value instanceof Closure
            ? app()->call($value, ['column' => $this, 'table' => $this->table, ...$parameters])
            : $value;
    }

    /**
     * @internal Extend getProps() instead.
     *
     * @return array{name: string, type: string, label: string, sortable: bool, props: object}
     */
    public function toDefinition(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->getType(),
            'label' => $this->getLabel(),
            'sortable' => $this->isSortable,
            'props' => (object) $this->getProps(),
        ];
    }

    /**
     * @internal Extend getCellProps() instead.
     *
     * @return array<string, mixed>
     */
    public function toCell(Model $record): array
    {
        $state = $this->getState($record);

        return ['state' => $this->reduceToKeys($state), ...$this->getCellProps($record, $state)];
    }

    /**
     * A column named after a relation would otherwise send the whole related
     * record, which holds values nobody declared.
     */
    private function reduceToKeys(mixed $state): mixed
    {
        return match (true) {
            $state instanceof Model => $state->getKey(),
            $state instanceof Collection => $state->map(fn (mixed $item): mixed => $this->reduceToKeys($item))->values()->all(),
            default => $state,
        };
    }
}
