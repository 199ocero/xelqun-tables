<?php

namespace Xelqun\Tables;

use Illuminate\Support\Str;

/**
 * A way the viewer can group a table's rows, by a column.
 *
 * @api
 */
final class Group
{
    private ?string $label = null;

    private bool $isCollapsible = false;

    private function __construct(private readonly string $column) {}

    public static function make(string $column): self
    {
        return new self($column);
    }

    public function label(?string $label): self
    {
        $this->label = $label;

        return $this;
    }

    /**
     * Let the viewer fold each group away.
     */
    public function collapsible(bool $condition = true): self
    {
        $this->isCollapsible = $condition;

        return $this;
    }

    public function getColumn(): string
    {
        return $this->column;
    }

    /**
     * @return array{name: string, label: string, collapsible?: true}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->column,
            'label' => $this->label ?? Str::headline(str_replace('.', ' ', $this->column)),
            ...($this->isCollapsible ? ['collapsible' => true] : []),
        ];
    }
}
