<?php

namespace Xelqun\Tables\Filters;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Xelqun\Forms\Components\Select;

/**
 * Yes, no, or either, for a boolean column.
 *
 * @api
 */
class TernaryFilter extends BaseFilter
{
    protected ?string $attribute = null;

    protected ?string $trueLabel = null;

    protected ?string $falseLabel = null;

    protected ?string $placeholder = null;

    protected bool $isNullable = false;

    protected ?Closure $trueQuery = null;

    protected ?Closure $falseQuery = null;

    protected ?Closure $blankQuery = null;

    /**
     * The column to filter. It defaults to the filter's name.
     */
    public function attribute(string $attribute): static
    {
        $this->attribute = $attribute;

        return $this;
    }

    public function trueLabel(?string $label): static
    {
        $this->trueLabel = $label;

        return $this;
    }

    public function falseLabel(?string $label): static
    {
        $this->falseLabel = $label;

        return $this;
    }

    /**
     * What the select says while neither is picked.
     */
    public function placeholder(?string $placeholder): static
    {
        $this->placeholder = $placeholder;

        return $this;
    }

    /**
     * Yes means the column has a value and no means it's null, as for a
     * nullable timestamp such as email_verified_at.
     */
    public function nullable(bool $condition = true): static
    {
        $this->isNullable = $condition;

        return $this;
    }

    /**
     * Your own query for yes, no, and neither picked. Each gets $query.
     */
    public function queries(?Closure $true = null, ?Closure $false = null, ?Closure $blank = null): static
    {
        $this->trueQuery = $true;
        $this->falseQuery = $false;
        $this->blankQuery = $blank;

        return $this;
    }

    public function applyWhenInactive(Builder $query): void
    {
        if ($this->blankQuery !== null) {
            $this->evaluate($this->blankQuery, ['query' => $query]);
        }
    }

    public function getFormComponents(): array
    {
        return [
            Select::make($this->name)
                ->label($this->getLabel())
                ->options(['1' => $this->trueLabel ?? __('xelqun-tables::messages.yes'), '0' => $this->falseLabel ?? __('xelqun-tables::messages.no')])
                ->placeholder($this->placeholder)
                // The options are keyed '1' and '0', so a default of true or false has to match them.
                ->default(is_bool($this->default) ? (string) (int) $this->default : $this->default),
        ];
    }

    public function apply(Builder $query, array $data): void
    {
        $value = (bool) $data[$this->name];
        $custom = $value ? $this->trueQuery : $this->falseQuery;

        if ($custom !== null) {
            $this->evaluate($custom, ['query' => $query]);

            return;
        }

        $column = $query->qualifyColumn($this->attribute ?? $this->name);

        if ($this->isNullable) {
            $value ? $query->whereNotNull($column) : $query->whereNull($column);

            return;
        }

        $query->where($column, $value);
    }

    protected function getDefaultIndicator(array $data): string
    {
        return $this->getLabel().': '.((bool) $data[$this->name]
            ? $this->trueLabel ?? __('xelqun-tables::messages.yes')
            : $this->falseLabel ?? __('xelqun-tables::messages.no'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function isActive(array $data): bool
    {
        return in_array($data[$this->name] ?? null, [true, false, 0, 1, '0', '1'], true);
    }
}
