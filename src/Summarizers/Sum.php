<?php

namespace Xelqun\Tables\Summarizers;

use Illuminate\Database\Eloquent\Builder;

/**
 * @api
 */
class Sum extends Summarizer
{
    public function summarize(Builder $query, string $column): mixed
    {
        return $query->sum($column);
    }
}
