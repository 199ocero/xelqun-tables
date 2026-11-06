<?php

namespace Xelqun\Tables\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Xelqun\Tables\Columns\TextColumn;
use Xelqun\Tables\Table;

class ProductsTable extends Table
{
    public static bool $allowed = true;

    protected function boot(): void
    {
        $this->paginationPageOptions([2, 5]);
    }

    public function query(): Builder
    {
        return Product::query();
    }

    public function authorize(?Authenticatable $user): bool
    {
        return self::$allowed;
    }

    public function columns(): array
    {
        return [
            TextColumn::make('name'),
            TextColumn::make('price')->label('Cost'),
            TextColumn::make('secret')->hidden(),
            TextColumn::make('shouted')->state(fn (Product $record): string => strtoupper($record->name)),
        ];
    }
}
