<?php

namespace Xelqun\Tables\Filters;

use Closure;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Xelqun\Forms\Components\Select;
use Xelqun\Tables\Table;

/**
 * @api
 */
class SelectFilter extends BaseFilter
{
    protected ?string $attribute = null;

    /** @var array<int|string, string>|Closure */
    protected array|Closure $options = [];

    protected ?string $relationship = null;

    protected ?string $titleAttribute = null;

    protected bool $isMultiple = false;

    protected bool $isSearchable = false;

    /**
     * The column to filter. It defaults to the filter's name.
     */
    public function attribute(string $attribute): static
    {
        $this->attribute = $attribute;

        return $this;
    }

    /**
     * @param  array<int|string, string>|Closure  $options
     */
    public function options(array|Closure $options): static
    {
        $this->options = $options;

        return $this;
    }

    /**
     * Offer the related records, and keep rows that have the chosen ones.
     */
    public function relationship(string $name, string $titleAttribute): static
    {
        $this->relationship = $name;
        $this->titleAttribute = $titleAttribute;

        return $this;
    }

    public function multiple(bool $condition = true): static
    {
        $this->isMultiple = $condition;

        return $this;
    }

    public function searchable(bool $condition = true): static
    {
        $this->isSearchable = $condition;

        return $this;
    }

    public function getFormComponents(): array
    {
        return [
            Select::make($this->name)
                ->label($this->getLabel())
                ->options(fn (): array => $this->getOptions())
                ->multiple($this->isMultiple)
                ->searchable($this->isSearchable)
                ->default($this->default),
        ];
    }

    /**
     * @return array<int|string, string>
     */
    protected function getOptions(): array
    {
        if ($this->relationship === null) {
            $options = $this->evaluate($this->options);

            return $options instanceof Arrayable ? $options->toArray() : $options;
        }

        /** @var Table $table */
        $table = $this->table;
        $related = $table->query()->getModel()->{$this->relationship}()->getRelated();

        // shortcut: every related record is sent, so a very long list needs a Filter with an async Select instead.
        return $related->newQuery()->orderBy($this->titleAttribute)->pluck($this->titleAttribute, $related->getKeyName())->all();
    }

    public function apply(Builder $query, array $data): void
    {
        $values = (array) ($data[$this->name] ?? []);

        if ($this->relationship !== null) {
            $query->whereHas($this->relationship, fn (Builder $related): Builder => $related->whereKey($values));

            return;
        }

        $query->whereIn($query->qualifyColumn($this->attribute ?? $this->name), $values);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function isActive(array $data): bool
    {
        return (array) ($data[$this->name] ?? []) !== [];
    }
}
