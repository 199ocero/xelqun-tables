<?php

namespace Xelqun\Tables\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Xelqun\Tables\Security\TableTokenEncrypter;
use Xelqun\Tables\State\TableState;

class QueryController
{
    public function __invoke(Request $request, TableTokenEncrypter $tokens): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'query' => ['present', 'array'],
            'keys' => ['sometimes', 'boolean'],
        ]);

        $table = $tokens->resolveTable($validated['token']);
        $state = TableState::from($validated['query'], $table);

        if (! ($validated['keys'] ?? false)) {
            return response()->json($table->toResult($state));
        }

        $keys = $table->getSelectableKeys($state);

        abort_if($keys === null, 422, 'Too many rows to select.');

        return response()->json(['keys' => $keys]);
    }
}
