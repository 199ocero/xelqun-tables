<?php

namespace Xelqun\Tables\Columns;

use Xelqun\Tables\Columns\Concerns\IsEditable;
use Xelqun\Tables\Columns\Contracts\Editable;

/**
 * @api
 */
class ToggleColumn extends Column implements Editable
{
    use IsEditable;

    public function getType(): string
    {
        return 'toggle-column';
    }

    /**
     * @return array<int, mixed>
     */
    protected function getValueRules(): array
    {
        return ['required', 'boolean'];
    }
}
