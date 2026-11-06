<?php

namespace Xelqun\Tables\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Xelqun\Tables\Actions\TableActionHost;
use Xelqun\Tables\Security\TableTokenEncrypter;

/**
 * Open an action's modal, or run the action. The order matters: rebuild the
 * table, find the record through its query, find an action it declares, and
 * only then check that action. The browser's record key is a request, not a fact.
 */
class ActionController
{
    public function __invoke(Request $request, TableTokenEncrypter $tokens): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'action' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::in([TableActionHost::RECORD, TableActionHost::HEADER])],
            'phase' => ['required', 'in:mount,run'],
            'record' => ['required_if:kind,'.TableActionHost::RECORD, 'nullable', 'string', 'max:255'],
            'data' => ['nullable', 'array'],
        ]);

        $table = $tokens->resolveTable($validated['token']);
        $record = $validated['kind'] === TableActionHost::RECORD ? $table->resolveRecord($validated['record']) : null;
        $host = TableActionHost::for($table, $validated['kind'], $record);
        $action = $host->findAction($validated['action']);

        abort_if($action === null, 404);
        abort_unless($action->canRun(), 403);

        if ($validated['phase'] === 'mount') {
            return response()->json(['form' => $action->hasModalForm() ? $action->makeForm([])->toSchema() : null]);
        }

        $result = $host->getConnection()->transaction(fn () => $action->run([], $validated['data'] ?? []));

        return response()->json(['result' => $action->hasResult() ? $result : null]);
    }
}
