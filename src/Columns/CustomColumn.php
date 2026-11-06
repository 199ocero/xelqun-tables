<?php

namespace Xelqun\Tables\Columns;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Xelqun\Tables\Exceptions\TableDefinitionException;

/**
 * A one-off cell, rendered by a Vue component registered with
 * createXelqunForms(). Reusable columns are better as their own class, which
 * make:xelqun-column creates.
 *
 * @api
 */
class CustomColumn extends Column
{
    protected ?string $component = null;

    /** @var array<string, mixed>|Closure */
    protected array|Closure $cellProps = [];

    public function getType(): string
    {
        return $this->component ?? throw new TableDefinitionException("The column [{$this->name}] needs component().");
    }

    public function component(string $component): static
    {
        $this->component = $component;

        return $this;
    }

    /**
     * Data for the Vue component, available as cell.props. A closure receives
     * $state and $record.
     *
     * @param  array<string, mixed>|Closure  $props
     */
    public function props(array|Closure $props): static
    {
        $this->cellProps = $props;

        return $this;
    }

    protected function getCellProps(Model $record, mixed $state): array
    {
        return ['props' => $this->evaluateForCell($this->cellProps, $record, $state)];
    }
}
