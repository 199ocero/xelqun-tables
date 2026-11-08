<?php

namespace Xelqun\Tables\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Xelqun\Notifications\NotificationQueue;
use Xelqun\Tables\Actions\TableActionHost;
use Xelqun\Tables\Security\TableTokenEncrypter;

/**
 * Open an action's modal, or run the action. The order matters: rebuild the
 * table, find the record through its query, find an action it declares, and
 * only then check that action. The browser's record key is a request, not a fact.
 */
class ActionController
{
    public function __invoke(Request $request, TableTokenEncrypter $tokens, NotificationQueue $notifications): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'action' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::in([TableActionHost::RECORD, TableActionHost::HEADER, TableActionHost::BULK])],
            'phase' => ['required', 'in:mount,run'],
            'record' => ['required_if:kind,'.TableActionHost::RECORD, 'nullable', 'string', 'max:255'],
            'records' => ['required_if:kind,'.TableActionHost::BULK, 'array'],
            'data' => ['nullable', 'array'],
        ]);

        $table = $tokens->resolveTable($validated['token']);
        $isBulk = $validated['kind'] === TableActionHost::BULK;
        $selection = $isBulk ? array_values($validated['records']) : [];

        abort_if(count($selection) > $table->getMaxSelectableRecords(), 422, 'Too many rows selected.');

        $record = $validated['kind'] === TableActionHost::RECORD ? $table->resolveRecord($validated['record']) : null;
        $host = TableActionHost::for($table, $validated['kind'], $record, $selection);
        $action = $host->findAction($validated['action']);

        abort_if($action === null, 404);
        abort_if($isBulk && ! $host->getSelectedQuery()->exists(), 404);
        abort_unless($action->canRun(), 403);

        if ($validated['phase'] === 'mount') {
            return response()->json(['form' => $action->hasModalForm() ? $action->makeForm([])->toSchema() : null]);
        }

        $result = $host->getConnection()->transaction(fn () => $action->run([], $validated['data'] ?? []));

        $sentNotifications = $notifications->pull();

        return response()->json([
            'result' => $action->hasResult() ? $result : null,
            'redirect' => $action->getSuccessRedirectUrl(),
            ...($sentNotifications === [] ? [] : ['notifications' => $sentNotifications]),
        ]);
    }
}
