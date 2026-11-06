<?php

namespace Xelqun\Tables\Testing;

use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Xelqun\Tables\Table;

/**
 * Drive a table's endpoints from a Laravel test the way the browser does.
 * In Pest, add uses(InteractsWithTables::class) to the test file.
 *
 * @api
 */
trait InteractsWithTables
{
    /**
     * @param  array<string, mixed>  $query  What the URL would hold, such as ['page' => 2].
     * @return TestResponse<Response>
     */
    public function queryTable(Table $table, array $query = []): TestResponse
    {
        return $this->postJson(route('xelqun.tables.query'), [
            'token' => $table->makeToken(),
            'query' => $query,
        ]);
    }

    /**
     * Open an action's modal.
     *
     * @return TestResponse<Response>
     */
    public function mountTableAction(Table $table, string $name, int|string|null $record = null): TestResponse
    {
        return $this->postTableAction($table, $name, $record, 'mount', null);
    }

    /**
     * Run an action. $record is the row's key, for an action on a row.
     *
     * @param  array<string, mixed>|null  $data  The modal's values.
     * @return TestResponse<Response>
     */
    public function runTableAction(Table $table, string $name, int|string|null $record = null, ?array $data = null): TestResponse
    {
        return $this->postTableAction($table, $name, $record, 'run', $data);
    }

    /**
     * Run an action on selected rows.
     *
     * @param  array<int, int|string>  $records  The rows' keys.
     * @param  array<string, mixed>|null  $data  The modal's values.
     * @return TestResponse<Response>
     */
    public function runBulkTableAction(Table $table, string $name, array $records, ?array $data = null): TestResponse
    {
        return $this->postJson(route('xelqun.tables.action'), [
            'token' => $table->makeToken(),
            'action' => $name,
            'kind' => 'bulk',
            'records' => array_map(strval(...), $records),
            'phase' => 'run',
            'data' => $data,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $data
     * @return TestResponse<Response>
     */
    private function postTableAction(Table $table, string $name, int|string|null $record, string $phase, ?array $data): TestResponse
    {
        return $this->postJson(route('xelqun.tables.action'), [
            'token' => $table->makeToken(),
            'action' => $name,
            'kind' => $record === null ? 'header' : 'record',
            'record' => $record === null ? null : (string) $record,
            'phase' => $phase,
            'data' => $data,
        ]);
    }
}
