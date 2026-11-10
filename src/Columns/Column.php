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
use UnitEnum;
use Xelqun\Forms\Support\SafeUrl;
use Xelqun\Tables\Columns\Contracts\Editable;
use Xelqun\Tables\Exceptions\TableDefinitionException;
use Xelqun\Tables\Summarizers\Summarizer;
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

    protected ?Closure $formatStateUsing = null;

    protected string|Closure|null $color = null;

    protected string|Closure|null $icon = null;

    protected string|Closure|null $url = null;

    protected mixed $default = null;

    protected string|Closure|null $tooltip = null;

    protected ?string $alignment = null;

    protected ?string $width = null;

    protected bool $grows = false;

    protected bool $wrapsHeader = false;

    protected ?string $group = null;

    protected bool $opensUrlInNewTab = false;

    protected bool $isSortable = false;

    protected ?Closure $sortUsing = null;

    protected bool $isSearchable = false;

    protected ?Closure $searchUsing = null;

    protected bool $isIndividuallySearchable = false;

    protected bool $isGloballySearchable = true;

    /** @var array{0: string, 1: string, 2: string|null}|null A function, a relation and its column. */
    protected ?array $aggregate = null;

    /** @var array<int, Summarizer> */
    protected array $summarizers = [];

    protected bool $isToggleable = false;

    protected bool $isHiddenByDefault = false;

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

    /**
     * What the cell holds when the record has nothing there. A closure gets $record.
     */
    public function default(mixed $value): static
    {
        $this->default = $value;

        return $this;
    }

    /**
     * Text shown on hover. A closure gets $record and $state.
     */
    public function tooltip(string|Closure|null $tooltip): static
    {
        $this->tooltip = $tooltip;

        return $this;
    }

    /**
     * start, center or end, for the heading, the cells and the summary.
     */
    public function alignment(?string $alignment): static
    {
        $this->alignment = $alignment;

        return $this;
    }

    /**
     * Any CSS width, such as '8rem' or '20%'.
     */
    public function width(?string $width): static
    {
        $this->width = $width;

        return $this;
    }

    /**
     * Take the room the other columns leave.
     */
    public function grow(bool $condition = true): static
    {
        $this->grows = $condition;

        return $this;
    }

    /**
     * Let a long heading run onto more lines.
     */
    public function wrapHeader(bool $condition = true): static
    {
        $this->wrapsHeader = $condition;

        return $this;
    }

    /**
     * @internal Set by ColumnGroup.
     */
    public function inGroup(string $label): static
    {
        $this->group = $label;

        return $this;
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
     * Change the value for display. Sorting and search still use the original.
     * It receives $state and $record, once for each value of a list.
     */
    public function formatStateUsing(?Closure $using): static
    {
        $this->formatStateUsing = $using;

        return $this;
    }

    /**
     * gray, primary, success, warning, danger or info. A closure receives
     * $state and $record, as it does in icon() and url(). A cell with no value
     * runs neither this nor icon(), so $state can be typed.
     */
    public function color(string|Closure|null $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function icon(string|Closure|null $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    /**
     * Link the cell. A URL outside http(s), mailto, tel or this site shows no
     * link, because a closure may build it from a record.
     */
    public function url(string|Closure|null $url, bool $openInNewTab = false): static
    {
        $this->url = $url;
        $this->opensUrlInNewTab = $openInNewTab;

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
    public function searchable(bool $condition = true, ?Closure $query = null, bool $isIndividual = false, bool $isGlobal = true): static
    {
        $this->isSearchable = $condition;
        $this->searchUsing = $query;
        $this->isIndividuallySearchable = $condition && $isIndividual;
        $this->isGloballySearchable = $isGlobal;

        return $this;
    }

    /**
     * A search box of its own, under the heading.
     */
    public function isIndividuallySearchable(): bool
    {
        return $this->isIndividuallySearchable;
    }

    /**
     * Part of the search box above the table.
     */
    public function isGloballySearchable(): bool
    {
        return $this->isSearchable && $this->isGloballySearchable;
    }

    /**
     * How many related records there are, loaded with the rows. Name the
     * column after the result, such as 'orders_count'. It can be sorted.
     */
    public function counts(string $relation): static
    {
        $this->aggregate = ['count', $relation, null];

        return $this;
    }

    /**
     * The sum of a related column, loaded with the rows. It can be sorted.
     */
    public function sum(string $relation, string $column): static
    {
        $this->aggregate = ['sum', $relation, $column];

        return $this;
    }

    /**
     * @internal
     *
     * @param  Builder<covariant Model>  $query
     */
    public function applyAggregate(Builder $query): void
    {
        if ($this->aggregate === null) {
            return;
        }

        [$function, $relation, $column] = $this->aggregate;

        // Aliased to the column's name, so the cell reads it like any attribute.
        $query->withAggregate("{$relation} as {$this->name}", $column ?? '*', $function);
    }

    /**
     * Let the viewer hide this column. Their choice stays in their browser.
     */
    public function toggleable(bool $condition = true, bool $isHiddenByDefault = false): static
    {
        $this->isToggleable = $condition;
        $this->isHiddenByDefault = $condition && $isHiddenByDefault;

        return $this;
    }

    /**
     * Whether this column only works on a database query: a count or sum of
     * related rows, summaries, or inline editing.
     *
     * @internal
     */
    public function needsQuery(): bool
    {
        return $this->aggregate !== null || $this->summarizers !== [] || $this instanceof Editable;
    }

    /**
     * Add a line under the column.
     *
     * @param  Summarizer|array<int, Summarizer>  $summarizers
     */
    public function summarize(Summarizer|array $summarizers): static
    {
        $this->summarizers = is_array($summarizers) ? $summarizers : [$summarizers];

        return $this;
    }

    /**
     * Each line under the column, formatted like a cell.
     *
     * @internal
     *
     * @param  Builder<covariant Model>  $query
     * @return array<int, array{label: string|null, value: mixed}>
     */
    public function toSummaries(Builder $query): array
    {
        $model = $query->getModel();
        $attribute = $query->qualifyColumn($this->name);
        $results = $this->runAggregates($query, $attribute);

        return array_map(function (Summarizer $summarizer, int $index) use ($attribute, $model, $query, $results): array {
            $value = array_key_exists($index, $results)
                ? $summarizer->fromAggregates($results[$index])
                : $summarizer->summarize((clone $query)->reorder(), $attribute);

            return [
                'label' => $summarizer->getLabel(),
                'value' => is_array($value)
                    ? implode(' – ', array_map(fn (mixed $part): string => (string) $this->formatValue($part, $model), $value))
                    : $this->formatValue($value, $model),
            ];
        }, $this->summarizers, array_keys($this->summarizers));
    }

    /**
     * Every built-in summary of this column in one query, rather than one each.
     *
     * @param  Builder<covariant Model>  $query
     * @return array<int, array<int, mixed>> Summarizer index => its aggregates' results.
     */
    private function runAggregates(Builder $query, string $attribute): array
    {
        $base = (clone $query)->reorder()->toBase();

        // Laravel wraps such a query before it aggregates, so each summarizer does it on its own.
        if ($base->havings !== null || $base->unions !== null) {
            return [];
        }

        // The selected columns go with their bindings, or later bindings would land in the wrong place.
        $base = $base->cloneWithout(['columns'])->cloneWithoutBindings(['select']);
        $column = $base->getGrammar()->wrap($attribute);
        $selects = [];

        foreach ($this->summarizers as $index => $summarizer) {
            foreach ($summarizer->getAggregates() as $position => $function) {
                $selects["xq_{$index}_{$position}"] = "{$function}({$column})";
            }
        }

        if ($selects === []) {
            return [];
        }

        $row = (array) $base->selectRaw(implode(', ', array_map(fn (string $sql, string $alias): string => "{$sql} as {$alias}", $selects, array_keys($selects))))->first();
        $results = [];

        foreach (array_keys($selects) as $alias) {
            [, $index] = explode('_', $alias);
            $results[(int) $index][] = $row[$alias] ?? null;
        }

        return $results;
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
            $state = $this->evaluate($this->stateUsing, ['record' => $record]);
        } else {
            $state = $record;

            foreach (explode('.', $this->name) as $segment) {
                $state = $state instanceof Collection
                    ? $state->map(fn (mixed $item): mixed => data_get($item, $segment))->flatten(1)
                    : data_get($state, $segment);
            }
        }

        return $state ?? $this->evaluate($this->default, ['record' => $record]);
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
        if ($this->aggregate !== null) {
            if ($this->isSearchable && $this->searchUsing === null) {
                throw new TableDefinitionException("The column [{$this->name}] adds up related records, so it can't be searched by itself. Pass a closure to searchable().");
            }

            return;
        }

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
            // Its own group, so a closure that starts with where() can't AND into the other columns.
            $query->orWhere(fn (Builder $group) => $this->evaluate($this->searchUsing, ['query' => $group, 'search' => $search]));

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

        if ($this->aggregate !== null) {
            $query->orderBy($this->name, $direction);

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
     * @return array<string, mixed>
     */
    public function toDefinition(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->getType(),
            'label' => $this->getLabel(),
            'sortable' => $this->isSortable,
            ...($this->opensUrlInNewTab ? ['opensInNewTab' => true] : []),
            ...($this->isToggleable ? ['toggleable' => true] : []),
            ...($this->isHiddenByDefault ? ['hiddenByDefault' => true] : []),
            ...array_filter([
                'alignment' => $this->alignment,
                'width' => $this->width,
                'grow' => $this->grows,
                'wrapHeader' => $this->wrapsHeader,
                'group' => $this->group,
                'searchableIndividually' => $this->isIndividuallySearchable,
            ]),
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
        $state = $this->reduceToKeys($this->getState($record));

        return [
            'state' => is_array($state)
                ? array_map(fn (mixed $value): mixed => $this->formatValue($value, $record), $state)
                : $this->formatValue($state, $record),
            ...array_filter([
                // shortcut: a list gets one color and icon, and its closure receives the array. Send one per value if a table needs that.
                'color' => $state === null ? null : $this->evaluateForCell($this->color, $record, $state) ?? $this->fromEnum($state, 'getColor'),
                'icon' => $state === null ? null : $this->evaluateForCell($this->icon, $record, $state) ?? $this->fromEnum($state, 'getIcon'),
                'url' => SafeUrl::filter($this->evaluateForCell($this->url, $record, $state)),
                'tooltip' => $this->evaluateForCell($this->tooltip, $record, $state),
                ...$this->getCellProps($record, $state),
            ], fn (mixed $value): bool => $value !== null),
        ];
    }

    /**
     * What the cell shows for one value.
     */
    protected function formatValue(mixed $value, Model $record): mixed
    {
        if ($this->formatStateUsing !== null && $value !== null) {
            return $this->evaluate($this->formatStateUsing, ['state' => $value, 'record' => $record]);
        }

        if (! $value instanceof UnitEnum) {
            return $value;
        }

        return method_exists($value, 'getLabel') ? $value->getLabel() : $value->name;
    }

    protected function evaluateForCell(mixed $value, Model $record, mixed $state): mixed
    {
        return $this->evaluate($value, ['record' => $record, 'state' => $state]);
    }

    /**
     * A column named after a relation would otherwise send the whole related
     * record, which holds values nobody declared.
     */
    /**
     * An enum's own color or icon, when it has one.
     */
    private function fromEnum(mixed $state, string $method): mixed
    {
        return $state instanceof UnitEnum && method_exists($state, $method) ? $state->{$method}() : null;
    }

    private function reduceToKeys(mixed $state): mixed
    {
        return match (true) {
            $state instanceof Model => $state->getKey(),
            $state instanceof Collection => $state->map(fn (mixed $item): mixed => $this->reduceToKeys($item))->values()->all(),
            default => $state,
        };
    }
}
