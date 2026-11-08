<?php

namespace Xelqun\Tables\Filters\Constraints;

use Illuminate\Database\Eloquent\Builder;

/**
 * @api
 */
class SelectConstraint extends Constraint
{
    /** @var array<string|int, string> */
    protected array $options = [];

    /**
     * @param  array<string|int, string>  $options
     */
    public function options(array $options): static
    {
        $this->options = $options;

        return $this;
    }

    public function getValueField(): ?string
    {
        return $this->options === [] ? 'value' : 'option';
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function getOperators(): array
    {
        return ['equals' => __('xelqun-tables::messages.operators.equals'), 'not_equals' => __('xelqun-tables::messages.operators.not_equals')];
    }

    protected function accepts(mixed $value): bool
    {
        return is_scalar($value) && ($this->options === [] || isset($this->options[$value]));
    }

    protected function constrain(Builder $query, string $column, string $operator, mixed $value): void
    {
        $query->where($column, $operator === 'equals' ? '=' : '!=', $value);
    }
}
