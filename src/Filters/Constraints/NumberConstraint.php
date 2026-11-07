<?php

namespace Xelqun\Tables\Filters\Constraints;

use Illuminate\Database\Eloquent\Builder;

/**
 * @api
 */
class NumberConstraint extends Constraint
{
    private const array COMPARISONS = ['equals' => '=', 'not_equals' => '!=', 'greater' => '>', 'greater_or_equal' => '>=', 'less' => '<', 'less_or_equal' => '<='];

    public function getOperators(): array
    {
        $operators = [];

        foreach (array_keys(self::COMPARISONS) as $operator) {
            $operators[$operator] = __("xelqun-tables::messages.operators.{$operator}");
        }

        return $operators;
    }

    protected function accepts(mixed $value): bool
    {
        return is_numeric($value);
    }

    protected function constrain(Builder $query, string $column, string $operator, mixed $value): void
    {
        $query->where($column, self::COMPARISONS[$operator], $value);
    }
}
