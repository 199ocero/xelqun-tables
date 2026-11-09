<?php

namespace Xelqun\Tables\Columns;

/**
 * Columns under one heading, which spans them above their own headings.
 *
 * @api
 */
final class ColumnGroup
{
    /**
     * @param  array<int, Column>  $columns
     */
    private function __construct(private readonly string $label, private readonly array $columns) {}

    /**
     * @param  array<int, Column>  $columns
     */
    public static function make(string $label, array $columns): self
    {
        return new self($label, $columns);
    }

    /**
     * @return array<int, Column>
     */
    public function getColumns(): array
    {
        return array_map(fn (Column $column): Column => $column->inGroup($this->label), $this->columns);
    }
}
