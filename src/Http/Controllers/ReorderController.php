<?php

namespace Xelqun\Tables\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Xelqun\Tables\Security\TableTokenEncrypter;

/**
 * Moves a row up or down by swapping its order with its neighbour's. Both rows
 * come through the table's own query.
 */
class ReorderController
{
    public function __invoke(Request $request, TableTokenEncrypter $tokens): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'record' => ['required', 'string', 'max:255'],
            'direction' => ['required', Rule::in(['up', 'down'])],
        ]);

        $table = $tokens->resolveTable($validated['token']);
        $column = $table->getReorderColumn();

        abort_if($column === null, 404);

        $record = $table->resolveRecord($validated['record']);

        abort_unless($table->canReorder(), 403);

        $isUp = $validated['direction'] === 'up';
        $position = $record->getAttribute($column);

        // shortcut: rows with equal positions stay put, so give the column distinct values. Renumber here if an app can't.
        $neighbour = $table->getReorderQuery()
            ->reorder()
            ->where($table->getQuery()->qualifyColumn($column), $isUp ? '<' : '>', $position)
            ->orderBy($table->getQuery()->qualifyColumn($column), $isUp ? 'desc' : 'asc')
            ->first();

        if ($neighbour !== null) {
            $record->getConnection()->transaction(function () use ($column, $neighbour, $position, $record): void {
                $record->setAttribute($column, $neighbour->getAttribute($column))->save();
                $neighbour->setAttribute($column, $position)->save();
            });
        }

        return response()->json(['moved' => $neighbour !== null]);
    }
}
