<?php

namespace Xelqun\Tables\Tests\Fixtures;

use Illuminate\Database\Eloquent\SoftDeletes;

class TrashableProduct extends Product
{
    use SoftDeletes;

    protected $table = 'products';
}
