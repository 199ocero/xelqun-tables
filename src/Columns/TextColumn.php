<?php

namespace Xelqun\Tables\Columns;

/**
 * @api
 */
class TextColumn extends Column
{
    public function getType(): string
    {
        return 'text-column';
    }
}
