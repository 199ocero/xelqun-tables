<?php

namespace Xelqun\Tables\Filters;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Xelqun\Forms\Components\Component;
use Xelqun\Forms\Components\Toggle;
use Xelqun\Tables\Exceptions\TableDefinitionException;

/**
 * A switch by default. Give it schema() for fields of its own, and query() to
 * say what they do.
 *
 * @api
 */
class Filter extends BaseFilter
{
    protected ?Closure $queryUsing = null;

    /** @var array<int, Component>|null */
    protected ?array $components = null;

    /**
     * Narrow the query. The closure receives $query and $data.
     */
    public function query(Closure $using): static
    {
        $this->queryUsing = $using;

        return $this;
    }

    /**
     * @param  array<int, Component>  $components
     */
    public function schema(array $components): static
    {
        $this->components = $components;

        return $this;
    }

    public function getFormComponents(): array
    {
        return $this->components === null
            ? [Toggle::make($this->name)->label($this->getLabel())->default($this->default ?? false)]
            : array_map(fn (Component $component): Component => clone $component, $this->components);
    }

    public function apply(Builder $query, array $data): void
    {
        if ($this->queryUsing !== null) {
            $this->evaluate($this->queryUsing, ['query' => $query, 'data' => $data]);

            return;
        }

        if ($this->components !== null) {
            throw new TableDefinitionException("The filter [{$this->name}] has fields, so it needs query().");
        }

        $query->where($query->qualifyColumn($this->name), true);
    }

    /**
     * A switch arrives from the URL as "0", which isn't empty.
     */
    public function isActive(array $data): bool
    {
        return $this->components === null
            ? filter_var($data[$this->name] ?? false, FILTER_VALIDATE_BOOLEAN)
            : parent::isActive($data);
    }
}
