<?php

namespace Xelqun\Tables\Filters;

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

    /**
     * The column to filter. It defaults to the filter's name.
     */
    public function attribute(string $attribute): static
    {
        $this->attribute = $attribute;

        return $this;
    }

    public function getFormComponents(): array
    {
        return [
            Select::make($this->name)
                ->label($this->getLabel())
                ->options(['1' => __('xelqun-tables::messages.yes'), '0' => __('xelqun-tables::messages.no')])
                // The options are keyed '1' and '0', so a default of true or false has to match them.
                ->default(is_bool($this->default) ? (string) (int) $this->default : $this->default),
        ];
    }

    public function apply(Builder $query, array $data): void
    {
        $query->where($query->qualifyColumn($this->attribute ?? $this->name), (bool) $data[$this->name]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function isActive(array $data): bool
    {
        return in_array($data[$this->name] ?? null, [true, false, 0, 1, '0', '1'], true);
    }
}
