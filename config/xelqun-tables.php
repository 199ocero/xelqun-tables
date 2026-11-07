<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | The internal endpoints used by search, sorting, paging and actions.
    |
    */

    'routes' => [
        'prefix' => '_xelqun/tables',
        'middleware' => ['web'],
        'throttle' => [
            'query' => '240,1',
            'update' => '120,1',
            'action' => '60,1',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Table Tokens
    |--------------------------------------------------------------------------
    |
    | Minutes a table token stays valid. Set to 0 to never expire.
    |
    */

    'token_ttl' => 720,

];
