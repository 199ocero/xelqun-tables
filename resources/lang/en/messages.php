<?php

/*
 * Text the server sends. To translate it, run:
 * php artisan vendor:publish --tag=xelqun-tables-lang
 */

return [
    'no' => 'No',
    'yes' => 'Yes',
    'query_builder' => [
        'column' => 'Column',
        'operator' => 'Operator',
        'value' => 'Value',
        'match_any' => 'Match any rule, not all',
        'group' => 'Group of rules',
        'rules' => 'Rules',
    ],

    'operators' => [
        'contains' => 'Contains',
        'not_contains' => "Doesn't contain",
        'starts_with' => 'Starts with',
        'ends_with' => 'Ends with',
        'equals' => 'Is',
        'not_equals' => "Isn't",
        'greater' => 'More than',
        'greater_or_equal' => 'At least',
        'less' => 'Less than',
        'less_or_equal' => 'At most',
        'on' => 'On',
        'before' => 'Before',
        'after' => 'After',
        'is_true' => 'Is on',
        'is_false' => 'Is off',
    ],

];
