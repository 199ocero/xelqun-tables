<?php

namespace Xelqun\Tables\Filters\Constraints;

use Illuminate\Database\Eloquent\Builder;

/**
 * @api
 */
class TextConstraint extends Constraint
{
    public function getOperators(): array
    {
        return [
            'contains' => __('xelqun-tables::messages.operators.contains'),
            'not_contains' => __('xelqun-tables::messages.operators.not_contains'),
            'starts_with' => __('xelqun-tables::messages.operators.starts_with'),
            'ends_with' => __('xelqun-tables::messages.operators.ends_with'),
            'equals' => __('xelqun-tables::messages.operators.equals'),
        ];
    }

    protected function constrain(Builder $query, string $column, string $operator, mixed $value): void
    {
        match ($operator) {
            'contains' => $query->whereLike($column, "%{$value}%"),
            'not_contains' => $query->whereNotLike($column, "%{$value}%"),
            'starts_with' => $query->whereLike($column, "{$value}%"),
            'ends_with' => $query->whereLike($column, "%{$value}"),
            default => $query->where($column, $value),
        };
    }
}
