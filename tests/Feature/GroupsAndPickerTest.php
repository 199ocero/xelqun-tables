<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Xelqun\Forms\Form;
use Xelqun\Tables\Columns\TextColumn;
use Xelqun\Tables\Forms\ModalTableSelect;
use Xelqun\Tables\Group;
use Xelqun\Tables\Table;
use Xelqun\Tables\Tests\Fixtures\Category;
use Xelqun\Tables\Tests\Fixtures\Product;

class GroupedTable extends Table
{
    protected function boot(): void
    {
        $this->defaultGroup('status')->groups(['status', Group::make('is_featured')->label('Featured')->collapsible()]);
    }

    public function query(): Builder
    {
        return Product::query();
    }

    public function authorize(?Authenticatable $user): bool
    {
        return true;
    }

    public function columns(): array
    {
        return [TextColumn::make('name')];
    }
}

class CategoryPickerTable extends Table
{
    public function query(): Builder
    {
        return Category::query();
    }

    public function authorize(?Authenticatable $user): bool
    {
        return true;
    }

    public function columns(): array
    {
        return [TextColumn::make('name')->searchable()];
    }
}

class ProductPickerForm extends Form
{
    public function model(): ?string
    {
        return Product::class;
    }

    public function schema(): array
    {
        return [ModalTableSelect::make('category_id')->relationship('category', 'name')->tableConfiguration(CategoryPickerTable::class)];
    }
}

it('groups by what the viewer picks from the table\'s groups, or by none', function () {
    Product::create(['name' => 'A', 'status' => 'live', 'is_featured' => true]);

    expect(GroupedTable::make()->toSchema()['groups'])->toBe([
        ['name' => 'status', 'label' => 'Status'],
        ['name' => 'is_featured', 'label' => 'Featured', 'collapsible' => true],
    ]);

    $this->queryTable(GroupedTable::make(), ['group' => 'is_featured'])->assertJsonPath('group', 'is_featured')->assertJsonPath('rows.0.group', '1');
    $this->queryTable(GroupedTable::make(), ['group' => 'secret'])->assertJsonPath('group', 'status');
    $this->queryTable(GroupedTable::make(), ['group' => ''])->assertJsonPath('group', null)->assertJsonMissingPath('rows.0.group');
});

it('sends a picker table without rows, and only the picked label', function () {
    $desks = Category::create(['name' => 'Desks']);
    Category::create(['name' => 'Lamps']);

    $form = ProductPickerForm::make(Product::create(['name' => 'Chair', 'category_id' => $desks->id]));
    $props = json_decode(json_encode(collect($form->toSchema()['components'])->firstWhere('path', 'data.category_id')), true)['props'];

    expect($props['options'])->toBe([['value' => $desks->id, 'label' => 'Desks']])
        ->and($props['titleAttribute'])->toBe('name')
        ->and($props['table'])->toMatchArray(['deferred' => true, 'rows' => [], 'id' => 'category-picker-table']);
});

it('accepts only related records that exist', function () {
    $desks = Category::create(['name' => 'Desks']);

    expect(ProductPickerForm::make()->validate(['data' => ['category_id' => $desks->id]])['category_id'])->toBe($desks->id)
        ->and(fn () => ProductPickerForm::make()->validate(['data' => ['category_id' => 999]]))->toThrow(ValidationException::class);
});
