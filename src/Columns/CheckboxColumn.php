<?php

namespace Xelqun\Tables\Columns;

use Xelqun\Tables\Columns\Concerns\IsEditable;
use Xelqun\Tables\Columns\Contracts\Editable;

/**
 * @api
 */
class CheckboxColumn extends Column implements Editable
{
    use IsEditable;

    public function getType(): string
    {
        return 'checkbox-column';
    }

    /**
     * @return array<int, mixed>
     */
    protected function getValueRules(): array
    {
        return ['required', 'boolean'];
    }
}
