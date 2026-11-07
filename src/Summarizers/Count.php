<?php

namespace Xelqun\Tables\Summarizers;

use Illuminate\Database\Eloquent\Builder;

/**
 * How many rows have a value in this column.
 *
 * @api
 */
class Count extends Summarizer
{
    public function summarize(Builder $query, string $column): mixed
    {
        return $query->whereNotNull($column)->count();
    }
}
