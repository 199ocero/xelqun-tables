<?php

namespace Xelqun\Tables\Filters;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Macroable;
use Xelqun\Forms\Components\Component;
use Xelqun\Forms\Components\Field;
use Xelqun\Tables\Table;

/**
 * One filter of a table. Its fields are real form fields, so everything a form
 * can do works here, and the browser's values are checked by their rules.
 *
 * @api Anything public or protected here, unless it says @internal.
 */
abstract class BaseFilter
{
    use Macroable;

    protected ?Table $table = null;

    protected string|Closure|null $label = null;

    protected bool|Closure $hidden = false;

    protected mixed $default = null;

    final public function __construct(protected string $name) {}

    public static function make(string $name): static
    {
        $filter = new static($name);
        $filter->setUp();

        return $filter;
    }

    protected function setUp(): void {}

    /**
     * The fields the viewer fills in. Their names are the keys of $data, and
     * they have to be unique across the table's filters.
     *
     * @return array<int, Component>
     */
    abstract public function getFormComponents(): array;

    /**
     * Narrow the query. It only runs while the filter is active.
     *
     * @param  Builder<covariant Model>  $query
     * @param  array<string, mixed>  $data  This filter's values.
     */
    abstract public function apply(Builder $query, array $data): void;

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

    /**
     * The value before the viewer changes anything.
     */
    public function default(mixed $value): static
    {
        $this->default = $value;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        return $this->evaluate($this->label) ?? Str::headline($this->name);
    }

    public function isHidden(): bool
    {
        return (bool) $this->evaluate($this->hidden);
    }

    /**
     * @param  array<string, mixed>  $data  This filter's values.
     */
    public function isActive(array $data): bool
    {
        foreach ($data as $value) {
            if (! in_array($value, [null, '', false, []], true)) {
                return true;
            }
        }

        return false;
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
     * @return array<int, string>
     */
    public function getFieldNames(): array
    {
        $names = [];

        $collect = function (array $components) use (&$collect, &$names): void {
            foreach ($components as $component) {
                if ($component instanceof Field) {
                    $names[] = $component->getName();
                }

                $collect($component->getChildComponents());
            }
        };

        $collect($this->getFormComponents());

        return $names;
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function evaluate(mixed $value, array $parameters = []): mixed
    {
        return $value instanceof Closure
            ? app()->call($value, ['filter' => $this, 'table' => $this->table, ...$parameters])
            : $value;
    }
}
