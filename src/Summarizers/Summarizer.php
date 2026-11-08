<?php

namespace Xelqun\Tables\Summarizers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A line under a column, worked out over every row of the search and filters, not
 * just this page.
 *
 * @api
 */
abstract class Summarizer
{
    protected ?string $label = null;

    final public function __construct() {}

    public static function make(): static
    {
        return new static;
    }

    public function label(?string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    abstract public function summarize(Builder $query, string $column): mixed;

    /**
     * The SQL aggregates this works from, such as ['sum'], so a column's summaries
     * run as one query. None means summarize() runs on its own.
     *
     * @internal
     *
     * @return array<int, 'sum'|'avg'|'count'|'min'|'max'>
     */
    public function getAggregates(): array
    {
        return [];
    }

    /**
     * @internal
     *
     * @param  array<int, mixed>  $values  The aggregates' results, in the order getAggregates() gave.
     */
    public function fromAggregates(array $values): mixed
    {
        return $values[0] ?? null;
    }
}
