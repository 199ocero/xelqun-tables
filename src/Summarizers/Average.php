<?php

namespace Xelqun\Tables\Summarizers;

use Illuminate\Database\Eloquent\Builder;

/**
 * @api
 */
class Average extends Summarizer
{
    public function summarize(Builder $query, string $column): mixed
    {
        return $query->avg($column);
    }

    public function getAggregates(): array
    {
        return ['avg'];
    }
}
