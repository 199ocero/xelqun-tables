<?php

namespace Xelqun\Tables\Filters\Constraints;

use Illuminate\Database\Eloquent\Builder;

/**
 * @api
 */
class BooleanConstraint extends Constraint
{
    public function getValueField(): ?string
    {
        return null;
    }

    public function getOperators(): array
    {
        return ['is_true' => __('xelqun-tables::messages.operators.is_true'), 'is_false' => __('xelqun-tables::messages.operators.is_false')];
    }

    protected function accepts(mixed $value): bool
    {
        return true;
    }

    protected function constrain(Builder $query, string $column, string $operator, mixed $value): void
    {
        $query->where($column, $operator === 'is_true');
    }
}
