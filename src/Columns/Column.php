<?php

namespace Xelqun\Tables\Columns;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Macroable;
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
        return $this->stateUsing === null
            ? data_get($record, $this->name)
            : $this->evaluate($this->stateUsing, ['record' => $record]);
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
     * @return array{name: string, type: string, label: string, props: object}
     */
    public function toDefinition(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->getType(),
            'label' => $this->getLabel(),
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

        return ['state' => $state, ...$this->getCellProps($record, $state)];
    }
}
