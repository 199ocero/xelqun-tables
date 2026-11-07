<?php

namespace Xelqun\Tables\Columns;

/**
 * A color as a swatch.
 *
 * @api
 */
class ColorColumn extends Column
{
    public function getType(): string
    {
        return 'color-column';
    }
}
