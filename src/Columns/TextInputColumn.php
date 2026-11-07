<?php

namespace Xelqun\Tables\Columns;

use Xelqun\Tables\Columns\Concerns\IsEditable;
use Xelqun\Tables\Columns\Contracts\Editable;

/**
 * A text box in the cell. It saves when the viewer leaves it, or presses Enter.
 *
 * @api
 */
class TextInputColumn extends Column implements Editable
{
    use IsEditable;

    public function getType(): string
    {
        return 'text-input-column';
    }

    /**
     * @return array<int, mixed>
     */
    protected function getValueRules(): array
    {
        return ['nullable', 'string'];
    }
}
