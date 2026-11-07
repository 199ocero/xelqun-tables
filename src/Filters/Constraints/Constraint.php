<?php

namespace Xelqun\Tables\Filters\Constraints;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One thing a QueryBuilder filter can ask about. The columns and operators are
 * the developer's, so a visitor only ever supplies the value.
 *
 * @api
 */
abstract class Constraint
{
    protected ?string $label = null;

    final public function __construct(protected string $attribute) {}

    public static function make(string $attribute): static
    {
        return new static($attribute);
    }

    public function label(?string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getAttribute(): string
    {
        return $this->attribute;
    }

    public function getLabel(): string
    {
        return $this->label ?? Str::headline(Str::afterLast($this->attribute, '.'));
    }

    /**
     * @return array<string, string> Operator => label.
     */
    abstract public function getOperators(): array;

    /**
     * Whether this operator can use this value. A rule that can't is skipped, as a
     * bad value in a filter is.
     */
    protected function accepts(mixed $value): bool
    {
        return is_scalar($value) && (string) $value !== '';
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    abstract protected function constrain(Builder $query, string $column, string $operator, mixed $value): void;

    /**
     * @param  Builder<covariant Model>  $query
     */
    public function apply(Builder $query, string $operator, mixed $value): void
    {
        if (! isset($this->getOperators()[$operator]) || ! $this->accepts($value)) {
            return;
        }

        if (! str_contains($this->attribute, '.')) {
            $this->constrain($query, $query->qualifyColumn($this->attribute), $operator, $value);

            return;
        }

        $query->whereHas(Str::beforeLast($this->attribute, '.'), function (Builder $related) use ($operator, $value): void {
            $this->constrain($related, $related->qualifyColumn(Str::afterLast($this->attribute, '.')), $operator, $value);
        });
    }
}
