<?php

namespace Xelqun\Tables\Summarizers;

use Illuminate\Database\Eloquent\Builder;

/**
 * The lowest and highest value.
 *
 * @api
 */
class Range extends Summarizer
{
    public function summarize(Builder $query, string $column): mixed
    {
        return [(clone $query)->min($column), (clone $query)->max($column)];
    }

    public function getAggregates(): array
    {
        return ['min', 'max'];
    }

    public function fromAggregates(array $values): mixed
    {
        return [$values[0] ?? null, $values[1] ?? null];
    }
}
