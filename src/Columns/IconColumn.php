<?php

namespace Xelqun\Tables\Columns;

use Illuminate\Database\Eloquent\Model;

/**
 * Icon names come from the forms package's set. Apps add more with the icons
 * option of createXelqunForms().
 *
 * @api
 */
class IconColumn extends Column
{
    protected bool $isBoolean = false;

    public function getType(): string
    {
        return 'icon-column';
    }

    /**
     * Show a check for a true value and a cross for a false one.
     */
    public function boolean(bool $condition = true): static
    {
        $this->isBoolean = $condition;

        return $this;
    }

    protected function getCellProps(Model $record, mixed $state): array
    {
        if (! $this->isBoolean) {
            return ['label' => is_scalar($state) ? (string) $state : null];
        }

        return [
            'icon' => $state ? 'check' : 'x',
            'color' => $state ? 'success' : 'gray',
            'label' => __($state ? 'xelqun-tables::messages.yes' : 'xelqun-tables::messages.no'),
        ];
    }
}
