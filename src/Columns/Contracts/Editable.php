<?php

namespace Xelqun\Tables\Columns\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * A column whose cells the viewer can change. See the IsEditable trait.
 *
 * @api
 */
interface Editable
{
    public function isEditable(Model $record): bool;

    public function updateState(Model $record, mixed $value): void;
}
