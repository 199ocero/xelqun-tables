<?php

namespace Xelqun\Tables\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Xelqun\Tables\Security\TableTokenEncrypter;

/**
 * Saves one editable cell. The row comes through the table's own query, and the
 * column and its permission are checked again here.
 */
class UpdateController
{
    public function __invoke(Request $request, TableTokenEncrypter $tokens): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'record' => ['required', 'string', 'max:255'],
            'column' => ['required', 'string', 'max:255'],
            'value' => ['present'],
        ]);

        $table = $tokens->resolveTable($validated['token']);
        $record = $table->resolveRecord($validated['record']);
        $column = $table->findEditableColumn($validated['column']);

        abort_if($column === null, 404);
        abort_unless($column->isEditable($record), 403);

        $column->updateState($record, $validated['value']);

        return response()->json(['cell' => $column->toCell($record->refresh())]);
    }
}
