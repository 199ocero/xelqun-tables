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
}
