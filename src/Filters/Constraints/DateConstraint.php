<?php

namespace Xelqun\Tables\Filters\Constraints;

use Illuminate\Database\Eloquent\Builder;

/**
 * @api
 */
class DateConstraint extends Constraint
{
    private const array COMPARISONS = ['on' => '=', 'before' => '<', 'after' => '>'];

    public function getValueField(): ?string
    {
        return 'date';
    }

    public function getOperators(): array
    {
        $operators = [];

        foreach (array_keys(self::COMPARISONS) as $operator) {
            $operators[$operator] = __("xelqun-tables::messages.operators.{$operator}");
        }

        return $operators;
    }

    /**
     * Some databases stop with an error on a word where a date goes.
     */
    protected function accepts(mixed $value): bool
    {
        return is_string($value) && strtotime($value) !== false;
    }

    protected function constrain(Builder $query, string $column, string $operator, mixed $value): void
    {
        $query->whereDate($column, self::COMPARISONS[$operator], (string) date('Y-m-d', (int) strtotime($value)));
    }
}
