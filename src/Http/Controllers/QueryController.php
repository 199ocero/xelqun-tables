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
        ]);

        $table = $tokens->resolveTable($validated['token']);

        return response()->json($table->toResult(TableState::from($validated['query'], $table)));
    }
}
